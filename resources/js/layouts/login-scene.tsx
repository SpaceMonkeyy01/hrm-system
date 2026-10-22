import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { useFlash } from '@/components/flash-toaster';
import { APP_CREDIT } from '@/lib/app-info';
import { cn } from '@/lib/utils';

/**
 * Log in, forgot password and reset password: "Blue night with doodles" (design Login E2). A live clock on a dial
 * that fills through the workday (the default schedule's hours), the date and the Islamic date, and the page's card.
 * Desktop: the whole scene scaled to cover the screen; phones and tablets: the clock above the card. A persistent
 * layout, so the clock keeps running between the three pages. Doodles are decoration only (aria-hidden).
 * Props from LoginController::sceneProps().
 */
type SceneProps = { workday?: { start: string; end: string }; hijriOffset?: number };

const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const CIRC = 2 * Math.PI * 260;
/** The bundled Jost (@fontsource-variable/jost) for SVG text, where CSS variables don't work in attributes. */
const JOST = "'Jost Variable', Jost, sans-serif";
/** Hijri months as people in Pakistan write them (the browser says "Jumada I"). */
const HIJRI_MONTHS = ['Muharram', 'Safar', 'Rabi al-Awwal', 'Rabi al-Thani', 'Jumada al-Awwal', 'Jumada al-Thani', 'Rajab', 'Shaban', 'Ramadan', 'Shawwal', 'Dhul Qadah', 'Dhul Hijjah'];

const minutes = (hhmm: string) => Number(hhmm.slice(0, 2)) * 60 + Number(hhmm.slice(3, 5));
/** 1080 -> "6:00 PM" */
const twelve = (mins: number) => `${(Math.floor(mins / 60) % 12) || 12}:${String(mins % 60).padStart(2, '0')} ${Math.floor(mins / 60) % 24 < 12 ? 'AM' : 'PM'}`;

/** "11 Jumada al-Awwal 1448 AH" (Umm al-Qura calendar, moved by offset days), or null if the browser can't. */
function hijri(d: Date, offset: number): string | null {
    try {
        const shifted = new Date(d.getTime() + offset * 86_400_000);
        const parts = new Intl.DateTimeFormat('en-u-ca-islamic-umalqura-nu-latn', { day: 'numeric', month: 'numeric', year: 'numeric' }).formatToParts(shifted);
        const get = (t: string) => parts.find((p) => p.type === t)?.value ?? '';
        const month = HIJRI_MONTHS[Number(get('month')) - 1];
        return month ? `${get('day')} ${month} ${get('year').replace(/\D/g, '')} AH` : null;
    } catch {
        return null;
    }
}

function useClock(workday: { start: string; end: string }, hijriOffset: number) {
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const t = window.setInterval(() => setNow(new Date()), 10_000);
        return () => window.clearInterval(t);
    }, []);
    const h = now.getHours();
    const mins = h * 60 + now.getMinutes();
    const start = minutes(workday.start);
    const end = minutes(workday.end) > start ? minutes(workday.end) : minutes(workday.end) + 1440;
    const left = end - mins;
    return {
        time: `${h % 12 || 12}:${String(now.getMinutes()).padStart(2, '0')}`,
        ampm: h < 12 ? 'AM' : 'PM',
        greeting: h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening',
        date: `${DAYS[now.getDay()]} ${now.getDate()} ${MONTHS[now.getMonth()]}`,
        hijri: hijri(now, hijriOffset),
        dayline: mins < start ? `The workday starts at ${twelve(start)}` : mins >= end ? 'The workday is done. See you tomorrow.'
            : `${Math.floor(left / 60)}h ${left % 60}m left in the workday`,
        // How much of the workday has gone, as the ring's dash
        arc: `${Math.round(CIRC * Math.max(0, Math.min(1, (mins - start) / (end - start))))} ${Math.round(CIRC)}`,
    };
}

/** The decorations around the dial, in the design's 1440 x 900 space (desktop scene only). */
function Doodles() {
    return (
        <>
            <g fill="none" stroke="#85b4c9" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" opacity=".42">
                <g transform="translate(300 42) rotate(-6)"><path d="M2 10 Q1 2 10 2 L50 2 Q58 3 57 11 L56 50 Q56 57 48 57 L10 56 Q2 56 3 48 Z" /><path d="M2 18 L57 18 M16 -4 L16 8 M42 -4 L42 8" /><path d="M17 36 L26 45 L42 28" /></g>
                <g className="login-float-1"><path d="M560 118 C 610 160, 640 60, 690 108 S 760 140, 780 96" strokeDasharray="2 7" />
                    <g transform="translate(784 70) rotate(-18)"><path d="M0 22 L46 0 L30 44 L22 28 Z M22 28 L46 0" /></g></g>
                <g transform="translate(1336 96)"><path d="M0 18 L4 56 Q5 62 12 62 L34 62 Q41 62 42 56 L46 18 Z" /><path d="M46 28 Q60 28 58 40 Q56 50 44 50" />
                    <g className="login-steam"><path d="M14 10 Q8 2 14 -6 Q20 -14 14 -22" /><path d="M30 10 Q24 2 30 -6 Q36 -14 30 -22" /></g></g>
                <g transform="translate(58 360)"><path d="M10 40 Q10 10 36 10 Q62 10 62 40" /><path d="M18 46 Q18 18 36 18 Q54 18 54 44 Q54 60 46 70" /><path d="M26 54 Q26 26 36 26 Q46 26 46 46 Q46 66 36 80" /><path d="M36 36 Q38 60 26 84" /></g>
                <g transform="translate(64 720) rotate(4)"><path d="M0 34 L34 4 L68 34 M8 28 L8 70 L60 70 L60 28" /><path d="M26 70 L26 48 L42 48 L42 70" /><path d="M48 14 L48 2 L56 2 L56 21" /></g>
                <g transform="translate(1340 470) rotate(10)"><path d="M0 30 Q30 -10 60 30 Q50 22 40 30 Q30 22 20 30 Q10 22 0 30 Z" /><path d="M30 30 L30 70 Q30 78 22 76" /></g>
                <g transform="translate(560 790)"><path d="M10 0 L90 0 Q96 0 96 6 L96 52 L4 52 L4 6 Q4 0 10 0 Z M-8 52 L108 52 Q106 62 96 62 L4 62 Q-6 62 -8 52 Z" /><path d="M30 26 L42 36 L66 16" /></g>
                <path d="M230 210 L230 230 M220 220 L240 220" /><path d="M760 300 L760 316 M752 308 L768 308" /><path d="M1300 780 L1300 800 M1290 790 L1310 790" /><path d="M150 610 L150 622 M144 616 L156 616" /><path d="M1110 40 L1110 54 M1103 47 L1117 47" />
                <circle cx="900" cy="60" r="3" /><circle cx="250" cy="560" r="2.5" /><circle cx="1390" cy="650" r="3" />
            </g>
            <g fontFamily={JOST} fill="#85b4c9">
                <g transform="translate(1294 610)" opacity=".55">
                    <text x="0" y="-10" fontSize="11" letterSpacing="2">THIS WEEK</text>
                    <rect x="0" y="40" width="14" height="38" rx="3" /><rect x="22" y="22" width="14" height="56" rx="3" /><rect x="44" y="30" width="14" height="48" rx="3" />
                    <rect x="66" y="14" width="14" height="64" rx="3" /><rect x="88" y="26" width="14" height="52" rx="3" /><rect x="110" y="56" width="14" height="22" rx="3" opacity=".5" />
                    <path d="M-6 80 L132 80" stroke="#85b4c9" strokeWidth="1.5" fill="none" />
                    {['M', 'T', 'W', 'T', 'F', 'S'].map((d, i) => <text key={i} x={3 + i * 22.2} y="96" fontSize="10">{d}</text>)}
                </g>
            </g>
        </>
    );
}

/** Field styles the three cards share (they sit on white, outside the app's theme). */
export const authLabel = 'text-xs font-bold tracking-[.06em] text-[#5f6e82] uppercase';
export const authInput = 'h-[46px] w-full rounded-lg border border-[#cbd3dd] bg-white px-3.5 text-[15px] text-[#1d1d1d] focus:outline-2 focus:outline-offset-1 focus:outline-[#00435c]';
export const authButton = 'h-[50px] w-full cursor-pointer rounded-full bg-[#00435c] font-display text-[15px] font-semibold tracking-[.04em] text-white hover:bg-[#003a50] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#00435c] disabled:opacity-70';
export const authLink = 'font-bold text-[#00435c] hover:underline';

/** The white card: the navy band with the logo, then the page's own content. */
export function AuthCard({ children, onSubmit }: { children: ReactNode; onSubmit: (e: React.FormEvent) => void }) {
    return (
        <form onSubmit={onSubmit} className="flex flex-col overflow-hidden rounded-[18px] bg-white text-[#1d1d1d] shadow-[0_40px_90px_rgba(0,0,0,.45)]">
            <div className="flex items-center bg-[#00435c] px-7 py-4">
                <img src="/images/logo-white.png" alt="Blue Cascade" width={800} height={519} className="h-auto w-[88px]" />
            </div>
            <div className="flex flex-col gap-[15px] px-7 pt-[22px] pb-[26px]">{children}</div>
        </form>
    );
}

/** A card's title row: the square tile (initials, a key, a question mark) and the heading. */
export function AuthTitle({ tile, solid = false, title, subtitle }: { tile: ReactNode; solid?: boolean; title: string; subtitle: string }) {
    return (
        <div className="flex items-center gap-4">
            <span aria-hidden className={cn('flex size-[68px] flex-none items-center justify-center rounded-xl font-display text-2xl font-semibold text-[#00435c]',
                solid ? 'border-2 border-[#00435c] bg-[#d9e8ef]' : 'border-2 border-dashed border-[#b9d1dc] bg-[#eef5f9]')}>{tile}</span>
            <div className="flex flex-col gap-0.5">
                <h1 className="m-0 font-display text-[26px] leading-[1.15] font-medium text-[#00435c]">{title}</h1>
                <span className="text-[13px] text-[#5f6e82]">{subtitle}</span>
            </div>
        </div>
    );
}

/** The card's last line, under a dashed rule (Back to log in). */
export function AuthFooter({ children }: { children: ReactNode }) {
    return <div className="border-t border-dashed border-[#dce1e8] pt-3 text-center text-sm">{children}</div>;
}

function Credit({ className }: { className?: string }) {
    return <p className={cn('m-0 text-xs text-[#5f8799]', className)}>© {new Date().getFullYear()} Blue Cascade · {APP_CREDIT}</p>;
}

function Tagline({ className }: { className?: string }) {
    return (
        <p className={cn('m-0 font-display text-[13px] tracking-[.16em] text-[#8fb3c4] uppercase', className)}>
            <span className="text-[#85b4c9]">b</span>reaking the i<span className="text-[#85b4c9]">ll</span>usions
        </p>
    );
}

export default function LoginScene({ children }: { children: ReactNode }) {
    useFlash();
    const props = usePage<SceneProps>().props;
    const c = useClock(props.workday ?? { start: '09:00', end: '18:00' }, props.hijriOffset ?? 0);

    return (
        <div className="relative min-h-svh overflow-hidden bg-[#021c28] font-sans text-white">
            {/* Desktop: the scene, scaled to cover the screen; the clock is drawn in it so it stays centred on the dial. */}
            <svg aria-hidden viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice" className="absolute inset-0 hidden size-full lg:block">
                <rect width="1440" height="900" fill="#021c28" />
                <circle cx="420" cy="450" r="540" fill="#03293a" />
                <circle cx="420" cy="450" r="340" fill="#00435c" />
                <circle cx="420" cy="450" r="260" fill="none" stroke="#b9d6e3" strokeWidth="1" opacity=".3" />
                <circle cx="420" cy="450" r="340" fill="none" stroke="#b9d6e3" strokeWidth="1" opacity=".16" />
                <circle cx="420" cy="450" r="450" fill="none" stroke="#b9d6e3" strokeWidth="1" opacity=".08" />
                <g className="login-orbit"><circle cx="420" cy="190" r="6" fill="#85b4c9" /><circle cx="760" cy="450" r="4" fill="#1f7aa0" /></g>
                <circle cx="420" cy="450" r="260" fill="none" stroke="#0b3a4e" strokeWidth="6" />
                <circle cx="420" cy="450" r="260" fill="none" stroke="#5fb0d4" strokeWidth="6" strokeLinecap="round" strokeDasharray={c.arc} transform="rotate(-90 420 450)" />
                <Doodles />
                <g textAnchor="middle">
                    <text x="420" y="362" fontSize="12" fontWeight="700" letterSpacing="2.4" fill="#85b4c9">{c.greeting.toUpperCase()}</text>
                    <text x="420" y="462" fontFamily={JOST} fontSize="104" fontWeight="300" fill="#ffffff" style={{ fontVariantNumeric: 'tabular-nums' }}>
                        {c.time}<tspan fontSize="30" fontWeight="400" dx="12" fill="#b9d6e3">{c.ampm}</tspan>
                    </text>
                    <text x="420" y="506" fontFamily={JOST} fontSize="15" letterSpacing="2.1" fill="#b9d6e3">{c.date.toUpperCase()}</text>
                    {c.hijri && <text x="420" y="532" fontFamily={JOST} fontSize="14" letterSpacing="1.2" fill="#85b4c9">{c.hijri}</text>}
                    <text x="420" y={c.hijri ? 562 : 540} fontSize="13" fill="#8fb3c4">{c.dayline}</text>
                </g>
            </svg>

            {/* Phones and tablets: a softer backdrop */}
            <svg aria-hidden viewBox="0 0 400 400" className="absolute -top-40 left-1/2 w-[48rem] -translate-x-1/2 opacity-80 lg:hidden">
                <circle cx="200" cy="200" r="200" fill="#03293a" />
                <circle cx="200" cy="200" r="130" fill="#00435c" />
                <circle cx="200" cy="200" r="100" fill="none" stroke="#b9d6e3" strokeWidth=".6" opacity=".3" />
            </svg>

            <img src="/images/logo-white.png" alt="Blue Cascade" width={800} height={519} className="absolute top-12 left-16 z-10 hidden h-auto w-[130px] lg:block" />
            <Tagline className="absolute bottom-10 left-16 z-10 hidden lg:block" />
            <Credit className="absolute right-16 bottom-8 z-10 hidden lg:block" />

            <div className="relative z-10 mx-auto flex min-h-svh w-full max-w-[420px] flex-col justify-center gap-8 px-4 py-10
                lg:absolute lg:top-1/2 lg:right-[11%] lg:mx-0 lg:min-h-0 lg:w-[420px] lg:max-w-none lg:-translate-y-1/2 lg:px-0 lg:py-0">
                {/* The clock, phones and tablets */}
                <div className="flex flex-col items-center gap-1 text-center lg:hidden">
                    <span className="text-xs font-bold tracking-[.2em] text-[#85b4c9] uppercase">{c.greeting}</span>
                    <span className="font-display text-[64px] leading-none font-light tabular-nums">{c.time}<span className="ml-2 text-xl font-normal text-[#b9d6e3]">{c.ampm}</span></span>
                    <span className="mt-1 font-display text-sm tracking-[.14em] text-[#b9d6e3] uppercase">{c.date}</span>
                    {c.hijri && <span className="font-display text-sm text-[#85b4c9]">{c.hijri}</span>}
                    <span className="mt-1 text-[13px] text-[#8fb3c4]">{c.dayline}</span>
                </div>
                {children}
                <div className="flex flex-col items-center gap-2 lg:hidden"><Tagline /><Credit /></div>
            </div>
        </div>
    );
}
