<?php

namespace App\Services\Import;

/**
 * Matches names typed in the onboarding sheets to the employee master (BCC + Emerge lists).
 *
 * Order of checks, strictest first:
 *   alias      - listed in aliases.csv by HR (always wins). Looked up on the name as typed, bracket hint
 *                included ("Ali Raza (night shift)"), then on the bare name ("Ali Raza"). An alias whose
 *                code is not on the lists gives "bad_alias" and links nothing.
 *   exact      - identical after trimming and lower-casing
 *   normalized - identical after removing punctuation and unifying "Muhammad / M. / Muhmmad"
 *   spelling   - identical after collapsing doubled letters (Qadir = Qaddir, Mehbob = Mehboob)
 *   spelling   - also ignores spaces: "Umme Kulsoom" = "Umm-e-Kulsoom"
 *   fuzzy      - same number of words, each within a 1-2 letter typo (Siama ~ Saima); flagged for HR review
 *   subset     - all words of the shorter name appear in the longer one (Zubair Hassan ~ Zubair Hassan Malik);
 *                the shorter name needs 2+ distinctive words (Muhammad / Syed don't count); flagged for review
 * If more than one employee fits at the first level that matches, the result is "ambiguous" and
 * nothing is linked (e.g. two people with the same name on one list).
 */
class NameMatcher
{
    /** @var array<int, array{name: string, exact: string, norm: string, squash: string, compact: string, tokens: string[]}> */
    private array $people = [];

    /** @var array<string, string> aliasKey(source name) => employee_code */
    private array $aliases = [];

    /** @var array<string, int> lower-cased employee_code => id */
    private array $codes = [];

    private const MUHAMMAD = ['muhammad', 'muhmmad', 'muhmmahd', 'muahmmad', 'mohammad', 'mohammed', 'muhammed', 'mohd', 'md', 'm'];

    /** Words that don't tell two people apart (after normalize + squash). */
    private const COMMON = ['muhamad', 'syed', 'sayed', 'hafiz', 'hafiza', 'mian', 'sheikh', 'shaikh', 'shekh', 'mr', 'ms', 'mrs', 'dr'];

    public function add(int $id, string $name, string $code): void
    {
        $norm = self::normalize($name);
        $this->people[$id] = [
            'name' => $name,
            'exact' => mb_strtolower(trim(preg_replace('/\s+/', ' ', $name))),
            'norm' => $norm,
            'squash' => self::squash($norm),
            'compact' => str_replace(' ', '', self::squash($norm)),
            'tokens' => $norm === '' ? [] : explode(' ', self::squash($norm)),
        ];
        $this->codes[mb_strtolower(trim($code))] = $id;
    }

    public function setAliases(array $aliases): void
    {
        foreach ($aliases as $source => $code) {
            $this->aliases[self::aliasKey((string) $source)] = trim($code);
        }
    }

    /** "Ali  Raza ( Night shift )" -> "ali raza (night shift)"; "Ali Raza" -> "ali raza" */
    public static function aliasKey(string $name): string
    {
        [$bare, $hint] = self::splitHint(preg_replace('/\s+/u', ' ', $name));
        $key = mb_strtolower($bare);

        return $hint !== null && $hint !== '' ? "$key (".mb_strtolower($hint).')' : $key;
    }

    /**
     * A bracket hint ("Ali Raza (night shift)") is used for the alias lookup only; the other levels
     * match the bare name.
     *
     * @return array{type: string, ids: int[], score: float, code?: string}
     *         type: alias|bad_alias|exact|normalized|spelling|fuzzy|subset|ambiguous|none
     *         code: the alias's employee_code (bad_alias only)
     */
    public function match(string $name): array
    {
        [$bare] = self::splitHint($name);
        foreach (array_unique([self::aliasKey($name), self::aliasKey($bare)]) as $aliasKey) {
            if (isset($this->aliases[$aliasKey])) {
                $code = $this->aliases[$aliasKey];
                $id = $this->codes[mb_strtolower($code)] ?? null;

                return $id ? ['type' => 'alias', 'ids' => [$id], 'score' => 1.0]
                    : ['type' => 'bad_alias', 'ids' => [], 'score' => 0.0, 'code' => $code];
            }
        }

        $key = mb_strtolower(trim(preg_replace('/\s+/', ' ', $bare)));
        $name = $bare;

        $norm = self::normalize($name);
        $squash = self::squash($norm);
        $compact = str_replace(' ', '', $squash);

        foreach (['exact' => ['exact', $key], 'normalized' => ['norm', $norm], 'spelling' => ['compact', $compact]] as $type => [$field, $value]) {
            $ids = array_keys(array_filter($this->people, fn ($p) => $p[$field] === $value));
            if (count($ids) === 1) {
                return ['type' => $type, 'ids' => $ids, 'score' => 1.0];
            }
            if (count($ids) > 1) {
                return ['type' => 'ambiguous', 'ids' => $ids, 'score' => 1.0];
            }
        }

        // Fuzzy: same number of words, every word within a typo or two, whole name >= 85% similar.
        $tokens = $squash === '' ? [] : explode(' ', $squash);
        $scored = [];
        foreach ($this->people as $id => $p) {
            if (count($p['tokens']) !== count($tokens) || $tokens === []) {
                continue;
            }
            $wordsOk = true;
            foreach ($tokens as $i => $t) {
                if (self::similarity($t, $p['tokens'][$i]) < 0.7) {
                    $wordsOk = false;
                    break;
                }
            }
            $score = self::similarity($squash, $p['squash']);
            if ($wordsOk && $score >= 0.85) {
                $scored[$id] = $score;
            }
        }
        arsort($scored);
        if ($scored) {
            $best = array_key_first($scored);
            $values = array_values($scored);
            if (count($values) > 1 && $values[0] - $values[1] < 0.04) {
                return ['type' => 'ambiguous', 'ids' => array_keys($scored), 'score' => $values[0]];
            }

            return ['type' => 'fuzzy', 'ids' => [$best], 'score' => round($scored[$best], 3)];
        }

        // Subset: "Zubair Hassan" inside "Zubair Hassan Malik". The shorter name needs two distinctive words,
        // so "Muhammad Shahzeb" can't grab "Muhammad Shahzeb Ali".
        $ids = array_keys(array_filter($this->people, function ($p) use ($tokens) {
            $short = count($tokens) <= count($p['tokens']) ? $tokens : $p['tokens'];
            $long = count($tokens) <= count($p['tokens']) ? $p['tokens'] : $tokens;

            return count(array_diff($short, self::COMMON)) >= 2 && array_diff($short, $long) === [];
        }));
        if (count($ids) === 1) {
            return ['type' => 'subset', 'ids' => $ids, 'score' => 0.9];
        }
        if (count($ids) > 1) {
            return ['type' => 'ambiguous', 'ids' => $ids, 'score' => 0.9];
        }

        return ['type' => 'none', 'ids' => [], 'score' => 0.0];
    }

    /** "Muhmmad Faisal ( developer )" -> ["Muhmmad Faisal", "developer"] */
    public static function splitHint(string $name): array
    {
        if (preg_match('/^(.*?)\s*\((.*?)\)\s*$/u', $name, $m) && trim($m[1]) !== '') {
            return [trim($m[1]), trim($m[2])];
        }

        return [trim($name), null];
    }

    public function nameOf(int $id): string
    {
        return $this->people[$id]['name'] ?? '';
    }

    /** "M. Danish" -> "muhammad danish", "Abdul Ur- Rehman" -> "abdul ur rehman" */
    public static function normalize(string $name): string
    {
        $s = mb_strtolower($name);
        $s = preg_replace('/[.\-_\'’`,]+/u', ' ', $s);
        $s = preg_replace('/[^\p{L}\s]+/u', '', $s);
        $tokens = preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array_map(fn ($t) => in_array($t, self::MUHAMMAD, true) ? 'muhammad' : $t, $tokens);

        return implode(' ', $tokens);
    }

    /** Collapse doubled letters: "qaddir" -> "qadir", "marryam" -> "maryam". */
    public static function squash(string $norm): string
    {
        return preg_replace('/(\p{L})\1+/u', '$1', $norm);
    }

    /** 1 - (Damerau-Levenshtein distance / longer length). Handles swapped letters (Siama / Saima). */
    public static function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        $la = mb_strlen($a);
        $lb = mb_strlen($b);
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        $d = [];
        for ($i = 0; $i <= $la; $i++) {
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $lb; $j++) {
            $d[0][$j] = $j;
        }
        for ($i = 1; $i <= $la; $i++) {
            for ($j = 1; $j <= $lb; $j++) {
                $cost = $x[$i - 1] === $y[$j - 1] ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $x[$i - 1] === $y[$j - 2] && $x[$i - 2] === $y[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }

        return 1 - $d[$la][$lb] / max($la, $lb);
    }
}
