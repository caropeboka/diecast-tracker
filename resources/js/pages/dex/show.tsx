import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Car,
    Check,
    ExternalLink,
    Eye,
    Heart,
    LayoutGrid,
    Loader2,
    Plus,
    Rows3,
    Search,
} from 'lucide-react';
import { useCallback, useMemo, useRef, useState } from 'react';
import DexController from '@/actions/App/Http/Controllers/DexController';
import DiecastController from '@/actions/App/Http/Controllers/DiecastController';
import {
    CustomDexToolbar,
    DeleteCatalogButton,
    DeleteItemButton,
} from '@/components/dex/custom-dex-tools';
import { DexLegend, DexMeter } from '@/components/dex/dex-meter';
import { useImageDownloads } from '@/components/dex/use-image-downloads';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import { STATUS_LABELS } from '@/types/diecast';
import { percent } from '@/types/dex';
import type { DexCounts, DexEntry, DexFlags, DexSeries } from '@/types/dex';

type Filter = 'all' | 'missing' | 'seen' | 'caught' | 'wanted';

const FILTERS: { key: Filter; label: string; count: (s: DexCounts) => number }[] = [
    { key: 'all', label: 'Semua', count: (s) => s.total },
    { key: 'missing', label: 'Belum', count: (s) => s.total - s.seen },
    { key: 'seen', label: 'Dilihat', count: (s) => s.seen },
    { key: 'caught', label: 'Dimiliki', count: (s) => s.caught },
    { key: 'wanted', label: 'Diincar', count: (s) => s.wanted },
];

const matchesFilter = (entry: DexEntry, filter: Filter) =>
    ({
        all: true,
        missing: !entry.seen,
        seen: entry.seen,
        caught: entry.caught,
        wanted: entry.wanted,
    })[filter];

type CatalogInfo = {
    id: number;
    brand: string;
    name: string;
    year: number | null;
    source: string;
    source_url: string | null;
    synced_at: string | null;
};

export default function DexShow({
    catalog,
    entries,
    series,
    stats,
    imagesPending,
    aiEnabled,
}: {
    catalog: CatalogInfo;
    entries: DexEntry[];
    series: DexSeries[];
    stats: DexCounts;
    imagesPending: number;
    aiEnabled: boolean;
}) {
    const custom = catalog.source === 'custom';
    const [filter, setFilter] = useState<Filter>('all');
    const [query, setQuery] = useState('');
    const [view, setView] = useState<'grid' | 'series'>('grid');
    const [openNumber, setOpenNumber] = useState<string | null>(null);

    // Show photos as they arrive (every few batches, and when done).
    const batches = useRef(0);
    const reload = useCallback((remaining: number) => {
        batches.current++;

        if (remaining === 0 || batches.current % 4 === 0) {
            router.reload({ only: ['entries'] });
        }
    }, []);
    const images = useImageDownloads(imagesPending, {
        catalogId: catalog.id,
        onBatch: reload,
    });

    const visible = useMemo(() => {
        const q = query.trim().toLowerCase();

        return entries.filter(
            (entry) =>
                matchesFilter(entry, filter) &&
                (!q ||
                    entry.number.toLowerCase().includes(q) ||
                    entry.name.toLowerCase().includes(q) ||
                    (entry.series ?? '').toLowerCase().includes(q)),
        );
    }, [entries, filter, query]);

    const open = entries.find((e) => e.number === openNumber) ?? null;

    return (
        <>
            <Head title={catalog.name} />
            <div className="space-y-5 p-4 md:p-6">
                <Link
                    href={DexController.index()}
                    className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" /> Semua Dex
                </Link>
                <Heading
                    title={catalog.name}
                    description={`${stats.total} nomor${catalog.source_url ? ' · data dari wiki komunitas' : custom ? ` · dex buatanmu (${catalog.brand})` : ''}`}
                />
                {custom && (
                    <CustomDexToolbar
                        catalogId={catalog.id}
                        brand={catalog.brand}
                        aiEnabled={aiEnabled}
                    />
                )}

                <section className="surface rounded-xl p-4">
                    <div className="flex flex-wrap items-end gap-x-8 gap-y-3">
                        <BigNumber
                            value={`${percent(stats.seen, stats.total)}%`}
                            label={`dilihat · ${stats.seen}/${stats.total}`}
                        />
                        <BigNumber
                            value={`${percent(stats.caught, stats.total)}%`}
                            label={`dimiliki · ${stats.caught}/${stats.total}`}
                        />
                        {stats.wanted > 0 && (
                            <BigNumber
                                value={String(stats.wanted)}
                                label="diincar (wishlist)"
                            />
                        )}
                    </div>
                    <DexMeter
                        className="mt-4"
                        seen={stats.seen}
                        caught={stats.caught}
                        total={stats.total}
                    />
                    <DexLegend
                        className="mt-2"
                        seen={stats.seen}
                        caught={stats.caught}
                        total={stats.total}
                    />
                </section>

                <div className="flex flex-wrap items-center gap-2">
                    <div
                        className="flex w-full rounded-lg bg-muted p-1 text-sm sm:w-auto"
                        role="group"
                        aria-label="Filter"
                    >
                        {FILTERS.map((f) => (
                            <button
                                key={f.key}
                                type="button"
                                aria-pressed={filter === f.key}
                                onClick={() => setFilter(f.key)}
                                className={cn(
                                    // Phones: label over count, so all five fit.
                                    'flex flex-1 flex-col items-center rounded-md px-1.5 py-1 text-xs whitespace-nowrap transition sm:flex-none sm:flex-row sm:gap-1 sm:px-2.5 sm:py-1.5 sm:text-sm',
                                    filter === f.key
                                        ? 'bg-card font-medium shadow-sm ring-1 ring-brand/40'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {f.label}
                                <span className="text-[11px] text-muted-foreground sm:text-xs">
                                    {f.count(stats)}
                                </span>
                            </button>
                        ))}
                    </div>
                    <div className="relative min-w-40 flex-1">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            aria-label="Cari di Dex"
                            placeholder="Cari nama, nomor, seri…"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            className="pl-8"
                        />
                    </div>
                    <div className="flex rounded-lg bg-muted p-1" role="group" aria-label="Tampilan">
                        {(
                            [
                                ['grid', 'Grid', LayoutGrid],
                                ['series', 'Per seri', Rows3],
                            ] as const
                        ).map(([key, label, Icon]) => (
                            <button
                                key={key}
                                type="button"
                                aria-pressed={view === key}
                                onClick={() => setView(key)}
                                className={cn(
                                    'flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm transition',
                                    view === key
                                        ? 'bg-card font-medium shadow-sm ring-1 ring-brand/40'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                <Icon className="size-4" /> {label}
                            </button>
                        ))}
                    </div>
                </div>

                {images.running && (
                    <p className="flex items-center gap-2 text-xs text-muted-foreground">
                        <Loader2 className="size-3.5 animate-spin" /> Mengunduh
                        foto katalog… {images.pending} lagi
                    </p>
                )}

                {visible.length === 0 ? (
                    <p className="py-10 text-center text-sm text-muted-foreground">
                        {entries.length === 0
                            ? 'Dex ini masih kosong. Tambah entri satu per satu, atau isi banyak sekaligus dari Excel, screenshot checklist, atau koleksimu.'
                            : 'Tidak ada yang cocok.'}
                    </p>
                ) : view === 'grid' ? (
                    <EntryGrid entries={visible} onOpen={setOpenNumber} />
                ) : (
                    <div className="space-y-6">
                        {series.map((group) => {
                            const list = visible.filter(
                                (e) => (e.series ?? 'Lainnya') === group.series,
                            );

                            return list.length ? (
                                <section key={group.series} className="space-y-2">
                                    <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                                        <h2 className="font-heading text-base font-semibold">
                                            {group.series}
                                        </h2>
                                        <p className="text-xs text-muted-foreground">
                                            dilihat {group.seen}/{group.total} ·
                                            dimiliki {group.caught}/{group.total}
                                        </p>
                                    </div>
                                    <DexMeter
                                        seen={group.seen}
                                        caught={group.caught}
                                        total={group.total}
                                    />
                                    <EntryGrid entries={list} onOpen={setOpenNumber} />
                                </section>
                            ) : null;
                        })}
                    </div>
                )}

                <div className="flex flex-wrap items-center justify-between gap-2">
                    {catalog.source_url ? (
                        <p className="text-xs text-muted-foreground">
                            Data & foto:{' '}
                            <a
                                href={catalog.source_url}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex items-center gap-1 underline underline-offset-2"
                            >
                                {catalog.brand} Wiki <ExternalLink className="size-3" />
                            </a>{' '}
                            (CC BY-SA).
                        </p>
                    ) : (
                        <span />
                    )}
                    <DeleteCatalogButton catalogId={catalog.id} name={catalog.name} />
                </div>
            </div>

            <EntrySheet
                entry={open}
                catalogName={catalog.name}
                custom={custom}
                onClose={() => setOpenNumber(null)}
            />
        </>
    );
}

function BigNumber({ value, label }: { value: string; label: string }) {
    return (
        <div>
            <p className="text-3xl font-semibold tracking-tight">{value}</p>
            <p className="text-xs text-muted-foreground">{label}</p>
        </div>
    );
}

function EntryGrid({
    entries,
    onOpen,
}: {
    entries: DexEntry[];
    onOpen: (number: string) => void;
}) {
    return (
        <div className="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 2xl:grid-cols-8">
            {entries.map((entry) => (
                <button
                    key={entry.number}
                    type="button"
                    onClick={() => onOpen(entry.number)}
                    className="surface lift flex flex-col overflow-hidden rounded-lg text-left"
                >
                    <div className="relative aspect-[4/3] bg-muted/40">
                        {entry.photo_url ? (
                            <img
                                src={entry.photo_url}
                                alt=""
                                loading="lazy"
                                className={cn(
                                    'size-full object-contain p-1 transition',
                                    !entry.seen && 'opacity-35 grayscale',
                                )}
                            />
                        ) : (
                            <Car className="absolute inset-0 m-auto size-6 text-muted-foreground/40" />
                        )}
                        <span className="absolute top-1 left-1 rounded bg-background/85 px-1 text-[10px] font-semibold tabular-nums">
                            #{entry.number}
                        </span>
                        <StateBadge entry={entry} />
                        <FlagChips flags={entry.flags} className="absolute bottom-1 left-1" />
                    </div>
                    <p className="line-clamp-2 px-1.5 py-1 text-[11px] leading-tight">
                        {entry.name}
                    </p>
                </button>
            ))}
        </div>
    );
}

/** Icon + label, so the state never rides on color alone. */
function StateBadge({ entry }: { entry: Pick<DexEntry, 'seen' | 'caught' | 'wanted'> }) {
    const state = entry.caught
        ? { icon: Check, label: 'Dimiliki', className: 'bg-viz-bar text-white' }
        : entry.seen
          ? { icon: Eye, label: 'Dilihat', className: 'bg-viz-bar-soft text-white' }
          : entry.wanted
            ? { icon: Heart, label: 'Diincar', className: 'bg-background/85 text-foreground' }
            : null;

    if (!state) {
        return null;
    }

    const Icon = state.icon;

    return (
        <span
            className={cn(
                'absolute top-1 right-1 flex size-5 items-center justify-center rounded-full',
                state.className,
            )}
            title={state.label}
            aria-label={state.label}
        >
            <Icon className="size-3" />
        </span>
    );
}

function FlagChips({ flags, className }: { flags: DexFlags; className?: string }) {
    const chips = [
        flags.sth && { label: 'STH', className: 'bg-viz-sth text-white' },
        flags.th && !flags.sth && { label: 'TH', className: 'bg-viz-th text-white' },
    ].filter(Boolean) as { label: string; className: string }[];

    if (!chips.length) {
        return null;
    }

    return (
        <span className={cn('flex gap-0.5', className)}>
            {chips.map((chip) => (
                <span
                    key={chip.label}
                    className={cn('rounded px-1 text-[9px] font-bold', chip.className)}
                >
                    {chip.label}
                </span>
            ))}
        </span>
    );
}

function EntrySheet({
    entry,
    catalogName,
    custom,
    onClose,
}: {
    entry: DexEntry | null;
    catalogName: string;
    custom: boolean;
    onClose: () => void;
}) {
    const [variantId, setVariantId] = useState<number | null>(null);
    const variant =
        entry?.variants.find((v) => v.id === variantId) ?? entry?.variants[0];

    return (
        <Sheet
            open={entry !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                    setVariantId(null);
                }
            }}
        >
            <SheetContent className="w-full overflow-y-auto sm:max-w-md">
                {entry && variant && (
                    <>
                        <SheetHeader>
                            <SheetTitle className="font-heading">
                                #{entry.number} · {entry.name}
                            </SheetTitle>
                            <SheetDescription>
                                {catalogName}
                                {entry.series &&
                                    ` · ${entry.series}${entry.series_number ? ` ${entry.series_number}` : ''}`}
                            </SheetDescription>
                        </SheetHeader>

                        <div className="space-y-5 px-4 pb-6">
                            <div className="flex aspect-[4/3] items-center justify-center overflow-hidden rounded-lg bg-muted/40">
                                {variant.photo_url ? (
                                    <img
                                        src={variant.photo_url}
                                        alt={variant.name}
                                        className="size-full object-contain"
                                    />
                                ) : (
                                    <Car className="size-10 text-muted-foreground/40" />
                                )}
                            </div>

                            <div className="flex flex-wrap gap-1.5">
                                <Badge variant="secondary">
                                    {entry.caught
                                        ? 'Dimiliki'
                                        : entry.seen
                                          ? 'Dilihat'
                                          : entry.wanted
                                            ? 'Diincar'
                                            : 'Belum ditelusuri'}
                                </Badge>
                                {variant.flags.new && <Badge variant="outline">Model baru</Badge>}
                                {variant.flags.th && <Badge className="bg-viz-th text-white">Treasure Hunt</Badge>}
                                {variant.flags.sth && <Badge className="bg-viz-sth text-white">Super Treasure Hunt</Badge>}
                                {variant.flags.exclusive && (
                                    <Badge variant="outline">Eksklusif {variant.flags.exclusive}</Badge>
                                )}
                                {variant.toy_code && (
                                    <Badge variant="outline" className="font-mono">
                                        {variant.toy_code}
                                    </Badge>
                                )}
                            </div>

                            {entry.variants.length > 1 && (
                                <div className="space-y-2">
                                    <p className="text-sm font-medium">
                                        Varian ({entry.variants.length})
                                    </p>
                                    <div className="grid grid-cols-3 gap-2">
                                        {entry.variants.map((v) => (
                                            <button
                                                key={v.id}
                                                type="button"
                                                onClick={() => setVariantId(v.id)}
                                                aria-pressed={v.id === variant.id}
                                                className={cn(
                                                    'relative overflow-hidden rounded-md border text-left',
                                                    v.id === variant.id
                                                        ? 'border-brand ring-1 ring-brand'
                                                        : 'border-border',
                                                )}
                                            >
                                                <div className="relative aspect-[4/3] bg-muted/40">
                                                    {v.photo_url ? (
                                                        <img
                                                            src={v.photo_url}
                                                            alt=""
                                                            className={cn(
                                                                'size-full object-contain',
                                                                !v.seen && 'opacity-40 grayscale',
                                                            )}
                                                        />
                                                    ) : (
                                                        <Car className="absolute inset-0 m-auto size-5 text-muted-foreground/40" />
                                                    )}
                                                    <StateBadge entry={v} />
                                                </div>
                                                <p className="truncate px-1 py-0.5 text-[10px]">
                                                    {v.variant_label ?? 'Warna utama'}
                                                </p>
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}

                            <div className="space-y-2">
                                <p className="text-sm font-medium">
                                    Catat{' '}
                                    {entry.variants.length > 1 &&
                                        `(${variant.variant_label ?? 'warna utama'})`}
                                </p>
                                <div className="grid gap-2">
                                    <Button asChild>
                                        <Link href={DiecastController.create({ query: { dex_item: variant.id } })}>
                                            <Plus /> Tambah ke koleksi
                                        </Link>
                                    </Button>
                                    <div className="grid grid-cols-2 gap-2">
                                        <Button variant="outline" asChild>
                                            <Link
                                                href={DiecastController.create({
                                                    query: { dex_item: variant.id, status: 'spotted' },
                                                })}
                                            >
                                                <Eye /> Ditemui
                                            </Link>
                                        </Button>
                                        <Button variant="outline" asChild>
                                            <Link
                                                href={DiecastController.create({
                                                    query: { dex_item: variant.id, status: 'wishlist' },
                                                })}
                                            >
                                                <Heart /> Wishlist
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            </div>

                            {entry.diecasts.length > 0 && (
                                <div className="space-y-2">
                                    <p className="text-sm font-medium">Di koleksimu</p>
                                    <ul className="space-y-1.5">
                                        {entry.diecasts.map((d) => (
                                            <li key={d.id}>
                                                <Link
                                                    href={DiecastController.edit(d.id)}
                                                    className="flex items-center gap-2 rounded-md p-1 hover:bg-muted/60"
                                                >
                                                    {d.photo_url ? (
                                                        <img
                                                            src={d.photo_url}
                                                            alt=""
                                                            className="size-10 rounded object-cover"
                                                        />
                                                    ) : (
                                                        <span className="flex size-10 items-center justify-center rounded bg-muted">
                                                            <Car className="size-4 text-muted-foreground" />
                                                        </span>
                                                    )}
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block truncate text-sm">
                                                            {d.name}
                                                        </span>
                                                        <span className="block text-xs text-muted-foreground">
                                                            {STATUS_LABELS[d.status]}
                                                            {d.catalog_match === 'auto' &&
                                                                ' · dicocokkan otomatis'}
                                                        </span>
                                                    </span>
                                                </Link>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            {custom && (
                                <DeleteItemButton
                                    itemId={variant.id}
                                    onDeleted={() => {
                                        setVariantId(null);

                                        if (entry.variants.length === 1) {
                                            onClose();
                                        }
                                    }}
                                />
                            )}
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

DexShow.layout = {
    breadcrumbs: [{ title: 'Dex', href: DexController.index() }],
};
