<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Org chart > Check-up: reporting lines that need fixing. Worked out over current staff (active + leaving).
 *
 *   tops          no manager but people report to them (the top of a line: usually right, listed to confirm)
 *   no_manager    no manager and nobody reports to them
 *   manager_gone  their manager (or second manager) has left, is still under review, or was deleted
 *   cross_company their manager works for the other company
 *   loops         reporting goes round in a circle (A > B > A)
 */
class ManagerCheckup
{
    /**
     * @param  Collection<int, Employee>  $people  current staff in view (one company or all), with company_id,
     *     manager_id, secondary_manager_id
     * @return array<string, list<array{id: int, name: string, code: string, detail: ?string}>>
     */
    public static function run(Collection $people): array
    {
        $byId = $people->keyBy('id');
        $managerIds = $people->pluck('manager_id')->merge($people->pluck('secondary_manager_id'))->filter()->unique();
        // Managers can sit outside the view (the other company, or no longer current): look them all up.
        $managers = Employee::withTrashed()->whereIn('id', $managerIds)->get(['id', 'full_name', 'company_id', 'status', 'deleted_at'])->keyBy('id');
        $hasReports = $people->pluck('manager_id')->filter()->flip();
        $item = fn (Employee $e, ?string $detail = null) => ['id' => $e->id, 'name' => $e->full_name, 'code' => $e->employee_code, 'detail' => $detail];
        $current = fn (?Employee $m) => $m && ! $m->deleted_at && in_array($m->status, ['active', 'offboarding'], true);

        $out = ['tops' => [], 'no_manager' => [], 'manager_gone' => [], 'cross_company' => [], 'loops' => []];
        foreach ($people as $e) {
            if (! $e->manager_id) {
                $out[isset($hasReports[$e->id]) ? 'tops' : 'no_manager'][] = $item($e);
            }
            foreach (['manager_id' => 'Manager', 'secondary_manager_id' => 'Second manager'] as $field => $label) {
                $m = $e->{$field} ? $managers->get($e->{$field}) : null;
                if ($e->{$field} && ! $current($m)) {
                    $why = ! $m || $m->deleted_at ? 'was deleted' : ($m->status === 'review' ? 'is still under review' : 'has left');
                    $out['manager_gone'][] = $item($e, "$label ".($m?->full_name ?? 'unknown')." $why");
                } elseif ($field === 'manager_id' && $m && $m->company_id !== $e->company_id) {
                    $out['cross_company'][] = $item($e, "Manager {$m->full_name} is in the other company");
                }
            }
        }

        // Loops: follow each person's managers upwards; coming back to someone already on the path is a circle.
        $reported = [];
        foreach ($people as $e) {
            $path = [];
            for ($x = $e; $x && ! isset($path[$x->id]); $x = $x->manager_id ? $byId->get($x->manager_id) : null) {
                $path[$x->id] = true;
            }
            if ($x && ! isset($reported[$x->id])) {
                $circle = [];
                for ($y = $x; ! isset($circle[$y->id]); $y = $byId->get($y->manager_id)) {
                    $circle[$y->id] = $y->full_name;
                }
                foreach (array_keys($circle) as $id) {
                    $reported[$id] = true;
                }
                $out['loops'][] = $item($x, implode(' > ', $circle).' > '.$x->full_name);
            }
        }

        return $out;
    }
}
