<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetType;
use App\Models\Company;

/**
 * Automatic asset tags: company code, type prefix, next number. BCC-LAP-001, EMG-SIM-012, or LAP-003 with no company.
 * The number is one more than the highest already used with that start, so gaps from deleted assets aren't reused.
 * HR can still type their own tag; it only has to be unique.
 */
class AssetTags
{
    public static function next(?Company $company, ?AssetType $type, array $taken = []): string
    {
        $base = implode('-', array_filter([$company?->code, $type?->prefix ?? 'AST']));
        $max = 0;
        $tags = Asset::query()->where('asset_tag', 'like', $base.'-%')->pluck('asset_tag')->merge($taken);
        foreach ($tags as $tag) {
            if (preg_match('/^'.preg_quote($base, '/').'-(\d+)$/i', $tag, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return sprintf('%s-%03d', $base, $max + 1);
    }
}
