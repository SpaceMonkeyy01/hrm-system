<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistEntry extends Model
{
    use LogsActivity;

    protected $fillable = ['employee_id', 'checklist_item_id', 'status', 'note', 'updated_by'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class, 'checklist_item_id');
    }

    public function activityEmployeeId(): ?int
    {
        return $this->employee_id;
    }

    public function activityLabel(): string
    {
        $item = $this->relationLoaded('item') ? $this->item : ChecklistItem::find($this->checklist_item_id);

        return $item?->label ?? 'Checklist';
    }
}
