<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * One-off (2026-10-23): BCC's HR login belongs to BCC and Emerge's to Emerge, both as their home company (pages open
 * on it, the 08:00 email covers only its people) and as the address that company's staff get emails from. Only fills
 * what is still empty, so a choice already made in Settings stays. Does nothing where the logins or companies don't
 * exist (local copies, tests). Through the models, so the changes are in the activity log.
 */
return new class extends Migration
{
    private const LOGINS = ['BCC' => 'hr@bluecascade.org', 'EMG' => 'hr.emerge@bluecascade.org'];

    public function up(): void
    {
        // A sender has to be on the domain set up for sending (StructureController::updateCompanyEmail).
        $domain = strtolower((string) strrchr((string) config('mail.from.address'), '@'));
        foreach (self::LOGINS as $code => $email) {
            $company = Company::where('code', $code)->first();
            if (! $company) {
                continue;
            }
            $user = User::where('email', $email)->whereNull('company_id')->first();
            if ($user && $user->role !== 'employee') {
                $user->update(['company_id' => $company->id]);
            }
            if (! $company->hr_email && $domain && str_ends_with($email, $domain)) {
                $company->update(['hr_email' => $email]);
            }
        }
    }

    public function down(): void
    {
        // Settings, not schema: change them in Settings > Users and Settings > Departments and teams.
    }
};
