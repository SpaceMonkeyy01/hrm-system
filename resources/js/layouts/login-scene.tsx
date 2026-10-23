import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { ComponentType, ReactNode } from 'react';
import { useFlash } from '@/components/flash-toaster';
import { APP_CREDIT } from '@/lib/app-info';
import { cn } from '@/lib/utils';

/**
 * Log in, forgot password and reset password: "Login Light" (docs/HRM-UI-REVIEW.md, "Login, final design"). A pale dotted
 * page, a navy disc with a live clock whose ring fills through the workday (the default schedule's hours), the date and
 * the Islamic date, navy line doodles, a few swimming fish, and the page's card. Desktop (the `wide` variant: landscape,
 * 1024px+): the scene scaled to fit the screen, the card on the right; phones and portrait tablets: a smaller disc above
 * the card. A persistent layout, so the clock keeps running between the three pages. Doodles and fish are decoration
 * only (aria-hidden). Props from LoginController::sceneProps().
 */
type SceneProps = { workday?: { start: string; end: string }; hijriOffset?: number };

const DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
/** The bundled Jost (@fontsource-variable/jost) for SVG text, where CSS variables don't work in attributes. */
const JOST = "'Jost Variable', Jost, sans-serif";
/** Hijri months as people in Pakistan write them (the browser says "Jumada I"). */
const HIJRI_MONTHS = ['Muharram', 'Safar', 'Rabi al-Awwal', 'Rabi al-Thani', 'Jumada al-Awwal', 'Jumada al-Thani', 'Rajab', 'Shaban', 'Ramadan', 'Shawwal', 'Dhul Qadah', 'Dhul Hijjah'];
const NAVY = '#00435c';
/** Soft circles behind some doodles. */
const TINT = '#d8eaf3';

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
    // How much of the workday has gone, 0 to 1
    const done = Math.max(0, Math.min(1, (mins - start) / (end - start)));
    return {
        time: `${h % 12 || 12}:${String(now.getMinutes()).padStart(2, '0')}`,
        ampm: h < 12 ? 'AM' : 'PM',
        greeting: h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening',
        date: `${DAYS[now.getDay()]} ${now.getDate()} ${MONTHS[now.getMonth()]}`,
        shortDate: `${DAYS[now.getDay()].slice(0, 3)} ${now.getDate()} ${MONTHS[now.getMonth()].slice(0, 3)}`,
        hijri: hijri(now, hijriOffset),
        dayline: mins < start ? `The workday starts at ${twelve(start)}` : mins >= end ? 'The workday is done. See you tomorrow.'
            : `${Math.floor(left / 60)}h ${left % 60}m left in the workday`,
        /** The ring's dash for a circle of radius r */
        arc: (r: number) => `${(2 * Math.PI * r * done).toFixed(1)} ${(2 * Math.PI * r).toFixed(1)}`,
    };
}

function useReducedMotion() {
    const [reduced, setReduced] = useState(() => typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    useEffect(() => {
        const q = window.matchMedia('(prefers-reduced-motion: reduce)');
        const on = () => setReduced(q.matches);
        q.addEventListener('change', on);
        return () => q.removeEventListener('change', on);
    }, []);
    return reduced;
}

/** A small fish facing right, centred on 0,0. `flip` draws it upside down, so it swims upright on a route going left. */
function Fish({ scale = 1, flip = false }: { scale?: number; flip?: boolean }) {
    return (
        <g transform={`scale(${scale} ${flip ? -scale : scale})`} stroke={NAVY} strokeWidth={1.4 / scale} strokeLinejoin="round">
            <path className="login-tail" d="M-9 0 L-17 -6 L-16 6 Z" fill={TINT} />
            <path d="M-10 0 Q-2 -8 9 -1.5 Q11.5 0 9 1.5 Q-2 8 -10 0 Z" fill="#ffffff" />
            <path d="M-1 -5 Q1 0 -1 5" fill="none" />
            <circle cx="5" cy="-1" r="1.1" fill={NAVY} stroke="none" />
        </g>
    );
}

type Route = { d: string; dur: number; begin: number; scale: number; flip?: boolean };

/** Fish that swim along the routes (left out when the person asks for less motion). */
function Swimmers({ routes }: { routes: Route[] }) {
    if (useReducedMotion()) return null;
    return (
        <g opacity=".85">
            {routes.map((r) => (
                <g key={r.d}>
                    <animateMotion dur={`${r.dur}s`} begin={`-${r.begin}s`} repeatCount="indefinite" rotate="auto" path={r.d} />
                    <Fish scale={r.scale} flip={r.flip} />
                </g>
            ))}
        </g>
    );
}

/** Desktop, in the design's 1440 x 900 space: along the top both ways, the bottom, the right edge, between the disc and the card, the lower left. */
const DESKTOP_ROUTES: Route[] = [
    { d: 'M-60 130 C200 70, 420 40, 700 92 S1100 40, 1500 120', dur: 34, begin: 6, scale: 1.5 },
    { d: 'M1500 30 C1300 70, 1100 0, 900 46 S600 16, -60 36', dur: 30, begin: 18, scale: 1.2, flip: true },
    { d: 'M1500 850 C1200 812, 1000 886, 700 862 S300 812, -60 872', dur: 38, begin: 4, scale: 1.4, flip: true },
    { d: 'M1405 -40 C1360 200, 1430 400, 1385 600 S1405 800, 1395 940', dur: 28, begin: 12, scale: 1.3 },
    { d: 'M800 940 C770 720, 845 540, 790 340 S830 110, 800 -40', dur: 32, begin: 20, scale: 1.3 },
    { d: 'M-60 470 C40 560, 10 680, 120 770 S260 880, 320 960', dur: 26, begin: 9, scale: 1.2 },
];

/** Phone, in the top picture's 362 x 300 space: round the dotted orbit. */
const PHONE_ROUTES: Route[] = [{ d: 'M181 10 A155 155 0 1 1 180.9 10 Z', dur: 36, begin: 5, scale: 1 }];
/** Phone, in the bottom picture's 362 x 120 space: across, going left. */
const PHONE_BOTTOM_ROUTES: Route[] = [{ d: 'M400 64 C300 40, 220 92, 140 62 S20 70, -40 58', dur: 22, begin: 8, scale: 1, flip: true }];

/* Doodles: navy line art, each drawn at the origin and placed with a transform. */
const lines = { fill: 'none', stroke: NAVY, strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round' } as const;
const Calendar = () => <><path d="M2 10 Q1 2 10 2 L50 2 Q58 3 57 11 L56 50 Q56 57 48 57 L10 56 Q2 56 3 48 Z" /><path d="M2 18 L57 18 M16 -4 L16 8 M42 -4 L42 8" /><path d="M17 36 L26 45 L42 28" /></>;
const Plane = () => <path d="M0 22 L46 0 L30 44 L22 28 Z M22 28 L46 0" />;
const Coffee = () => (
    <><path d="M0 18 L4 56 Q5 62 12 62 L34 62 Q41 62 42 56 L46 18 Z" /><path d="M46 28 Q60 28 58 40 Q56 50 44 50" />
        <g className="login-steam"><path d="M14 10 Q8 2 14 -6 Q20 -14 14 -22" /><path d="M30 10 Q24 2 30 -6 Q36 -14 30 -22" /></g></>
);
const Heart = () => <><path d="M24 44 C6 32, -2 20, 4 10 C10 0, 22 2, 24 12 C26 2, 38 0, 44 10 C50 20, 42 32, 24 44 Z" /><path d="M24 16 V30 M17 23 H31" /></>;
const Umbrella = () => <><path d="M0 30 Q30 -10 60 30 Q50 22 40 30 Q30 22 20 30 Q10 22 0 30 Z" /><path d="M30 30 L30 70 Q30 78 22 76" /></>;
const House = () => <><path d="M0 34 L34 4 L68 34 M8 28 L8 70 L60 70 L60 28" /><path d="M26 70 L26 48 L42 48 L42 70" /><path d="M48 14 L48 2 L56 2 L56 21" /></>;
const Laptop = () => <><path d="M10 0 L90 0 Q96 0 96 6 L96 52 L4 52 L4 6 Q4 0 10 0 Z M-8 52 L108 52 Q106 62 96 62 L4 62 Q-6 62 -8 52 Z" /><path d="M30 26 L42 36 L66 16" /></>;
const Plus = ({ x, y, s = 10 }: { x: number; y: number; s?: number }) => <path d={`M${x} ${y - s} V${y + s} M${x - s} ${y} H${x + s}`} />;

/** Everything around the disc and the card on desktop, in the design's 1440 x 900 space. Nothing over either. */
function DesktopDoodles() {
    return (
        <>
            <g fill={TINT}>
                <circle cx="276" cy="52" r="30" /><circle cx="1012" cy="92" r="30" /><circle cx="1392" cy="80" r="32" /><circle cx="1362" cy="300" r="32" />
                <circle cx="812" cy="246" r="26" /><circle cx="800" cy="668" r="26" /><circle cx="44" cy="586" r="30" />
            </g>
            <g {...lines} opacity=".55">
                <g transform="translate(255 22) rotate(-4)"><Calendar /></g>
                <path d="M482 80 C540 104, 600 40, 660 72 S728 62, 748 50" strokeDasharray="2 7" />
                <g className="login-float-1"><g transform="translate(750 26) rotate(-18)"><Plane /></g></g>
                <g transform="translate(884 138)"><path d="M0 6 A20 6 0 0 0 40 6 A20 6 0 0 0 0 6 V26 A20 6 0 0 0 40 26 V6 M0 16 A20 6 0 0 0 40 16" /></g>
                <g transform="translate(986 68) rotate(8)"><rect x="0" y="0" width="54" height="38" rx="4" /><path d="M2 3 L27 22 L52 3" /></g>
                <path d="M1123 150 L1128.3 160.7 L1140.1 162.4 L1131.6 170.6 L1133.6 182.6 L1123 177 L1112.4 182.6 L1114.4 170.6 L1105.9 162.4 L1117.7 160.7 Z" />
                <g transform="translate(1344 48)"><Coffee /></g>
                <g transform="translate(1332 284) rotate(10)"><Umbrella /></g>
                <g transform="translate(1334 708)"><rect x="0" y="6" width="48" height="62" rx="5" /><rect x="14" y="0" width="20" height="12" rx="3" /><path d="M10 30 L15 35 L23 26 M28 31 L40 31 M10 50 L15 55 L23 46 M28 51 L40 51" /></g>
                <g transform="translate(788 222)"><Heart /></g>
                <g transform="translate(798 446)"><path d="M18 52 C6 36, 0 28, 0 18 A18 18 0 0 1 36 18 C36 28, 30 36, 18 52 Z" /><circle cx="18" cy="18" r="7" /></g>
                <g transform="translate(772 644)"><path d="M4 0 H56 Q60 0 60 4 V36 Q60 40 56 40 H22 L10 52 L12 40 H4 Q0 40 0 36 V4 Q0 0 4 0 Z" /><circle cx="18" cy="20" r="2.5" /><circle cx="30" cy="20" r="2.5" /><circle cx="42" cy="20" r="2.5" /></g>
                <g transform="translate(520 812)"><Laptop /></g>
                <g transform="translate(374 806)"><rect x="0" y="10" width="52" height="36" rx="4" /><path d="M18 10 V4 Q18 0 22 0 H30 Q34 0 34 4 V10 M0 24 H52 M26 20 V28" /></g>
                <g transform="translate(34 722)"><path d="M6 34 H40 L36 62 H10 Z M3 34 H43" /><path d="M23 34 V18 M23 22 Q12 22 8 10 Q20 8 23 18 M23 18 Q26 4 40 2 Q40 16 23 22" /></g>
                <g transform="translate(16 568)"><House /></g>
                <g transform="translate(16 270)"><path d="M10 40 Q10 10 36 10 Q62 10 62 40" /><path d="M18 46 Q18 18 36 18 Q54 18 54 44 Q54 60 46 70" /><path d="M26 54 Q26 26 36 26 Q46 26 46 46 Q46 66 36 80" /><path d="M36 36 Q38 60 26 84" /></g>
                <g transform="translate(40 172)"><circle cx="24" cy="28" r="20" /><path d="M24 28 V18 M24 28 L32 32 M18 4 H30 M24 4 V8 M40 12 L44 8" /></g>
                <Plus x={760} y={124} /><Plus x={1110} y={46} s={8} /><Plus x={1280} y={204} s={8} /><Plus x={1411} y={798} /><Plus x={204} y={800} s={7} />
                <circle cx="900" cy="38" r="4" /><circle cx="830" cy="380" r="4" /><circle cx="1409" cy="639" r="4" /><circle cx="1230" cy="760" r="4" /><circle cx="250" cy="760" r="3" />
            </g>
            <g transform="translate(456 80)"><Fish scale={1.6} /></g>
            <g transform="translate(1376 714) rotate(-55)"><Fish scale={1.4} /></g>
            {/* A "This week" bar sketch */}
            <g transform="translate(1296 497)" fill={NAVY} opacity=".55">
                <text x="0" y="-10" fontFamily={JOST} fontSize="11" letterSpacing="2">THIS WEEK</text>
                <rect x="0" y="40" width="14" height="38" rx="2" /><rect x="22" y="14" width="14" height="64" rx="2" /><rect x="44" y="24" width="14" height="54" rx="2" />
                <rect x="66" y="6" width="14" height="72" rx="2" /><rect x="88" y="20" width="14" height="58" rx="2" /><rect x="110" y="56" width="14" height="22" rx="2" opacity=".45" />
                <path d="M-6 80 L136 80" stroke={NAVY} strokeWidth="1.5" fill="none" />
            </g>
        </>
    );
}

/** Phones: the doodles round the small disc, in the top picture's 362 x 300 space. */
function PhoneDoodles() {
    return (
        <>
            <g fill={TINT}><circle cx="38" cy="90" r="21" /><circle cx="326" cy="280" r="19" /></g>
            <g {...lines} opacity=".55" strokeWidth="1.6">
                <g transform="translate(22 72) scale(.58)"><Calendar /></g>
                <g transform="translate(310 40) rotate(-18) scale(.75)"><Plane /></g>
                <g transform="translate(314 202) scale(.55)"><Coffee /></g>
                <g transform="translate(14 212) scale(.6)"><Heart /></g>
                <g transform="translate(310 266) rotate(10) scale(.55)"><Umbrella /></g>
                <Plus x={306} y={135} s={7} /><Plus x={56} y={284} s={7} />
            </g>
        </>
    );
}

/** Field styles the three cards share (they sit on white, outside the app's theme). 16px text, so phones don't zoom. */
export const authLabel = 'text-xs font-bold tracking-[.08em] text-[#5f6e82] uppercase';
export const authInput = 'h-12 w-full rounded-[10px] border border-[#d3e3ec] bg-[#eef5fb] px-3.5 text-base text-[#1d1d1d] shadow-none placeholder:text-[#8a97a6] md:text-base wide:h-[46px] focus:border-[#00435c] focus:outline-2 focus:outline-offset-1 focus:outline-[#00435c] focus-visible:ring-0';
export const authButton = 'flex h-[52px] w-full cursor-pointer items-center justify-center gap-2 rounded-xl bg-[#00435c] font-display text-[17px] font-medium text-white shadow-[0_10px_24px_rgba(0,67,92,.22)] hover:bg-[#003a50] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#00435c] disabled:opacity-70 wide:h-[50px] wide:text-[15px]';
export const authLink = 'font-bold text-[#00435c] hover:underline';

/** An input with an icon inside on the left (pad the input with pl-10). */
export function AuthIconField({ icon: Icon, children }: { icon: ComponentType<{ className?: string }>; children: ReactNode }) {
    return (
        <div className="relative">
            {children}
            <Icon className="pointer-events-none absolute top-1/2 left-3.5 size-[18px] -translate-y-1/2 text-[#5f8799]" />
        </div>
    );
}

/** The white card: on desktop a navy band with the logo (phones show the logo above the clock), then the page's own content. */
export function AuthCard({ children, onSubmit }: { children: ReactNode; onSubmit: (e: React.FormEvent) => void }) {
    return (
        <form onSubmit={onSubmit} className="flex flex-col overflow-hidden rounded-[20px] border border-[#dbe8ef] bg-white text-[#1d1d1d] shadow-[0_24px_60px_rgba(0,67,92,.13)]">
            <div className="hidden items-center bg-[#00435c] px-8 py-5 wide:flex">
                <img src="/images/logo-white.png" alt="Blue Cascade" width={800} height={519} className="h-auto w-[88px]" />
            </div>
            <div className="flex flex-col gap-4 px-5 pt-7 pb-7 sm:px-8 wide:pt-6">{children}</div>
        </form>
    );
}

/** A card's title row: the square tile (a key, a question mark) and the heading. */
export function AuthTitle({ tile, solid = false, title, subtitle }: { tile: ReactNode; solid?: boolean; title: string; subtitle: string }) {
    return (
        <div className="flex items-center gap-4">
            <span aria-hidden className={cn('flex size-[60px] flex-none items-center justify-center rounded-xl font-display text-2xl font-semibold text-[#00435c]',
                solid ? 'border-2 border-[#00435c] bg-[#d9e8ef]' : 'border-2 border-dashed border-[#b9d1dc] bg-[#eef5f9]')}>{tile}</span>
            <div className="flex flex-col gap-0.5">
                <h1 className="m-0 font-display text-[28px] leading-[1.15] font-medium text-[#00435c]">{title}</h1>
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
    return <p className={cn('m-0 text-xs text-[#5f6e82]', className)}>© {new Date().getFullYear()} Blue Cascade · {APP_CREDIT}</p>;
}

export default function LoginScene({ children }: { children: ReactNode }) {
    useFlash();
    const props = usePage<SceneProps>().props;
    const c = useClock(props.workday ?? { start: '09:00', end: '18:00' }, props.hijriOffset ?? 0);

    return (
        <div className="login-dots relative min-h-svh overflow-hidden font-sans text-[#1d1d1d]">
            {/* Desktop: the scene, scaled to fit the screen (never cropped; the dot grid fills the rest); the clock is drawn in it so it stays centred on the disc. */}
            <svg aria-hidden viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid meet" className="absolute inset-0 hidden size-full overflow-visible wide:block">
                <g fill="none" stroke="#c9dde8" strokeWidth="1.2">
                    <circle cx="420" cy="450" r="520" opacity=".7" />
                    <circle cx="420" cy="450" r="380" strokeDasharray="2 8" strokeLinecap="round" />
                </g>
                <circle cx="420" cy="450" r="340" fill="#5d8a9e" />
                <circle cx="420" cy="450" r="326" fill={NAVY} />
                <g className="login-orbit"><circle cx="601" cy="783" r="7" fill={NAVY} /><circle cx="86" cy="630" r="5" fill="#85b4c9" /></g>
                <circle cx="420" cy="450" r="260" fill="none" stroke="#0d5571" strokeWidth="5" />
                <circle cx="420" cy="450" r="260" fill="none" stroke="#85b4c9" strokeWidth="5" strokeLinecap="round" strokeDasharray={c.arc(260)} transform="rotate(-90 420 450)" />
                <DesktopDoodles />
                <Swimmers routes={DESKTOP_ROUTES} />
                <g textAnchor="middle">
                    <text x="420" y="348" fontSize="12" fontWeight="700" letterSpacing="2.6" fill="#85b4c9">{c.greeting.toUpperCase()}</text>
                    <text x="420" y="452" fontFamily={JOST} fontSize="108" fontWeight="300" fill="#ffffff" style={{ fontVariantNumeric: 'tabular-nums' }}>
                        {c.time}<tspan fontSize="32" fontWeight="400" dx="12">{c.ampm}</tspan>
                    </text>
                    <text x="420" y="492" fontFamily={JOST} fontSize="16" letterSpacing="2" fill="#e3eef3">{c.date.toUpperCase()}</text>
                    {c.hijri && <text x="420" y="522" fontFamily={JOST} fontSize="15" letterSpacing="1" fill="#b9d6e3">{c.hijri}</text>}
                    <text x="420" y={c.hijri ? 556 : 534} fontSize="13" fill="#9fc2d3">{c.dayline}</text>
                </g>
                {/* Logo and tagline in the scene, so they keep clear of the doodles at any size (the card's band has the logo's alt text) */}
                <image href="/images/logo.png" x="62" y="38" width="124" height="80" />
                <g transform="translate(158 808)"><Fish scale={1.3} /></g>
                <text x="158" y="836" textAnchor="middle" fontFamily={JOST} fontSize="13" letterSpacing="2.6" fill={NAVY}>BREAKING THE ILLUSIONS</text>
            </svg>

            <Credit className="absolute right-16 bottom-8 z-10 hidden wide:block" />

            {/* Phones and portrait tablets: logo and small disc on top, the card, the credit; a house and a laptop at the bottom. */}
            <div className="relative mx-auto flex min-h-svh w-full max-w-[420px] flex-col px-4 pt-3 pb-5 wide:contents">
                <svg aria-hidden viewBox="0 0 362 120" className="pointer-events-none absolute inset-x-0 bottom-0 w-full wide:hidden">
                    <circle cx="34" cy="62" r="20" fill={TINT} />
                    <g {...lines} opacity=".55" strokeWidth="1.6">
                        <g transform="translate(20 46) scale(.6)"><House /></g>
                        <g transform="translate(310 88) scale(.38)"><Laptop /></g>
                    </g>
                    <Swimmers routes={PHONE_BOTTOM_ROUTES} />
                </svg>
                <div className="relative -mx-1 wide:hidden">
                    <img src="/images/logo.png" alt="Blue Cascade" width={800} height={519} className="absolute top-1 left-1 h-auto w-[78px]" />
                    <svg aria-hidden viewBox="0 0 362 300" className="w-full overflow-visible">
                        <circle cx="181" cy="165" r="155" fill="none" stroke="#a9c8d7" strokeWidth="1" strokeDasharray="1.5 6" strokeLinecap="round" />
                        <PhoneDoodles />
                        <circle cx="181" cy="165" r="119" fill={NAVY} />
                        <circle cx="181" cy="165" r="98" fill="none" stroke="#0d5571" strokeWidth="3" />
                        <circle cx="181" cy="165" r="98" fill="none" stroke="#85b4c9" strokeWidth="3" strokeLinecap="round" strokeDasharray={c.arc(98)} transform="rotate(-90 181 165)" />
                        <Swimmers routes={PHONE_ROUTES} />
                        <g textAnchor="middle">
                            <text x="181" y="116" fontSize="9" fontWeight="700" letterSpacing="2.4" fill="#85b4c9">{c.greeting.toUpperCase()}</text>
                            <text x="181" y="163" fontFamily={JOST} fontSize="50" fontWeight="300" fill="#ffffff" style={{ fontVariantNumeric: 'tabular-nums' }}>
                                {c.time}<tspan fontSize="17" fontWeight="400" dx="5">{c.ampm}</tspan>
                            </text>
                            <text x="181" y="186" fontFamily={JOST} fontSize="11" letterSpacing="1.6" fill="#e3eef3">{c.shortDate.toUpperCase()}</text>
                            {c.hijri && <text x="181" y="203" fontFamily={JOST} fontSize="10" fill="#b9d6e3">{c.hijri}</text>}
                        </g>
                    </svg>
                </div>

                {/* Desktop: centred on the scene's card slot (x 1070 of 1440, the scene being min(100vw, 160svh) wide), kept on screen */}
                <div className="relative z-10 mt-2 wide:absolute wide:top-1/2 wide:left-[min(calc((100vw-min(100vw,160svh))/2+min(100vw,160svh)*.743-210px),calc(100vw-444px))] wide:mt-0 wide:w-[420px] wide:-translate-y-1/2">{children}</div>
                <Credit className="relative z-10 mt-auto pt-24 text-center wide:hidden" />
            </div>
        </div>
    );
}
