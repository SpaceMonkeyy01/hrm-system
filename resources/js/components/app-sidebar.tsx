import { Link, usePage } from '@inertiajs/react';
import {
    CalendarClock,
    CircleUserRound,
    ChartColumnBig,
    ChevronsUpDown,
    ClipboardCheck,
    Clock,
    DoorOpen,
    FileChartColumn,
    FileCheck2,
    House,
    Laptop,
    ListChecks,
    LogOut,
    Network,
    Palmtree,
    ScrollText,
    Search,
    Star,
    UserCog,
    UserRound,
    Users,
    Workflow,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Avatar } from '@/components/avatar';
import { useEmployeeSearch } from '@/components/employee-search';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { APP_CREDIT } from '@/lib/app-info';
import type { SharedProps } from '@/types';

/**
 * edit: only for HR and admin logins (the page itself returns 403 to others).
 * badge: key of a count in the shared "nav" prop (HandleInertiaRequests), shown when above 0.
 */
type Item = { title: string; href: string; icon: LucideIcon; edit?: boolean; badge?: 'requests' | 'reviews' };
type Group = { label?: string; items: Item[] };

const groups: Group[] = [
    {
        items: [
            { title: 'Home', href: '/dashboard', icon: House },
            { title: 'Overview', href: '/overview', icon: ChartColumnBig },
            { title: 'Reports', href: '/reports', icon: FileChartColumn },
        ],
    },
    {
        label: 'People',
        items: [
            { title: 'Employees', href: '/employees', icon: Users },
            { title: 'Org chart', href: '/org-chart', icon: Workflow },
            { title: 'Onboarding', href: '/onboarding', icon: ClipboardCheck },
            { title: 'Documents', href: '/documents', icon: FileCheck2 },
            { title: 'Reviews', href: '/reviews', icon: Star, edit: true, badge: 'reviews' },
            { title: 'Offboarding', href: '/offboarding', icon: DoorOpen },
        ],
    },
    {
        label: 'Time',
        items: [
            { title: 'Attendance', href: '/attendance', icon: Clock, badge: 'requests' },
            { title: 'Work schedules', href: '/attendance/schedules', icon: CalendarClock },
            { title: 'Leave & holidays', href: '/leave', icon: Palmtree },
        ],
    },
    {
        label: 'Company',
        items: [
            { title: 'Assets', href: '/assets', icon: Laptop },
            { title: 'Controlled documents', href: '/controlled-documents', icon: ScrollText },
        ],
    },
];

const settings: Item[] = [
    { title: 'Users', href: '/settings/users', icon: UserCog },
    { title: 'Departments & teams', href: '/settings/structure', icon: Network },
    { title: 'Checklists', href: '/settings/checklists', icon: ListChecks },
];

const mine: Item[] = [{ title: 'My page', href: '/me', icon: CircleUserRound }];

const roleLabel = { admin: 'Admin', hr: 'HR', viewer: 'Viewer', employee: 'Employee' } as const;

/** Blue Cascade branding. "mark" (sidebar) is the square "bl" icon plus the app name; "full" (login) is the full logo. */
export function AppLogo({ variant = 'mark' }: { variant?: 'mark' | 'full' }) {
    if (variant === 'full') {
        return (
            <span className="grid justify-items-center gap-3">
                <img src="/images/logo.png" alt="Blue Cascade" width={800} height={506} className="h-auto w-44" />
                <span className="text-sm font-bold text-muted-foreground">HRM System</span>
            </span>
        );
    }

    return (
        <span className="flex items-center gap-2.5">
            <img src="/images/logo-mark.png" alt="" width={512} height={512} className="size-8 shrink-0 rounded-md" />
            <span className="grid leading-tight">
                <span className="text-sm font-bold">HRM System</span>
                <span className="text-xs text-muted-foreground">BCC and Emerge</span>
            </span>
        </span>
    );
}

/** The most specific link wins across all groups: /attendance/schedules lights up Work schedules, not Attendance as well. */
function useActive(all: Item[]): string | undefined {
    const { url } = usePage();
    const path = new URL(url, 'http://x').pathname;
    const matches = (href: string) => path === href || path.startsWith(href + '/');
    return all.filter((i) => matches(i.href)).sort((a, b) => b.href.length - a.href.length)[0]?.href;
}

function NavGroup({ label, items, active }: { label?: string; items: Item[]; active?: string }) {
    const { nav } = usePage<SharedProps>().props;
    if (!items.length) return null;
    return (
        <SidebarGroup className="py-1">
            {label && <SidebarGroupLabel>{label}</SidebarGroupLabel>}
            <SidebarMenu>
                {items.map((item) => {
                    const count = item.badge ? nav?.[item.badge] ?? 0 : 0;
                    return (
                        <SidebarMenuItem key={item.href}>
                            <SidebarMenuButton asChild isActive={item.href === active} tooltip={{ children: count ? `${item.title} (${count} waiting)` : item.title }}>
                                <Link href={item.href} prefetch>
                                    <item.icon />
                                    <span>{item.title}</span>
                                </Link>
                            </SidebarMenuButton>
                            {count > 0 && (
                                <SidebarMenuBadge className="rounded-full bg-pending-soft px-1.5 text-pending" aria-label={`${count} waiting`}>{count}</SidebarMenuBadge>
                            )}
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}

export function AppSidebar() {
    const { auth } = usePage<SharedProps>().props;
    const user = auth.user!;
    const search = useEmployeeSearch();
    const visible = auth.can.view ? groups.map((g) => ({ ...g, items: g.items.filter((i) => !i.edit || auth.can.edit) })) : [];
    const showMine = auth.can.self || !auth.can.view;
    const active = useActive([...visible.flatMap((g) => g.items), ...(showMine ? mine : []), ...(auth.can.admin ? settings : [])]);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={auth.can.view ? '/dashboard' : '/me'} aria-label="HRM System home">
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    {auth.can.view && (
                        <SidebarMenuItem>
                            <SidebarMenuButton onClick={search.open} tooltip={{ children: 'Find an employee (Ctrl K)' }}
                                className="border border-sidebar-border bg-background text-muted-foreground shadow-xs hover:bg-background hover:text-foreground">
                                <Search />
                                <span className="flex-1">Find an employee</span>
                                <kbd className="rounded border bg-muted px-1.5 text-[10px] font-semibold text-muted-foreground group-data-[collapsible=icon]:hidden">Ctrl K</kbd>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    )}
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-0">
                {visible.map((g, i) => <NavGroup key={g.label ?? i} label={g.label} items={g.items} active={active} />)}
                {showMine && <NavGroup label="Me" items={mine} active={active} />}
                {auth.can.admin && <NavGroup label="Settings" items={settings} active={active} />}
            </SidebarContent>

            <SidebarFooter>
                <p className="px-2 text-[11px] text-muted-foreground group-data-[collapsible=icon]:hidden">{APP_CREDIT}</p>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <SidebarMenuButton size="lg" className="data-[state=open]:bg-sidebar-accent">
                                    <Avatar name={user.name} className="size-8" />
                                    <span className="grid flex-1 text-left leading-tight">
                                        <span className="truncate text-sm font-semibold">{user.name}</span>
                                        <span className="truncate text-xs text-muted-foreground">{roleLabel[user.role]}</span>
                                    </span>
                                    <ChevronsUpDown className="ml-auto size-4" />
                                </SidebarMenuButton>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent className="w-56" side="top" align="end">
                                <DropdownMenuLabel className="font-normal">
                                    <div className="text-sm font-semibold">{user.name}</div>
                                    <div className="text-xs text-muted-foreground">{user.email}</div>
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem asChild>
                                    <Link href="/account" className="w-full">
                                        <UserRound /> Your account
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem asChild>
                                    <Link href="/logout" method="post" as="button" className="w-full">
                                        <LogOut /> Log out
                                    </Link>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarFooter>
        </Sidebar>
    );
}
