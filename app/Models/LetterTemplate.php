<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;

/** A letter with {{fields}} filled from the employee record (LetterRenderer::FIELDS). */
class LetterTemplate extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'subject', 'body', 'details_label', 'checklist_key', 'is_active', 'sort_order'];

    protected $attributes = ['is_active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function activityLabel(): string
    {
        return "Letter template {$this->name}";
    }
}
