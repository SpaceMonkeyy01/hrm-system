<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The company filter (?company=) on HR pages, remembered for the session: the company someone picks stays picked as
 * they move between pages, and before they pick one, pages open on their login's home company (users.company_id;
 * none = both). "All" is sent as ?company=all. Not a restriction: anyone can pick All or the other company.
 * Exports and CSVs aren't listed, so a download always uses exactly what the page sent.
 */
class RememberCompany
{
    /** Pages with the All / BCC / Emerge choice (route names). */
    public const PAGES = [
        'dashboard', 'overview', 'org-chart', 'reports.show', 'requests.index', 'employees.index', 'onboarding.index', 'documents.index',
        'attendance.index', 'attendance.requests', 'wfh-requests.index', 'leave.index', 'leave-requests.index', 'loans.index', 'medical.index',
        'assets.index', 'logins.index',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->canViewHr() && $request->isMethod('GET') && in_array($request->route()?->getName(), self::PAGES, true)) {
            $key = 'company_choice.'.$user->id;   // per login, even if a session were ever shared
            if ($request->query->has('company')) {
                $value = (string) $request->query('company');
                $choice = ctype_digit($value) && (int) $value > 0 ? (int) $value : 'all';
                $request->session()->put($key, $choice);
            } else {
                $choice = $request->session()->get($key, $user->company_id ?: 'all');
            }
            if ($choice === 'all') {
                $request->query->remove('company');
            } else {
                $request->query->set('company', (string) $choice);
            }
        }

        return $next($request);
    }
}
