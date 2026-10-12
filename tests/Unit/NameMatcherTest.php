<?php

namespace Tests\Unit;

use App\Services\Import\NameMatcher;
use PHPUnit\Framework\TestCase;

class NameMatcherTest extends TestCase
{
    private function matcher(): NameMatcher
    {
        $m = new NameMatcher;
        $people = [1 => 'Nida Qaddir', 2 => 'Bilal Ahmed', 3 => 'Bilal Ahmed', 4 => 'Saima Javed', 5 => 'M. Tariq Shah',
            6 => 'Zubair Hassan Malik', 7 => 'Muhammad Danish', 8 => 'Umm-e-Kulsoom', 9 => 'Syed Fahad Ali Gilani', 10 => 'Muhammad Shahzeb Ali'];
        foreach ($people as $id => $name) {
            $m->add($id, $name, "C-$id");
        }

        return $m;
    }

    public function test_normalize(): void
    {
        $this->assertSame('muhammad danish', NameMatcher::normalize('M. Danish'));
        $this->assertSame('muhammad danish', NameMatcher::normalize('Muhmmad  Danish'));
        $this->assertSame('abdul ur rehman', NameMatcher::normalize('Abdul Ur- Rehman'));
        $this->assertSame('qadir', NameMatcher::squash('qaddir'));
    }

    public function test_match_levels(): void
    {
        $m = $this->matcher();
        $this->assertSame(['exact', [1]], array_slice(array_values($m->match('nida qaddir')), 0, 2));
        $this->assertSame(['normalized', [7]], array_slice(array_values($m->match('Muhmmad Danish')), 0, 2));
        $this->assertSame(['spelling', [1]], array_slice(array_values($m->match('Nida Qadir')), 0, 2));
        $this->assertSame(['spelling', [8]], array_slice(array_values($m->match('Umme Kulsoom')), 0, 2));
        $this->assertSame(['fuzzy', [4]], array_slice(array_values($m->match('Siama Javed')), 0, 2));
        $this->assertSame(['subset', [6]], array_slice(array_values($m->match('Zubair Hassan')), 0, 2));
    }

    public function test_ambiguous_names_are_never_guessed(): void
    {
        $r = $this->matcher()->match('Bilal Ahmed');
        $this->assertSame('ambiguous', $r['type']);
        $this->assertSame([2, 3], $r['ids']);
    }

    public function test_no_false_positives(): void
    {
        $m = $this->matcher();
        $this->assertSame('none', $m->match('Muhammad Talha Shah')['type'], 'Talha is not Tariq');
        $this->assertSame('none', $m->match('Rida Khalid')['type']);
        $this->assertSame('none', $m->match('Syed Fahad')['type'], 'one distinctive word is not enough for a subset match');
        $this->assertSame('none', $m->match('Muhammad Shahzeb')['type'], '"Muhammad" does not count as a distinctive word');
    }

    public function test_alias_wins(): void
    {
        $m = $this->matcher();
        $m->setAliases(['Bilal Ahmed' => 'C-3']);
        $this->assertSame(['alias', [3]], array_slice(array_values($m->match('bilal ahmed')), 0, 2));
    }

    public function test_split_hint(): void
    {
        $this->assertSame(['Muhmmad Faisal', 'developer'], NameMatcher::splitHint('Muhmmad Faisal ( developer )'));
        $this->assertSame(['Sana Tariq', null], NameMatcher::splitHint('Sana Tariq'));
    }
}
