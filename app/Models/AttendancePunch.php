<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A raw punch as the device recorded it. Written only by the attendance importers (bulk insert, not logged
 * one by one: the import itself is logged). Linked to a person through employees.attendance_machine_id
 * (source machine) or employees.hr_portal_id (source hrmy), never by name.
 */
class AttendancePunch extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['source', 'device_user_id', 'device_name', 'punched_at', 'work_date', 'method', 'attendance_import_id'];

    protected function casts(): array
    {
        return ['punched_at' => 'datetime', 'work_date' => 'date:Y-m-d'];
    }
}
