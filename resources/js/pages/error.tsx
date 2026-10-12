import { Head, Link, usePage } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import type { SharedProps } from '@/types';

/** 403 / 404 for logged-in users (bootstrap/app.php). Says what happened and links back to their home page. */
export default function ErrorPage({ status }: { status: 403 | 404 }) {
    const { auth } = usePage<SharedProps>().props;
    const home = auth.can.view ? { href: '/dashboard', label: 'Go to Home' } : { href: '/me', label: 'Go to My page' };
    const text = status === 403
        ? { title: "You don't have access to this page", body: auth.can.view ? 'Your login can see this part of the system only with a different role. Ask an admin if you need it.' : 'Your login shows your own record on My page. HR records are for HR staff.' }
        : { title: "This page doesn't exist", body: 'The link may be old, or the record was removed.' };
    return (
        <>
            <Head title={text.title} />
            <PageHeader title={text.title} description={text.body} />
            <div className="p-4 md:p-6">
                <Button asChild><Link href={home.href}>{home.label}</Link></Button>
            </div>
        </>
    );
}
