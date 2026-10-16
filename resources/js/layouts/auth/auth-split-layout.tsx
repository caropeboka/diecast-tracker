import { Link, usePage } from '@inertiajs/react';
import { Camera, ScanSearch, TrendingUp } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

const HIGHLIGHTS = [
    {
        icon: Camera,
        title: 'Foto sekali, data terisi',
        text: 'AI membaca nama, seri, dan nomor koleksi dari foto kartu.',
    },
    {
        icon: ScanSearch,
        title: 'Cek koleksi di toko',
        text: 'Foto diecast di rak — langsung tahu sudah punya atau belum.',
    },
    {
        icon: TrendingUp,
        title: 'Tahu nilai koleksimu',
        text: 'Harga pasaran dari listing Tokopedia & Shopee.',
    },
];

function Brand({ name }: { name: string }) {
    return (
        <Link href={home()} className="inline-flex items-center gap-2.5">
            <span className="flex size-9 items-center justify-center rounded-lg bg-brand-gradient text-brand-foreground shadow-[0_8px_24px_-8px_var(--brand-glow)]">
                <AppLogoIcon className="w-7 fill-current" />
            </span>
            <span className="font-heading text-xl font-semibold">{name}</span>
        </Link>
    );
}

export default function AuthSplitLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name } = usePage().props;

    return (
        <div className="grid min-h-dvh lg:grid-cols-[1.1fr_1fr]">
            {/* Brand panel (desktop) */}
            <aside className="aura relative hidden overflow-hidden border-r border-border bg-card p-10 lg:flex lg:flex-col xl:p-14">
                <Brand name={name} />

                <div className="my-auto max-w-lg space-y-8">
                    <h2 className="font-heading text-4xl leading-tight font-semibold xl:text-5xl">
                        Garasi digital untuk{' '}
                        <span className="text-brand-gradient">
                            koleksi diecast
                        </span>
                        -mu.
                    </h2>
                    <ul className="space-y-5">
                        {HIGHLIGHTS.map(({ icon: Icon, title: heading, text }) => (
                            <li key={heading} className="flex gap-4">
                                <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-brand/12 text-brand">
                                    <Icon className="size-5" />
                                </span>
                                <div>
                                    <p className="font-medium">{heading}</p>
                                    <p className="text-sm text-muted-foreground">
                                        {text}
                                    </p>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>

                {/* Oversized mark as a watermark */}
                <AppLogoIcon
                    aria-hidden
                    className="pointer-events-none absolute -right-16 -bottom-10 w-[28rem] fill-current text-brand opacity-[0.06]"
                />
            </aside>

            {/* Form */}
            <main className="flex items-center justify-center px-6 py-10 sm:px-10">
                <div className="w-full max-w-sm space-y-8">
                    <div className="lg:hidden">
                        <Brand name={name} />
                    </div>
                    <div className="space-y-2">
                        <h1 className="font-heading text-3xl font-semibold">
                            {title}
                        </h1>
                        <p className="text-sm text-balance text-muted-foreground">
                            {description}
                        </p>
                    </div>
                    {children}
                </div>
            </main>
        </div>
    );
}
