import { router, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { createContext, type ReactNode, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { Avatar } from '@/components/avatar';
import { StatusPill } from '@/components/status-pill';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

/** app/Http/Controllers/SearchController.php */
type Hit = { id: number; name: string; code: string; department: string | null; status: string; photo: string | null };

const Ctx = createContext<{ open: () => void }>({ open: () => {} });

export const useEmployeeSearch = () => useContext(Ctx);

const statusTone = { active: 'done', offboarding: 'pending', exited: 'neutral' } as const;
const statusLabel: Record<string, string> = { active: 'Active', offboarding: 'Leaving', exited: 'Left' };

/** "Find an employee" from anywhere: Ctrl K (Cmd K on a Mac), the sidebar button, or the phone header. */
export function EmployeeSearchProvider({ children }: { children: ReactNode }) {
    const { auth } = usePage<SharedProps>().props;
    const [isOpen, setOpen] = useState(false);
    const open = useCallback(() => setOpen(true), []);

    useEffect(() => {
        if (!auth.can.view) return;
        const onKey = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setOpen(true);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [auth.can.view]);

    return (
        <Ctx.Provider value={{ open }}>
            {children}
            {isOpen && <SearchDialog onClose={() => setOpen(false)} />}
        </Ctx.Provider>
    );
}

function SearchDialog({ onClose }: { onClose: () => void }) {
    const [q, setQ] = useState('');
    const [hits, setHits] = useState<Hit[]>([]);
    const [index, setIndex] = useState(0);
    const [loading, setLoading] = useState(false);
    const seq = useRef(0);

    useEffect(() => {
        const term = q.trim();
        if (!term) {
            setHits([]);
            return;
        }
        const mine = ++seq.current;
        setLoading(true);
        const t = setTimeout(() => {
            fetch(`/search/employees?q=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((r) => (r.ok ? r.json() : []))
                .then((data: Hit[]) => {
                    if (mine === seq.current) {
                        setHits(data);
                        setIndex(0);
                    }
                })
                .finally(() => mine === seq.current && setLoading(false));
        }, 150);
        return () => clearTimeout(t);
    }, [q]);

    const go = (h: Hit | undefined) => {
        if (!h) return;
        onClose();
        router.visit(`/employees/${h.id}`);
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="top-[15%] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-lg" aria-describedby="search-hint">
                <DialogTitle className="sr-only">Find an employee</DialogTitle>
                <div className="flex items-center gap-2 border-b px-3">
                    <Search className="size-4 shrink-0 text-muted-foreground" />
                    <input autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder="Name, code, system ID or CNIC"
                        aria-label="Find an employee" role="combobox" aria-expanded={hits.length > 0} aria-controls="search-results"
                        aria-activedescendant={hits[index] ? `hit-${hits[index].id}` : undefined}
                        className="h-12 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                        onKeyDown={(e) => {
                            if (e.key === 'ArrowDown') { e.preventDefault(); setIndex((i) => Math.min(i + 1, hits.length - 1)); }
                            if (e.key === 'ArrowUp') { e.preventDefault(); setIndex((i) => Math.max(i - 1, 0)); }
                            if (e.key === 'Enter') { e.preventDefault(); go(hits[index]); }
                        }} />
                </div>
                <ul id="search-results" role="listbox" className="max-h-80 overflow-y-auto p-1.5">
                    {hits.map((h, i) => (
                        <li key={h.id} id={`hit-${h.id}`} role="option" aria-selected={i === index}>
                            <button type="button" onMouseEnter={() => setIndex(i)} onClick={() => go(h)}
                                className={cn('flex w-full items-center gap-3 rounded-md px-2.5 py-2 text-left text-sm', i === index && 'bg-accent')}>
                                <Avatar name={h.name} photo={h.photo} />
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate font-semibold">{h.name}</span>
                                    <span className="block truncate text-xs text-muted-foreground">{[h.code, h.department].filter(Boolean).join(', ')}</span>
                                </span>
                                {h.status !== 'active' && <StatusPill value={statusLabel[h.status] ?? h.status} tone={statusTone[h.status as keyof typeof statusTone] ?? 'neutral'} />}
                            </button>
                        </li>
                    ))}
                </ul>
                <DialogDescription id="search-hint" className="border-t px-3 py-2 text-xs text-muted-foreground">
                    {q.trim() && !loading && hits.length === 0 ? 'Nobody matches that.' : 'Up and down to pick, Enter to open, Esc to close.'}
                </DialogDescription>
            </DialogContent>
        </Dialog>
    );
}
