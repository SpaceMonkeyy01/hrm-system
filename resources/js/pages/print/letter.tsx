import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, Printer, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import type { SharedProps } from '@/types';

type Props = {
    letter: { id: number; reference: string; subject: string | null; body: string; issued_on: string; issued_by: string | null; company: string | null;
        employee: { id: number; name: string } };
};

/** An issued letter on an A4 sheet. The toolbar doesn't print; "Save as PDF" in the print window gives a PDF. */
export default function PrintLetter({ letter }: Props) {
    const { auth } = usePage<SharedProps>().props;
    return (
        <>
            <Head title={`${letter.subject ?? 'Letter'} ${letter.reference}`} />
            <div className="min-h-svh bg-[#eef1f5] py-6 print:bg-white print:py-0">
                <div className="mx-auto mb-4 flex max-w-[210mm] flex-wrap items-center justify-between gap-2 px-4 print:hidden">
                    <Link href={`/employees/${letter.employee.id}`} className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                        <ChevronLeft className="size-4" /> {letter.employee.name}
                    </Link>
                    <div className="flex gap-2">
                        {auth.can.admin && (
                            <Button variant="ghost" onClick={() => confirm(`Withdraw ${letter.reference}? It is removed from the record.`) && router.delete(`/letters/${letter.id}`)}>
                                <Trash2 /> Withdraw
                            </Button>
                        )}
                        <Button onClick={() => window.print()}><Printer /> Print or save as PDF</Button>
                    </div>
                </div>

                <article className="letter-sheet mx-auto flex min-h-[297mm] w-[210mm] max-w-full flex-col bg-white px-[22mm] py-[20mm] text-[11pt] leading-relaxed text-[#1d2b3a] shadow-md print:min-h-0 print:w-auto print:shadow-none">
                    <header className="mb-10 flex items-start justify-between gap-6 border-b border-[#00435c] pb-5">
                        <img src="/images/logo.png" alt="Blue Cascade" className="h-14 w-auto" />
                        <div className="text-right text-[9.5pt] text-[#5f6e82]">
                            <p className="font-semibold text-[#1d2b3a]">{letter.company ?? 'Human Resources'}</p>
                            <p>Human Resources</p>
                        </div>
                    </header>

                    <div className="mb-8 flex justify-between text-[10pt]">
                        <p>Ref: <span className="font-semibold">{letter.reference}</span></p>
                        <p>{formatDate(letter.issued_on)}</p>
                    </div>

                    {letter.subject && <h1 className="mb-6 text-[13pt] font-bold underline underline-offset-4">{letter.subject}</h1>}

                    <div className="flex-1 whitespace-pre-line">{letter.body}</div>

                    <footer className="mt-16">
                        <p className="mb-10">Yours sincerely,</p>
                        <p className="w-56 border-t border-[#1d2b3a] pt-1 font-semibold">{letter.issued_by ?? 'Human Resources'}</p>
                        <p className="text-[10pt] text-[#5f6e82]">{letter.issued_by ? ['Human Resources', letter.company].filter(Boolean).join(', ') : letter.company}</p>
                    </footer>
                </article>
            </div>
        </>
    );
}
