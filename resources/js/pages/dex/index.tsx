import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    BookMarked,
    Check,
    CloudDownload,
    GitCompareArrows,
    ImageDown,
    Loader2,
    RefreshCw,
} from 'lucide-react';
import { useState } from 'react';
import DexController from '@/actions/App/Http/Controllers/DexController';
import { Panel } from '@/components/dashboard/charts';
import { CreateDexPanel } from '@/components/dex/custom-dex-tools';
import { DexLegend, DexMeter } from '@/components/dex/dex-meter';
import { useImageDownloads } from '@/components/dex/use-image-downloads';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { errorMessage, postJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { percent } from '@/types/dex';
import type {
    DexBrandSummary,
    DexCatalogSummary,
    DexSource,
} from '@/types/dex';

type Job = {
    year: number;
    state: 'waiting' | 'running' | 'done' | 'failed';
    message?: string;
};

type SyncResult = {
    catalog_id: number;
    name: string;
    items: number;
    added: number;
    removed: number;
    linked: number;
};

export default function DexIndex({
    catalogs,
    brands,
    sources,
    imagesPending,
    toReview,
    collectionBrands,
}: {
    catalogs: DexCatalogSummary[];
    brands: DexBrandSummary[];
    sources: DexSource[];
    imagesPending: number;
    toReview: number;
    collectionBrands: string[];
}) {
    const images = useImageDownloads(imagesPending);
    const byBrand = catalogs.reduce<Record<string, DexCatalogSummary[]>>(
        (groups, catalog) => {
            (groups[catalog.brand] ??= []).push(catalog);

            return groups;
        },
        {},
    );

    return (
        <>
            <Head title="Dex" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    title="Dex"
                    description="Seberapa banyak rilisan yang sudah kamu temui dan miliki, per tahun dan per seri."
                />

                {catalogs.length === 0 ? (
                    <EmptyState />
                ) : (
                    <div className="grid gap-3 md:grid-cols-2">
                        {brands.map((brand) => (
                            <BrandTile key={brand.brand} brand={brand} />
                        ))}
                    </div>
                )}

                {toReview > 0 && (
                    <Link
                        href={DexController.matches()}
                        className="surface lift flex items-center gap-3 rounded-xl p-4"
                    >
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand/12 text-brand">
                            <GitCompareArrows className="size-4" />
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="block text-sm font-medium">
                                {toReview} diecast perlu dicek pencocokannya
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                Konfirmasi entri Dex yang dicocokkan otomatis,
                                dan perbaiki tahun yang keliru.
                            </span>
                        </span>
                        <span className="text-sm font-medium text-brand">
                            Cocokkan
                        </span>
                    </Link>
                )}

                <SyncPanel
                    sources={sources}
                    onFinished={() =>
                        router.reload({
                            only: [
                                'catalogs',
                                'brands',
                                'sources',
                                'imagesPending',
                                'toReview',
                            ],
                        })
                    }
                />

                <CreateDexPanel brands={collectionBrands} />

                {(images.running || images.pending > 0) && (
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        {images.running ? (
                            <Loader2 className="size-4 animate-spin" />
                        ) : (
                            <ImageDown className="size-4" />
                        )}
                        {images.running
                            ? `Mengunduh foto katalog… ${images.pending} lagi`
                            : `${images.pending} foto katalog belum diunduh.`}
                        {!images.running && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => void images.start()}
                            >
                                Lanjutkan
                            </Button>
                        )}
                    </p>
                )}

                {Object.entries(byBrand).map(([brand, list]) => (
                    <section key={brand} className="space-y-3">
                        <h2 className="font-heading text-lg font-semibold">
                            {brand}
                        </h2>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {list.map((catalog) => (
                                <CatalogCard
                                    key={catalog.id}
                                    catalog={catalog}
                                />
                            ))}
                        </div>
                    </section>
                ))}

                <p className="text-xs text-muted-foreground">
                    Data & foto katalog:{' '}
                    <a
                        href="https://hotwheels.fandom.com"
                        target="_blank"
                        rel="noreferrer"
                        className="underline underline-offset-2"
                    >
                        Hot Wheels Wiki
                    </a>{' '}
                    dan{' '}
                    <a
                        href="https://matchbox.fandom.com"
                        target="_blank"
                        rel="noreferrer"
                        className="underline underline-offset-2"
                    >
                        Matchbox Wiki
                    </a>{' '}
                    (CC BY-SA).
                </p>
            </div>
        </>
    );
}

function EmptyState() {
    return (
        <div className="surface flex flex-col items-center gap-3 rounded-xl p-8 text-center">
            <BookMarked className="size-10 text-brand" />
            <p className="font-heading text-lg font-semibold">
                Dex masih kosong
            </p>
            <p className="max-w-md text-sm text-muted-foreground">
                Sinkronkan daftar rilisan Hot Wheels atau Matchbox di bawah, atau
                buat dex sendiri untuk merek lain. Koleksimu akan dicocokkan
                otomatis, lalu kelihatan berapa persen yang sudah kamu temui dan
                miliki.
            </p>
        </div>
    );
}

function BrandTile({ brand }: { brand: DexBrandSummary }) {
    return (
        <div className="surface rounded-xl p-4">
            <div className="flex items-baseline justify-between gap-2">
                <p className="font-heading text-base font-semibold">
                    {brand.brand}
                </p>
                <p className="text-xs text-muted-foreground">
                    {brand.catalogs} katalog · {brand.total} nomor
                </p>
            </div>
            <div className="mt-3 flex items-end gap-6">
                <div>
                    <p className="text-3xl font-semibold tracking-tight">
                        {percent(brand.seen, brand.total)}%
                    </p>
                    <p className="text-xs text-muted-foreground">dilihat</p>
                </div>
                <div>
                    <p className="text-3xl font-semibold tracking-tight">
                        {percent(brand.caught, brand.total)}%
                    </p>
                    <p className="text-xs text-muted-foreground">dimiliki</p>
                </div>
            </div>
            <DexMeter
                className="mt-3"
                seen={brand.seen}
                caught={brand.caught}
                total={brand.total}
            />
            <DexLegend
                className="mt-2"
                seen={brand.seen}
                caught={brand.caught}
                total={brand.total}
            />
        </div>
    );
}

function CatalogCard({ catalog }: { catalog: DexCatalogSummary }) {
    return (
        <Link
            href={DexController.show(catalog.id)}
            className="surface lift block rounded-xl p-4"
        >
            <div className="flex items-baseline justify-between gap-2">
                <p className="truncate font-medium">{catalog.name}</p>
                <p className="shrink-0 text-xs text-muted-foreground">
                    {percent(catalog.seen, catalog.total)}% dilihat
                </p>
            </div>
            <DexMeter
                className="mt-3"
                seen={catalog.seen}
                caught={catalog.caught}
                total={catalog.total}
            />
            <DexLegend
                className="mt-2"
                seen={catalog.seen}
                caught={catalog.caught}
                total={catalog.total}
            />
            {catalog.wanted > 0 && (
                <p className="mt-1 text-xs text-muted-foreground">
                    {catalog.wanted} di wishlist
                </p>
            )}
        </Link>
    );
}

function SyncPanel({
    sources,
    onFinished,
}: {
    sources: DexSource[];
    onFinished: () => void;
}) {
    const [sourceKey, setSourceKey] = useState(sources[0].key);
    const source = sources.find((s) => s.key === sourceKey) ?? sources[0];
    const years = Array.from(
        { length: source.last_year - source.first_year + 1 },
        (_, i) => source.last_year - i,
    );
    const [from, setFrom] = useState(source.first_year);
    const [to, setTo] = useState(source.last_year);
    const [jobs, setJobs] = useState<Job[]>([]);
    const running = jobs.some(
        (j) => j.state === 'running' || j.state === 'waiting',
    );

    function chooseSource(key: DexSource['key']) {
        const next = sources.find((s) => s.key === key)!;
        setSourceKey(key);
        setFrom(Math.max(from, next.first_year));
        setTo(Math.min(to, next.last_year));
    }

    async function run() {
        // Newest first: this year's wave matters most.
        const queue = years.filter((y) => y >= from && y <= to);
        setJobs(queue.map((year) => ({ year, state: 'waiting' })));

        for (const year of queue) {
            const set = (patch: Partial<Job>) =>
                setJobs((all) =>
                    all.map((j) => (j.year === year ? { ...j, ...patch } : j)),
                );

            set({ state: 'running' });

            try {
                const result = await postJson<SyncResult>(
                    DexController.sync.url(),
                    { source: source.key, year },
                );
                set({
                    state: 'done',
                    message: `${result.items} rilisan${result.linked ? ` · ${result.linked} tercocokkan` : ''}`,
                });
            } catch (error) {
                set({ state: 'failed', message: errorMessage(error) });
            }
        }

        onFinished();
    }

    return (
        <Panel
            title="Sinkron katalog"
            icon={<CloudDownload className="size-4" />}
        >
            <div className="flex flex-wrap items-end gap-3">
                <div className="grid gap-1.5">
                    <Label>Sumber</Label>
                    <div className="flex rounded-lg bg-muted p-1 text-sm">
                        {sources.map((s) => (
                            <button
                                key={s.key}
                                type="button"
                                disabled={running}
                                aria-pressed={s.key === sourceKey}
                                onClick={() => chooseSource(s.key)}
                                className={cn(
                                    'rounded-md px-3 py-1.5 transition',
                                    s.key === sourceKey
                                        ? 'bg-card font-medium shadow-sm ring-1 ring-brand/40'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {s.brand}
                            </button>
                        ))}
                    </div>
                </div>
                <YearSelect
                    label="Dari"
                    value={from}
                    years={years}
                    disabled={running}
                    onChange={(y) => {
                        setFrom(y);
                        setTo(Math.max(to, y));
                    }}
                />
                <YearSelect
                    label="Sampai"
                    value={to}
                    years={years}
                    disabled={running}
                    onChange={(y) => {
                        setTo(y);
                        setFrom(Math.min(from, y));
                    }}
                />
                <Button onClick={() => void run()} disabled={running}>
                    {running ? (
                        <Loader2 className="animate-spin" />
                    ) : (
                        <RefreshCw />
                    )}
                    Sinkron {to - from + 1} tahun
                </Button>
            </div>
            <p className="mt-2 text-xs text-muted-foreground">
                Diambil dari {source.brand} Wiki, ±15 detik per tahun. Tahun
                yang sudah ada diperbarui tanpa menghapus pencocokanmu.
                {source.synced.length > 0 &&
                    ` Sudah ada: ${[...source.synced].sort().join(', ')}.`}
            </p>

            {jobs.length > 0 && (
                <ul className="mt-4 grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
                    {jobs.map((job) => (
                        <li
                            key={job.year}
                            className="flex items-start gap-2 rounded-md bg-muted/50 px-3 py-2 text-sm"
                        >
                            {job.state === 'running' ? (
                                <Loader2 className="mt-0.5 size-4 shrink-0 animate-spin text-brand" />
                            ) : job.state === 'done' ? (
                                <Check className="mt-0.5 size-4 shrink-0 text-brand" />
                            ) : job.state === 'failed' ? (
                                <AlertTriangle className="mt-0.5 size-4 shrink-0 text-destructive" />
                            ) : (
                                <span className="mt-1.5 size-2 shrink-0 rounded-full bg-muted-foreground/40" />
                            )}
                            <span className="min-w-0">
                                <span className="font-medium">{job.year}</span>
                                {job.message && (
                                    <span
                                        className={cn(
                                            'block text-xs',
                                            job.state === 'failed'
                                                ? 'text-destructive'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {job.message}
                                    </span>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

function YearSelect({
    label,
    value,
    years,
    disabled,
    onChange,
}: {
    label: string;
    value: number;
    years: number[];
    disabled: boolean;
    onChange: (year: number) => void;
}) {
    return (
        <div className="grid gap-1.5">
            <Label>{label}</Label>
            <Select
                value={String(value)}
                onValueChange={(v) => onChange(Number(v))}
                disabled={disabled}
            >
                <SelectTrigger className="w-24">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {years.map((y) => (
                        <SelectItem key={y} value={String(y)}>
                            {y}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

DexIndex.layout = {
    breadcrumbs: [{ title: 'Dex', href: DexController.index() }],
};
