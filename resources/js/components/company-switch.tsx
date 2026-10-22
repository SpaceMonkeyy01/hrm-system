import { cn } from '@/lib/utils';

/**
 * { company: 4 }, or { company: 'all' } for All: the choice is remembered for the session (RememberCompany), and pages
 * open on the login's home company until one is picked, so All has to be said out loud.
 */
export const companyParam = (company: number | null | undefined): { company: number | 'all' } => ({ company: company || 'all' });

/**
 * All / BCC / Emerge: narrows the page to one company's people (?company=, read by Company::picked on the server).
 * Hidden when there is only one company.
 */
export function CompanySwitch({ companies, value, onChange }: {
    companies: { id: number; name: string }[]; value: number | null; onChange: (id: number | null) => void;
}) {
    if (companies.length < 2) {
        return null;
    }
    return (
        <div className="inline-flex rounded-md border bg-background p-0.5" role="group" aria-label="Company">
            {[{ id: null as number | null, name: 'All' }, ...companies].map((c) => (
                <button key={c.id ?? 'all'} type="button" aria-pressed={value === c.id} onClick={() => onChange(c.id)}
                    className={cn('rounded px-3 py-1.5 text-sm font-semibold', value === c.id ? 'bg-primary text-primary-foreground' : 'hover:bg-accent')}>
                    {c.name}
                </button>
            ))}
        </div>
    );
}
