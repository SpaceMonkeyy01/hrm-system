<?php

namespace Database\Seeders;

use App\Models\ChecklistItem;
use Illuminate\Database\Seeder;

/**
 * The canonical checklist items. Each real-world fact exists once.
 * Sheet columns that recorded the same fact twice are merged by the importer (see App\Services\Import\HrWorkbookImporter).
 *
 * Safe to run repeatedly: it only adds missing items and never renames or removes HR's edits.
 */
class ChecklistItemSeeder extends Seeder
{
    /**
     * key => [label, option_set, description shown to users]
     * The trailing comment is the column it comes from in HR_Onboarding.xlsx.
     */
    public const ITEMS = [
        'onboarding' => [
            'email_created' => ['Email created', 'yes_no', null],                                   // Tracker O
            'desk_allocated' => ['Desk allocated', 'yes_no', null],                                 // Tracker P
            'system_access' => ['System access set up', 'yes_no', 'Teams, HR portal and the main tools'], // Tracker Q
            'contact_info' => ['Contact info collected', 'yes_no', null],                           // Tracker N
            'hr_database' => ['Added to HR database', 'yes_no', null],                              // Tracker Y
            'orientation_1' => ['Orientation session 1', 'session', null],                          // Tracker R
            'orientation_2' => ['Orientation session 2', 'session', null],                          // Tracker S
            'orientation_3' => ['Orientation session 3', 'session', null],                          // Tracker T
            'day30_checkin' => ['30-day check-in', 'yes_no', 'Ticked when the 30-day check-in is dated in the 90-day plan'], // Tracker Z
            'day60_checkin' => ['60-day check-in', 'yes_no', 'Ticked when the 60-day check-in is dated in the 90-day plan'], // Tracker AA
            'day90_evaluation' => ['90-day evaluation', 'yes_no', 'Ticked when the 90-day check-in is dated in the 90-day plan'], // Tracker AB
        ],
        'document' => [
            'cnic_copy' => ['CNIC copy', 'document', null],                          // Checklist J (+ tracker M)
            'photo' => ['Photo', 'document', null],                                  // Checklist K
            'nda_signed' => ['NDA signed', 'document', null],                        // Checklist L (+ tracker W)
            'nca_signed' => ['NCA signed', 'document', null],                        // Checklist M (+ tracker X)
            'bank_details' => ['Bank details', 'document', null],                    // Checklist N
            'educational_documents' => ['Educational documents', 'document', null],  // Checklist O
            'emergency_contact' => ['Emergency contact', 'document', null],          // Checklist P
            'appointment_letter' => ['Appointment letter', 'document', null],        // Checklist Q (+ tracker L)
            'signed_contract' => ['Signed contract', 'document', null],              // Checklist R
            'experience_letters' => ['Experience letter(s)', 'document', null],      // Checklist S
            'policy_acknowledgment' => ['Policy acknowledgment', 'document', null],  // Checklist T (+ tracker V)
            'system_access_form' => ['System access form', 'document', null],        // Checklist U
            'laptop_asset_issued' => ['Laptop/asset issued', 'document', null],      // Checklist V
        ],
        'offboarding' => [
            'resignation_letter' => ['Resignation or termination letter on file', 'clearance', null],
            'handover' => ['Work handover completed', 'clearance', null],
            'assets_returned' => ['Laptop and assets returned', 'clearance', null],   // Offboarding tracker: Asset_Return
            'id_card_returned' => ['ID or access card returned', 'clearance', null],
            'email_disabled' => ['Email and system access disabled', 'clearance', null],
            'attendance_removed' => ['Removed from attendance machine', 'clearance', null],
            'exit_interview' => ['Exit interview done', 'clearance', null],           // Offboarding tracker: Exit_Interview
            'final_settlement' => ['Final settlement processed', 'clearance', null],
            'experience_letter_issued' => ['Experience letter issued', 'clearance', null],
        ],
    ];

    public function run(): void
    {
        foreach (self::ITEMS as $list => $items) {
            $order = 0;
            foreach ($items as $key => [$label, $set, $note]) {
                $order += 10;
                ChecklistItem::firstOrCreate(
                    ['list' => $list, 'key' => $key],
                    ['label' => $label, 'option_set' => $set, 'description' => $note, 'sort_order' => $order, 'is_active' => true],
                );
            }
        }
    }
}
