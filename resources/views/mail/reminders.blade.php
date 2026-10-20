<x-mail::message>
# HR reminders for {{ $date }}

@if ($count)
{{ $count === 1 ? '1 thing needs' : $count.' things need' }} attention today. Overdue items are listed first in each group.
@endif

@if (count($stalled))
## Attendance machine not syncing

@foreach ($stalled as $d)
- {{ $d['name'] }}{{ $d['code'] ? ' ('.$d['code'].')' : '' }}: {{ $d['since'] ? 'no automatic sync since '.$d['since'] : 'never synced automatically' }}.
@endforeach

Until it syncs again, days show No data or Absent. Check the cron job and that the machine is reachable. [Open Devices]({{ $devicesLink }})

@endif
@if (count($waiting))
## Requests waiting for HR

@foreach ($waiting as $q)
- [{{ $q['title'] }}]({{ $q['link'] }}): {{ $q['pending'] }} waiting{{ $q['since'] ? ', oldest sent '.$q['since'] : '' }}.
@endforeach

[Open Requests]({{ $inboxLink }})

@endif
@foreach ($groups as $group)
## {{ $group['heading'] }} ({{ count($group['rows']) }})

@foreach ($group['rows'] as $r)
- {{ $r['severity'] === 'overdue' ? 'Overdue' : 'Due soon' }}: [{{ $r['employee_name'] }} ({{ $r['employee_code'] }})]({{ url($r['link']) }}). {{ $r['message'] }}.
@endforeach

@endforeach
@if (count($ending))
## Warranties ending ({{ count($ending) }})

@foreach ($ending as $w)
- {{ $w['tag'] }} {{ $w['name'] }}: warranty ends {{ $w['date'] }}.
@endforeach

[See them on the Assets page]({{ $assetsLink }})

@endif
@if ($backlog)
## Older missing documents

{{ $backlog === 1 ? '1 person' : $backlog.' people' }} who started more than {{ config('hrm.reminders.recent_start_days') }} days ago still {{ $backlog === 1 ? 'has' : 'have' }} missing documents. They are not listed one by one. [See the documents list]({{ $backlogLink }}).

@endif
<x-mail::button :url="$dashboard">
Open the dashboard
</x-mail::button>

You get this email because your login has the HR or admin role. It is sent every morning when something needs attention.
</x-mail::message>
