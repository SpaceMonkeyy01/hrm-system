<?php

namespace App\Services;

use App\Models\Review;
use App\Models\ReviewCycle;

/**
 * Performance review rules, in one place (like HrRules for onboarding).
 *
 *   cycle     draft (HR sets it up, adds people) -> open (people can fill it in) -> closed (read only)
 *   review    self_review -> manager_review -> completed; cancelled when HR takes someone out
 *             People without a My page login skip the self-review and start at manager_review.
 *             HR can also skip a self-review that isn't coming.
 *   overall   the average of HR's ratings, one decimal; the band is the scale label it rounds to
 */
class ReviewRules
{
    public const CYCLE_STATUSES = ['draft' => 'Being set up', 'open' => 'Open', 'closed' => 'Closed'];

    public const STATUSES = ['self_review' => 'Waiting for self-review', 'manager_review' => 'Waiting for HR review', 'completed' => 'Done', 'cancelled' => 'Taken out'];

    /** @param array<string, int|string|null> $ratings */
    public static function average(array $ratings): ?float
    {
        $values = array_values(array_filter(array_map('intval', $ratings), fn ($v) => $v >= 1 && $v <= 5));

        return $values ? round(array_sum($values) / count($values), 1) : null;
    }

    /** 3.6 => "Exceeds expectations" (the scale label it rounds to). */
    public static function band(?float $overall): ?string
    {
        if ($overall === null) {
            return null;
        }
        $scale = config('hrm.reviews.scale');

        return $scale[(int) round($overall)] ?? null;
    }

    /** Status a new review starts in: the self-review only when the person can log in to My page. */
    public static function initialStatus(bool $hasLogin): string
    {
        return $hasLogin ? 'self_review' : 'manager_review';
    }

    /** A person can fill in their self-review while the cycle is open and it's their turn. */
    public static function canSelfReview(Review $review, ReviewCycle $cycle): bool
    {
        return $cycle->status === 'open' && $review->status === 'self_review';
    }

    /** HR can write the manager review once the cycle is open, before it is done. Saving a draft is allowed during the self-review too. */
    public static function canManagerReview(Review $review, ReviewCycle $cycle): bool
    {
        return $cycle->status === 'open' && in_array($review->status, ['self_review', 'manager_review'], true);
    }

    /** Every criterion must have a rating from 1 to 5. Returns the labels still missing. */
    public static function missingRatings(array $criteria, array $ratings): array
    {
        return array_values(array_map(fn ($c) => $c['label'], array_filter($criteria, fn ($c) => ! in_array((int) ($ratings[$c['key']] ?? 0), [1, 2, 3, 4, 5], true))));
    }
}
