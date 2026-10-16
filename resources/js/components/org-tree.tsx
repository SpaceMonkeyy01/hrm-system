import { Link } from '@inertiajs/react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Avatar } from '@/components/avatar';
import { plural } from '@/lib/format';
import { useSessionState } from '@/lib/use-session-state';
import { cn } from '@/lib/utils';

export type OrgPerson = { id: number; name: string; code?: string; role?: string | null; leaving?: boolean; photo?: string | null };

/**
 * One box in the chart. Groups (company, department, team) have children and/or people; a person box
 * links to the profile. Boxes with something under them open and close on click.
 */
export type OrgNode = {
    key: string;
    kind: 'root' | 'company' | 'department' | 'team' | 'person';
    title: string;
    subtitle?: string | null;
    count?: number;
    person?: OrgPerson;
    href?: string;
    children?: OrgNode[];
    /** Shown as a stacked list under the box when it is open (teams), so wide teams don't stretch the chart. */
    people?: OrgPerson[];
    /** People whose second manager this is: a dashed stack under the box when it is open. */
    dotted?: OrgPerson[];
    /**
     * "grid": children wrap as a grid of boxes under this one instead of one long row (a company's 20+
     * departments). An open child then takes the full width and shows its own small tree.
     */
    childLayout?: 'grid';
};

const kindStyle: Record<OrgNode['kind'], string> = {
    root: 'border-cascade bg-cascade text-white',
    company: 'border-cascade/40 bg-[#e7f0f5]',
    department: 'border-rule bg-background',
    team: 'border-rule bg-muted/60',
    person: 'border-rule bg-background',
};

const PEOPLE_SHOWN = 8;

function PeopleStack({ people, highlight, dotted }: { people: OrgPerson[]; highlight: (p: OrgPerson) => boolean; dotted?: boolean }) {
    const [all, setAll] = useState(false);
    const shown = all ? people : people.slice(0, PEOPLE_SHOWN);
    return (
        <div className={cn('mt-1.5 grid w-48 gap-1', dotted && 'rounded-md border border-dashed border-cascade/40 p-1')} role="list"
            aria-label={dotted ? 'Dotted-line reports' : undefined}>
            {dotted && <p className="px-1 text-[10px] font-semibold text-muted-foreground">Dotted line ({people.length})</p>}
            {shown.map((p) => (
                <div key={p.id} role="listitem">
                    <Link href={`/employees/${p.id}`} title={[p.code, p.role].filter(Boolean).join(', ') || undefined}
                        className={cn('flex w-48 items-center gap-2 rounded-md border bg-background px-2 py-1.5 text-left text-xs hover:border-cascade/50 hover:bg-accent',
                            highlight(p) && 'border-cascade ring-2 ring-cascade/20')}>
                        <Avatar name={p.name} photo={p.photo} className="size-6 text-[10px]" />
                        <span className="min-w-0 flex-1 truncate font-semibold">{p.name}</span>
                        {p.leaving && <span className="text-[10px] text-pending">leaving</span>}
                    </Link>
                </div>
            ))}
            {people.length > PEOPLE_SHOWN && (
                <button type="button" onClick={() => setAll((a) => !a)} className="w-48 rounded-md px-2 py-1 text-xs font-semibold text-cascade hover:underline">
                    {all ? 'Show fewer' : `${people.length - PEOPLE_SHOWN} more`}
                </button>
            )}
        </div>
    );
}

function Branch({ node, open, toggle, highlight, fill, nested }: {
    node: OrgNode; open: Set<string>; toggle: (key: string) => void; highlight: (p: OrgPerson) => boolean;
    /** Stretch to the grid cell (a subtree drawn inside a "grid" layout). */
    fill?: boolean;
    /** Already inside a grid: a grid of children takes the cell's width instead of a fixed one. */
    nested?: boolean;
}) {
    const hasMore = !!(node.children?.length || node.people?.length || node.dotted?.length);
    const isOpen = open.has(node.key);
    const hit = node.person ? highlight(node.person) : false;
    const inner = (
        <>
            {node.person && <Avatar name={node.person.name} photo={node.person.photo} className="size-7 text-[10px]" />}
            <span className="min-w-0 flex-1 text-left">
                <span className="line-clamp-2 block text-sm leading-tight font-semibold break-words" title={node.title}>{node.title}</span>
                {node.subtitle && <span className={cn('block truncate text-[11px]', node.kind === 'root' ? 'text-white/80' : 'text-muted-foreground')}>{node.subtitle}</span>}
            </span>
            {node.count !== undefined && (
                <span className={cn('shrink-0 rounded-full px-1.5 text-[11px] font-semibold tabular-nums', node.kind === 'root' ? 'bg-white/15' : 'bg-muted text-muted-foreground')}>{node.count}</span>
            )}
            {hasMore && (isOpen ? <ChevronDown className="size-3.5 shrink-0 opacity-60" /> : <ChevronRight className="size-3.5 shrink-0 opacity-60" />)}
        </>
    );
    const box = cn('flex w-48 items-center gap-2 rounded-lg border px-2.5 py-2 shadow-xs transition-colors', kindStyle[node.kind],
        hasMore && 'cursor-pointer hover:border-cascade/60', hit && 'border-cascade ring-2 ring-cascade/20');

    return (
        <li className={fill ? 'w-full' : undefined}>
            {hasMore ? (
                <button type="button" className={box} aria-expanded={isOpen} onClick={() => toggle(node.key)}>{inner}</button>
            ) : node.href ? (
                <Link href={node.href} className={box}>{inner}</Link>
            ) : <div className={box}>{inner}</div>}
            {isOpen && node.people && node.people.length > 0 && <PeopleStack people={node.people} highlight={highlight} />}
            {isOpen && node.dotted && node.dotted.length > 0 && <PeopleStack people={node.dotted} highlight={highlight} dotted />}
            {isOpen && node.children && node.children.length > 0 && (node.childLayout === 'grid' ? (
                <div className={cn('flex flex-col items-center', nested || fill ? 'w-full' : '')}>
                    <span className="h-5 w-px bg-border" aria-hidden />
                    <div className={cn('grid items-start gap-3 rounded-xl border border-dashed bg-background/60 p-3', nested || fill ? 'w-full' : 'w-[64rem]')}
                        style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(12.75rem, 1fr))' }}>
                        {node.children.map((c) => (
                            <div key={c.key} className={cn('flex justify-center', open.has(c.key) && 'col-span-full rounded-lg bg-muted/40 py-3')}>
                                <div className="org-tree w-full">
                                    <ul className="w-full"><Branch node={c} open={open} toggle={toggle} highlight={highlight} fill nested /></ul>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            ) : (
                <ul>{node.children.map((c) => <Branch key={c.key} node={c} open={open} toggle={toggle} highlight={highlight} />)}</ul>
            ))}
        </li>
    );
}

function allKeys(n: OrgNode): string[] {
    return [n.key, ...(n.children ?? []).flatMap(allKeys)];
}

/** Keys of every box on the way to a person whose name contains the search text, so the match is visible. */
function pathsTo(n: OrgNode, match: (p: OrgPerson) => boolean): string[] {
    const own = (n.person && match(n.person)) || (n.people ?? []).some(match) || (n.dotted ?? []).some(match);
    const below = (n.children ?? []).flatMap((c) => pathsTo(c, match));
    return own || below.length ? [n.key, ...below] : [];
}

/** "Open all" / "Close all" from the page's bar; `n` changes on every press so pressing twice works. */
export type TreeCommand = { kind: 'open' | 'close'; n: number } | null;

/**
 * Top-down flow chart. `startOpen` = boxes open at first (e.g. the root and companies). Zoom and the
 * open/close-all buttons live in the page's sticky bar (pages/org-chart.tsx) and arrive as props.
 */
export function OrgTree({ root, startOpen, needle, zoom, command, storageKey }: {
    root: OrgNode; startOpen: string[]; needle: string; zoom: number; command: TreeCommand;
    /** Remembers which boxes are open for this tab of the browser, so coming back shows the same chart. */
    storageKey: string;
}) {
    const [openList, setOpenList] = useSessionState<string[]>(`org-tree:${storageKey}`, startOpen);
    const open = new Set(openList);
    const setOpen = (next: Set<string> | ((prev: Set<string>) => Set<string>)) =>
        setOpenList((prev) => [...(typeof next === 'function' ? next(new Set(prev)) : next)]);
    // Start with the top box in view: a wide chart would otherwise open scrolled to its left edge.
    const scroller = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const el = scroller.current;
        if (el) el.scrollLeft = (el.scrollWidth - el.clientWidth) / 2;
    }, []);
    useEffect(() => {
        if (command) setOpen(new Set(command.kind === 'open' ? allKeys(root) : startOpen));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [command]);
    const highlight = (p: OrgPerson) => !!needle && p.name.toLowerCase().includes(needle);
    const searching = needle ? new Set(pathsTo(root, highlight)) : null;
    const effective = searching && searching.size ? new Set([...open, ...searching]) : open;
    const toggle = (key: string) => setOpen((s) => {
        const next = new Set(effective);
        if (next.has(key)) next.delete(key);
        else next.add(key);
        return next;
    });

    return (
        <div className="grid gap-2">
            {/* Scrolls sideways when wide; up and down with the page, under the sticky bar. */}
            <div ref={scroller} className="overflow-x-auto rounded-lg border bg-[#fbfcfd] p-6 print:overflow-visible print:border-0 print:bg-white print:p-0">
                <div className="org-tree mx-auto w-max" style={{ zoom }}>
                    <ul><Branch node={root} open={effective} toggle={toggle} highlight={highlight} /></ul>
                </div>
            </div>
            <p className="text-xs text-muted-foreground print:hidden">Click a box to open or close what is under it. {plural(root.count ?? 0, 'person', 'people')} in this chart.</p>
        </div>
    );
}
