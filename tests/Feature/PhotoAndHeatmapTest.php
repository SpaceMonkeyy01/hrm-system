<?php

namespace Tests\Feature;

use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Profile photos and the 12-month attendance heatmap. Fake people and a generated picture only. */
class PhotoAndHeatmapTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        [$this->company] = $this->baseData();
    }

    private function picture(int $w = 640, int $h = 480): UploadedFile
    {
        return UploadedFile::fake()->image('face.png', $w, $h);
    }

    public function test_hr_sets_a_photo_which_is_cropped_square_and_shown_to_the_right_people(): void
    {
        $e = $this->employee($this->company, ['full_name' => 'Fake Face']);
        $other = $this->employee($this->company);
        $this->actingAs($this->user('hr'))->post("/employees/{$e->id}/photo", ['photo' => $this->picture()])->assertSessionHasNoErrors();

        $e->refresh();
        $this->assertNotNull($e->photo_path);
        Storage::disk('local')->assertExists($e->photo_path);
        [$w, $h] = getimagesizefromstring(Storage::disk('local')->get($e->photo_path));
        $this->assertSame([320, 320], [$w, $h]);

        $this->actingAs($this->user('viewer'))->get("/employees/{$e->id}/photo")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($this->user('employee', ['employee_id' => $e->id]))->get("/employees/{$e->id}/photo")->assertOk();
        $this->actingAs($this->user('employee', ['employee_id' => $other->id]))->get("/employees/{$e->id}/photo")->assertForbidden();
        $this->actingAs($this->user('viewer'))->post("/employees/{$e->id}/photo", ['photo' => $this->picture()])->assertForbidden();

        $this->actingAs($this->user('viewer'))->get('/employees')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('employees.data', fn ($rows) => collect($rows)->firstWhere('id', $e->id)['photo'] === $e->photoUrl()));

        $old = $e->photo_path;
        $this->actingAs($this->user('hr'))->delete("/employees/{$e->id}/photo");
        $this->assertNull($e->fresh()->photo_path);
        Storage::disk('local')->assertMissing($old);
    }

    public function test_people_set_their_own_photo_and_bad_files_are_refused(): void
    {
        $e = $this->employee($this->company);
        $me = $this->user('employee', ['employee_id' => $e->id]);
        $this->actingAs($me)->post('/me/photo', ['photo' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf')])->assertSessionHasErrors('photo');
        $this->actingAs($me)->post('/me/photo', ['photo' => $this->picture(300, 900)])->assertSessionHasNoErrors();
        $this->assertNotNull($e->fresh()->photo_path);

        config(['hrm.uploads.enabled' => false]);
        $this->actingAs($me)->post('/me/photo', ['photo' => $this->picture()])->assertSessionHasErrors('photo');
    }

    public function test_heatmap_has_a_code_per_day_since_the_start_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-05-20 12:00'));
        WorkSchedule::create(['name' => '9 to 5', 'type' => 'fixed', 'start_time' => '09:00', 'end_time' => '17:00', 'late_grace_minutes' => 10,
            'full_day_minutes' => 480, 'half_day_minutes' => 240, 'working_days' => [1, 2, 3, 4, 5], 'is_default' => true]);
        $e = $this->employee($this->company, ['start_date' => '2026-05-11', 'attendance_machine_id' => '1']);
        foreach (['2026-05-18 09:40:00', '2026-05-18 17:00:00', '2026-05-19 09:00:00', '2026-05-19 17:00:00'] as $at) {
            AttendancePunch::create(['source' => 'machine', 'device_user_id' => '1', 'punched_at' => $at, 'work_date' => substr($at, 0, 10)]);
        }

        $this->actingAs($this->user('hr'))->get("/employees/{$e->id}")->assertInertia(fn (AssertableInertia $p) => $p
            ->where('attendanceYear.from', '2026-05-11')
            ->where('attendanceYear.to', '2026-05-20')
            ->where('attendanceYear.days.2026-05-18', 'L')
            ->where('attendanceYear.late.2026-05-18', 40)
            ->where('attendanceYear.days.2026-05-19', 'P')
            ->where('attendanceYear.days.2026-05-16', 'O')
            ->where('attendanceYear.days.2026-05-12', 'N'));   // no file covers the 12th
    }
}
