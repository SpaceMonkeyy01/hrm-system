import { Head } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { cn } from '@/lib/utils';

type Heading = { id: string; text: string; level: 2 | 3 };

const slug = (text: string) => text.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');

/**
 * System architecture (admin): docs/ARCHITECTURE.md as HTML from Settings\ArchitectureController. The contents list is
 * built from its headings, and ```mermaid blocks are drawn with Mermaid, loaded only on this page.
 */
export default function Architecture({ html }: { html: string | null }) {
    const ref = useRef<HTMLDivElement>(null);
    const [headings, setHeadings] = useState<Heading[]>([]);
    const [active, setActive] = useState<string>();
    const [diagramError, setDiagramError] = useState(false);

    useEffect(() => {
        const root = ref.current;
        if (!root) return;

        // Ids for the contents list and for links to a section
        const found: Heading[] = [];
        root.querySelectorAll<HTMLHeadingElement>('h2, h3').forEach((h) => {
            h.id ||= slug(h.textContent ?? '');
            found.push({ id: h.id, text: h.textContent ?? '', level: h.tagName === 'H2' ? 2 : 3 });
        });
        setHeadings(found);
        // Tables and code can be wider than the column: they scroll on their own.
        root.querySelectorAll('table').forEach((t) => {
            if (t.parentElement?.classList.contains('doc-scroll')) return;
            const box = document.createElement('div');
            box.className = 'doc-scroll';
            t.replaceWith(box);
            box.append(t);
        });

        const blocks = Array.from(root.querySelectorAll<HTMLElement>('pre > code.language-mermaid'));
        let cancelled = false;
        if (blocks.length) {
            const nodes = blocks.map((code) => {
                const box = document.createElement('div');
                box.className = 'doc-diagram mermaid';
                box.textContent = code.textContent ?? '';
                code.parentElement!.replaceWith(box);
                return box;
            });
            const dark = document.documentElement.classList.contains('dark');
            import('mermaid').then(({ default: mermaid }) => {
                if (cancelled) return;
                const natural = { useMaxWidth: false };
                mermaid.initialize({ startOnLoad: false, securityLevel: 'strict', theme: dark ? 'dark' : 'neutral', fontFamily: 'inherit',
                    er: natural, flowchart: natural, state: natural, sequence: natural });
                return mermaid.run({ nodes }).then(() => {
                    if (cancelled) return;
                    // Fit the column, but never below 60% of full size: shrunk further, the big ERDs can't be read,
                    // so those scroll sideways in their box instead.
                    nodes.forEach((n) => {
                        const svg = n.querySelector('svg');
                        const full = svg?.viewBox.baseVal.width;
                        if (!svg || !full) return;
                        svg.style.maxWidth = 'none';
                        svg.style.width = `${Math.max(Math.min(full, n.clientWidth - 32), full * 0.6)}px`;
                    });
                    // The diagrams changed the page height: go back to the linked section.
                    if (location.hash) document.getElementById(location.hash.slice(1))?.scrollIntoView();
                });
            }).catch(() => setDiagramError(true));
        }

        const observer = new IntersectionObserver((entries) => {
            const hit = entries.find((e) => e.isIntersecting);
            if (hit) setActive(hit.target.id);
        }, { rootMargin: '0px 0px -75% 0px' });
        root.querySelectorAll('h2, h3').forEach((h) => observer.observe(h));
        if (location.hash) document.getElementById(location.hash.slice(1))?.scrollIntoView();

        return () => {
            cancelled = true;
            observer.disconnect();
        };
    }, [html]);

    return (
        <>
            <Head title="System architecture" />
            <PageHeader title="System architecture"
                description="How the app is built and connected, for developers. This is docs/ARCHITECTURE.md from the code, so it always matches what is deployed." />
            {html === null ? (
                <div className="px-4 py-6 md:px-6"><EmptyState title="docs/ARCHITECTURE.md is missing from this deploy." /></div>
            ) : (
                <div className="grid gap-8 px-4 py-6 md:px-6 xl:grid-cols-[minmax(0,1fr)_15rem]">
                    <div className="min-w-0">
                        {diagramError && <p className="mb-4 rounded-lg border bg-pending-soft px-4 py-3 text-sm text-pending">The diagrams couldn't be drawn. The text is all here; refresh to try the diagrams again.</p>}
                        <div ref={ref} className="doc" dangerouslySetInnerHTML={{ __html: html }} />
                    </div>
                    <nav aria-label="Contents" className="hidden xl:block">
                        <div className="sticky top-4 max-h-[calc(100vh-2rem)] overflow-y-auto">
                            <p className="mb-2 text-xs font-bold tracking-wide text-muted-foreground uppercase">Contents</p>
                            <ol className="grid gap-0.5 text-sm">
                                {headings.map((h) => (
                                    <li key={h.id}>
                                        <a href={`#${h.id}`} className={cn('block rounded px-2 py-1 hover:bg-accent', h.level === 3 && 'pl-5 text-[13px]',
                                            active === h.id ? 'bg-accent font-semibold text-cascade' : 'text-muted-foreground')}>{h.text}</a>
                                    </li>
                                ))}
                            </ol>
                        </div>
                    </nav>
                </div>
            )}
        </>
    );
}
