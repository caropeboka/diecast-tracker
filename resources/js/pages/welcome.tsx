import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    ArchiveRestore,
    Camera,
    CircleCheck,
    Images,
    LayoutDashboard,
    ScanSearch,
    Sparkles,
    TrendingUp,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

const FEATURES: { icon: LucideIcon; title: string; text: string }[] = [
    {
        icon: Camera,
        title: 'Scan AI dari foto',
        text: 'Foto kartunya, AI mengisi nama, merek, seri, nomor koleksi, tahun, sampai kelangkaan.',
    },
    {
        icon: Images,
        title: 'Scan massal',
        text: 'Upload puluhan foto sekaligus — cocok untuk memindahkan koleksi lama dengan cepat.',
    },
    {
        icon: ScanSearch,
        title: 'Cek di toko',
        text: 'Foto diecast di rak, langsung tahu sudah punya, belum punya, atau ada di wishlist.',
    },
    {
        icon: TrendingUp,
        title: 'Harga pasaran',
        text: 'Screenshot hasil pencarian Tokopedia/Shopee — median & akurasinya dihitung otomatis.',
    },
    {
        icon: LayoutDashboard,
        title: 'Dashboard nilai',
        text: 'Total nilai koleksi, untung/rugi, yang paling berharga, dan statistik per merek.',
    },
    {
        icon: ArchiveRestore,
        title: 'Backup & Excel',
        text: 'Satu file ZIP berisi semua data dan foto, plus export/import Excel.',
    },
];

const STEPS = [
    {
        title: 'Foto',
        text: 'Ambil foto depan kartu diecast dengan kamera HP.',
    },
    {
        title: 'Cek',
        text: 'AI mengisi datanya. Periksa field bertanda ✦ dan lengkapi harga.',
    },
    {
        title: 'Simpan',
        text: 'Masuk ke garasimu — lengkap dengan foto, nilai, dan statistik.',
    },
];

/** Illustrative app preview built from UI pieces — sample data, not anyone's collection. */
function HeroPreview() {
    const fields = [
        ['Nama', "'71 Datsun 510"],
        ['Merek', 'Hot Wheels'],
        ['Seri', 'HW J-Imports'],
        ['Nomor', '123/250'],
    ];

    return (
        <div className="relative mx-auto w-full max-w-md">
            <div className="surface aura rounded-2xl p-4 shadow-2xl shadow-black/40">
                <div className="flex gap-4">
                    {/* Card art */}
                    <div className="relative flex aspect-[3/4] w-32 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-brand-gradient sm:w-36">
                        <div className="absolute inset-x-3 top-3 h-5 rounded bg-black/25" />
                        <AppLogoIcon className="w-24 fill-current text-black/70" />
                        <div className="absolute inset-x-3 bottom-3 h-3 rounded bg-black/20" />
                    </div>
                    <div className="min-w-0 flex-1 space-y-2">
                        <p className="flex items-center gap-1.5 text-xs font-medium text-brand">
                            <Sparkles className="size-3.5" /> Diisi oleh AI
                        </p>
                        {fields.map(([label, value]) => (
                            <div
                                key={label}
                                className="rounded-md border border-brand/40 bg-brand/8 px-2.5 py-1.5"
                            >
                                <p className="text-[10px] text-muted-foreground">
                                    {label}
                                </p>
                                <p className="truncate text-sm font-medium">
                                    {value}
                                </p>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {/* Floating chips */}
            <div className="surface absolute -bottom-6 -left-4 rounded-xl px-4 py-3 shadow-xl shadow-black/40 sm:-left-10">
                <p className="text-[11px] text-muted-foreground">
                    Nilai koleksi
                </p>
                <p className="text-lg font-semibold">
                    Rp 2,4 jt{' '}
                    <span className="text-sm font-medium text-viz-up">
                        ▲ 18%
                    </span>
                </p>
            </div>
            <div className="surface absolute -top-5 -right-3 flex items-center gap-2 rounded-xl px-3 py-2 text-sm shadow-xl shadow-black/40 sm:-right-8">
                <CircleCheck className="size-4 text-viz-up" />
                Sudah punya 2×
            </div>
        </div>
    );
}

export default function Welcome() {
    const { auth, name } = usePage().props;

    return (
        <>
            <Head title="Garasi digital untuk koleksi diecast" />
            <div className="min-h-screen overflow-x-clip">
                {/* Nav */}
                <header className="sticky top-0 z-30 border-b border-border/60 bg-background/70 backdrop-blur-md">
                    <nav className="mx-auto flex h-16 max-w-6xl items-center gap-6 px-4 sm:px-6">
                        <Link href="/" className="flex items-center gap-2.5">
                            <span className="flex size-8 items-center justify-center rounded-md bg-brand-gradient text-brand-foreground">
                                <AppLogoIcon className="w-6 fill-current" />
                            </span>
                            <span className="font-heading text-lg font-semibold">
                                {name}
                            </span>
                        </Link>
                        <div className="hidden items-center gap-6 text-sm text-muted-foreground md:flex">
                            <a href="#fitur" className="hover:text-foreground">
                                Fitur
                            </a>
                            <a href="#cara" className="hover:text-foreground">
                                Cara pakai
                            </a>
                        </div>
                        <div className="ml-auto flex items-center gap-2">
                            {auth.user ? (
                                <Button asChild>
                                    <Link href={dashboard()}>
                                        Buka garasi <ArrowRight />
                                    </Link>
                                </Button>
                            ) : (
                                <>
                                    <Button variant="ghost" asChild>
                                        <Link href={login()}>Masuk</Link>
                                    </Button>
                                    <Button asChild>
                                        <Link href={register()}>Daftar</Link>
                                    </Button>
                                </>
                            )}
                        </div>
                    </nav>
                </header>

                {/* Hero */}
                <section className="mx-auto grid max-w-6xl items-center gap-16 px-4 pt-14 pb-24 sm:px-6 md:pt-20 lg:grid-cols-[1.1fr_1fr]">
                    <div className="space-y-7">
                        <p className="inline-flex items-center gap-2 rounded-full border border-brand/40 bg-brand/10 px-3 py-1 text-xs font-medium text-brand">
                            <Sparkles className="size-3.5" />
                            Hot Wheels · Tomica · Mini GT · Matchbox & lainnya
                        </p>
                        <h1 className="font-heading text-4xl leading-[1.05] font-semibold sm:text-5xl lg:text-6xl">
                            Catat koleksi diecast-mu{' '}
                            <span className="text-brand-gradient">
                                cukup dengan satu foto.
                            </span>
                        </h1>
                        <p className="max-w-xl text-lg text-muted-foreground">
                            AI membaca kartunya, kamu tinggal simpan. Tahu
                            persis apa yang sudah kamu punya, berapa nilainya,
                            dan apa yang masih diburu.
                        </p>
                        <div className="flex flex-wrap gap-3">
                            <Button size="lg" asChild>
                                <Link href={auth.user ? dashboard() : register()}>
                                    {auth.user ? 'Buka garasi' : 'Mulai sekarang'}{' '}
                                    <ArrowRight />
                                </Link>
                            </Button>
                            {!auth.user && (
                                <Button size="lg" variant="outline" asChild>
                                    <Link href={login()}>Saya sudah punya akun</Link>
                                </Button>
                            )}
                        </div>
                    </div>
                    <HeroPreview />
                </section>

                {/* Features */}
                <section
                    id="fitur"
                    className="mx-auto max-w-6xl scroll-mt-20 px-4 py-20 sm:px-6"
                >
                    <div className="mb-12 max-w-2xl space-y-3">
                        <p className="text-sm font-medium text-brand">Fitur</p>
                        <h2 className="font-heading text-3xl font-semibold sm:text-4xl">
                            Semua yang dibutuhkan kolektor, di satu garasi.
                        </h2>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {FEATURES.map(({ icon: Icon, title, text }) => (
                            <div key={title} className="surface lift rounded-xl p-6">
                                <span className="mb-4 flex size-11 items-center justify-center rounded-lg bg-brand/12 text-brand">
                                    <Icon className="size-5" />
                                </span>
                                <h3 className="font-heading text-lg font-semibold">
                                    {title}
                                </h3>
                                <p className="mt-1.5 text-sm text-muted-foreground">
                                    {text}
                                </p>
                            </div>
                        ))}
                    </div>
                </section>

                {/* Steps */}
                <section
                    id="cara"
                    className="mx-auto max-w-6xl scroll-mt-20 px-4 py-20 sm:px-6"
                >
                    <div className="mb-12 max-w-2xl space-y-3">
                        <p className="text-sm font-medium text-brand">
                            Cara pakai
                        </p>
                        <h2 className="font-heading text-3xl font-semibold sm:text-4xl">
                            Dari rak ke garasi digital dalam 3 langkah.
                        </h2>
                    </div>
                    <ol className="grid gap-4 md:grid-cols-3">
                        {STEPS.map((step, index) => (
                            <li key={step.title} className="surface rounded-xl p-6">
                                <span className="font-heading text-5xl font-semibold text-brand-gradient">
                                    0{index + 1}
                                </span>
                                <h3 className="mt-3 font-heading text-xl font-semibold">
                                    {step.title}
                                </h3>
                                <p className="mt-1.5 text-sm text-muted-foreground">
                                    {step.text}
                                </p>
                            </li>
                        ))}
                    </ol>
                </section>

                {/* CTA */}
                <section className="mx-auto max-w-6xl px-4 pb-24 sm:px-6">
                    <div className="surface aura flex flex-col items-start gap-6 overflow-hidden rounded-2xl p-8 md:flex-row md:items-center md:justify-between md:p-12">
                        <div className="space-y-2">
                            <h2 className="font-heading text-3xl font-semibold">
                                Siap merapikan garasimu?
                            </h2>
                            <p className="text-muted-foreground">
                                Mulai dari satu foto. Sisanya biar AI yang
                                bantu.
                            </p>
                        </div>
                        <Button size="lg" asChild>
                            <Link href={auth.user ? dashboard() : register()}>
                                {auth.user ? 'Buka garasi' : 'Buat akun'}{' '}
                                <ArrowRight />
                            </Link>
                        </Button>
                    </div>
                </section>

                <footer className="border-t border-border/60">
                    <div className="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-8 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <span className="flex items-center gap-2">
                            <AppLogoIcon className="w-5 fill-current text-brand" />
                            {name}
                        </span>
                        <span>Dibuat untuk para kolektor diecast Indonesia.</span>
                    </div>
                </footer>
            </div>
        </>
    );
}
