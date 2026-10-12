<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One uploaded attendance file (machine punch log or HR.my export). */
class AttendanceImport extends Model
{
    use LogsActivity;

    protected $fillable = ['source', 'original_name', 'path', 'punches_found', 'punches_added', 'date_from', 'date_to', 'uploaded_by'];

    protected function casts(): array
    {
        return ['date_from' => 'date:Y-m-d', 'date_to' => 'date:Y-m-d'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function activityLabel(): string
    {
        return "Attendance file {$this->original_name}";
    }
}
