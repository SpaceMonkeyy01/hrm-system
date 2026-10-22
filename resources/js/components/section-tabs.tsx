import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

/** edit: only for HR and admin logins, like the sidebar (the page itself returns 403 to others). */
type Tab = { title: string; href: string; edit?: boolean };

const attendance: Tab[] = [
    { title: 'Day', href: '/attendance' },
    { title: 'Schedules', href: '/attendance/schedules' },
    { title: 'Punch log', href: '/attendance/punches', edit: true },
    { title: 'App clock-ins', href: '/attendance/clock-ins', edit: true },
    { title: 'Devices', href: '/attendance/devices', edit: true },
    { title: 'Links', href: '/attendance/links', edit: true },
];

const employeeCare: Tab[] = [
    { title: 'Loans', href: '/loans', edit: true },
    { title: 'Medical', href: '/medical', edit: true },
];

const settings: Tab[] = [
    { title: 'Users', href: '/settings/users' },
    { title: 'Departments & teams', href: '/settings/structure' },
    { title: 'Checklists', href: '/settings/checklists' },
];

/** Links between the pages of one section, drawn on the page header's bottom border. The most specific match is active. */
function SectionTabs({ tabs, label }: { tabs: Tab[]; label: string }) {
    const { url, props } = usePage<SharedProps>();
    const path = new URL(url, 'http://x').pathname;
    const shown = tabs.filter((t) => !t.edit || props.auth.can.edit);
    const active = shown.filter((t) => path === t.href || path.startsWith(t.href + '/')).sort((a, b) => b.href.length - a.href.length)[0]?.href;

    return (
        <nav aria-label={label} className="-mb-4 flex gap-1 overflow-x-auto overflow-y-hidden pt-1 [scrollbar-width:none]">
            {shown.map((t) => (
                <Link key={t.href} href={t.href} prefetch aria-current={t.href === active ? 'page' : undefined}
                    className={cn(
                        '-mb-px inline-flex items-center gap-2 border-b-2 border-transparent px-3 py-2 text-sm whitespace-nowrap text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-2',
                        t.href === active && 'border-primary font-semibold text-foreground',
                    )}>
                    {t.title}
                </Link>
            ))}
        </nav>
    );
}

export const AttendanceTabs = () => <SectionTabs tabs={attendance} label="Attendance pages" />;
export const EmployeeCareTabs = () => <SectionTabs tabs={employeeCare} label="Employee care pages" />;
export const SettingsTabs =() => <SectionTabs tabs={settings} label="Settings pages" />;
