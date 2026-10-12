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
    UserCog,
    UserRound,
    Users,
    Workflow,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
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
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { APP_CREDIT } from '@/lib/app-info';
import type { SharedProps } from '@/types';

type Item = { title: string; href: string; icon: LucideIcon };

const work: Item[] = [
    { title: 'Home', href: '/dashboard', icon: House },
    { title: 'Overview', href: '/overview', icon: ChartColumnBig },
    { title: 'Reports', href: '/reports', icon: FileChartColumn },
    { title: 'Employees', href: '/employees', icon: Users },
    { title: 'Onboarding', href: '/onboarding', icon: ClipboardCheck },
    { title: 'Documents', href: '/documents', icon: FileCheck2 },
    { title: 'Offboarding', href: '/offboarding', icon: DoorOpen },
    { title: 'Attendance', href: '/attendance', icon: Clock },
    { title: 'Work schedules', href: '/attendance/schedules', icon: CalendarClock },
    { title: 'Leave & holidays', href: '/leave', icon: Palmtree },
    { title: 'Assets', href: '/assets', icon: Laptop },
    { title: 'Org chart', href: '/org-chart', icon: Workflow },
    { title: 'Controlled documents', href: '/controlled-documents', icon: ScrollText },
];

const settings: Item[] = [
    { title: 'Users', href: '/settings/users', icon: UserCog },
    { title: 'Departments & teams', href: '/settings/structure', icon: Network },
    { title: 'Checklists', href: '/settings/checklists', icon: ListChecks },
];

const roleLabel = { admin: 'Admin', hr: 'HR', viewer: 'Viewer', employee: 'Employee' } as const;

const mine: Item[] = [{ title: 'My page', href: '/me', icon: CircleUserRound }];

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

function NavGroup({ label, items }: { label: string; items: Item[] }) {
    const { url } = usePage();
    const path = new URL(url, 'http://x').pathname;
    const matches = (href: string) => path === href || path.startsWith(href + '/');
    // The most specific item wins: /attendance/schedules lights up Work schedules, not Attendance as well.
    const active = items.filter((i) => matches(i.href)).sort((a, b) => b.href.length - a.href.length)[0]?.href;

    return (
        <SidebarGroup>
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.href}>
                        <SidebarMenuButton
                            asChild
                            isActive={item.href === active}
                            tooltip={{ children: item.title }}
                        >
                            <Link href={item.href} prefetch>
                                <item.icon />
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}

export function AppSidebar() {
    const { auth } = usePage<SharedProps>().props;
    const user = auth.user!;

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
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                {auth.can.view && <NavGroup label="HR records" items={work} />}
                {(auth.can.self || !auth.can.view) && <NavGroup label="Me" items={mine} />}
                {auth.can.admin && <NavGroup label="Settings" items={settings} />}
            </SidebarContent>

            <SidebarFooter>
                <p className="px-2 text-[11px] text-muted-foreground group-data-[collapsible=icon]:hidden">{APP_CREDIT}</p>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <SidebarMenuButton size="lg" className="data-[state=open]:bg-sidebar-accent">
                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-white text-sm font-bold text-cascade">
                                        {user.name.slice(0, 1).toUpperCase()}
                                    </span>
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
