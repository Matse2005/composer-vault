<?php

namespace App\Vault\Health;

use App\Models\AccessReview;
use App\Models\User;
use App\Vault\Settings\VaultSettings;
use Illuminate\Support\Carbon;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Warns when the last access review is older than the configured interval. Without a review yet, the interval counts
 * from the creation of the first admin, so a new install is not nagged on day one. The health notifications carry the
 * reminder.
 */
class AccessReviewCheck extends Check
{
    public function run(): Result
    {
        $interval = app(VaultSettings::class)->accessReviewIntervalDays();
        $last = AccessReview::query()->max('completed_at');
        $reference = $last !== null ? Carbon::parse($last) : User::query()->min('created_at');

        if ($reference === null) {
            return Result::make()->ok()->shortSummary('No admins yet');
        }

        $age = (int) Carbon::parse($reference)->diffInDays(now(), true);

        if ($age <= $interval) {
            return Result::make()->ok()->shortSummary($last === null ? 'First review not due yet' : "Reviewed {$age} day(s) ago");
        }

        return Result::make()
            ->warning($last === null ? "No access review was ever completed, and the vault is {$age} days old." : "The last access review was {$age} days ago. The interval is {$interval} days.")
            ->shortSummary('Review is due')
            ->meta(['fix' => 'Open the Access review page, check the lists and complete the review.']);
    }
}
