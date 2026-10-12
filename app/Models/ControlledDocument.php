<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Model;

class ControlledDocument extends Model
{
    use LogsActivity;

    protected $fillable = ['doc_number', 'doc_type', 'name', 'description', 'status', 'issued_on', 'obsolete_on', 'link_url'];

    protected $attributes = ['status' => 'Active'];

    protected function casts(): array
    {
        return ['issued_on' => 'date:Y-m-d', 'obsolete_on' => 'date:Y-m-d'];
    }

    /** BCC-0001, BCC-0002, ... (format from the Controlled_Documents sheet). */
    public static function nextNumber(): string
    {
        $prefix = config('hrm.document_number_prefix');
        $max = static::query()->where('doc_number', 'like', $prefix.'%')->pluck('doc_number')
            ->map(fn ($n) => (int) substr($n, strlen($prefix)))->max() ?? 0;

        return $prefix.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    public function activityLabel(): string
    {
        return "Document {$this->doc_number} {$this->name}";
    }
}
