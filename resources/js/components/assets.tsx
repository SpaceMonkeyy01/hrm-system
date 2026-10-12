import { formatDate } from '@/lib/format';

/** app/Models/AssetAssignment.php rowsFor() */
export type AssetRow = {
    id: number; asset_id: number; tag: string; name: string; type: string; serial_number: string | null;
    assigned_on: string; returned_on: string | null; condition_out: string | null; condition_in: string | null;
};

/** One person's assets: tag, what it is, since when (and when it came back). */
export function AssetList({ rows, empty = 'No company assets on record.' }: { rows: AssetRow[]; empty?: string }) {
    if (!rows.length) return <p className="rounded-lg border px-3 py-3 text-sm text-muted-foreground">{empty}</p>;
    return (
        <ul className="divide-y rounded-lg border">
            {rows.map((r) => (
                <li key={r.id} className="grid gap-0.5 px-3 py-2 text-sm sm:grid-cols-[7rem_1fr_auto] sm:items-baseline sm:gap-3">
                    <span className="font-semibold tabular-nums">{r.tag}</span>
                    <span>
                        {r.name} <span className="text-muted-foreground">{r.type}{r.serial_number ? `, serial ${r.serial_number}` : ''}</span>
                        {(r.condition_out || r.condition_in) && (
                            <span className="block text-xs text-muted-foreground">{[r.condition_out && `Given: ${r.condition_out}`, r.condition_in && `Returned: ${r.condition_in}`].filter(Boolean).join('. ')}</span>
                        )}
                    </span>
                    <span className="text-xs text-muted-foreground tabular-nums">
                        {r.returned_on ? `${formatDate(r.assigned_on)} to ${formatDate(r.returned_on)}` : `Since ${formatDate(r.assigned_on)}`}
                    </span>
                </li>
            ))}
        </ul>
    );
}
