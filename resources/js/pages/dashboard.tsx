import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowDownRight,
    ArrowRight,
    ArrowUpRight,
    Calendar,
    Camera,
    Car,
    CircleCheck,
    ClipboardList,
    Factory,
    Flame,
    Heart,
    Images,
    Layers,
    Sparkles,
    Tag,
    Trophy,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';
import BulkScanController from '@/actions/App/Http/Controllers/BulkScanController';
import DiecastController from '@/actions/App/Http/Controllers/DiecastController';
import {
    BarList,
    ColumnChart,
    Meter,
    Panel,
    StatTile,
} from '@/components/dashboard/charts';
import DiecastCard from '@/components/diecast-card';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { Auth } from '@/types';
import {
    formatRupiah,
    formatRupiahCompact,
    RARITY_LABELS,
} from '@/types/diecast';
import type { Diecast, Rarity } from '@/types/diecast';

type Stats = {
    total_items: number;
    unique_items: number;
    total_spent: number;
    priced_items: number;
    total_value: number;
    valued_items: number;
    value_gain: number;
    gain_items: number;
    special_items: number;
    super_treasure_hunts: number;
    wishlist: number;
    brands: number;
    pending_scans: number;
};

type Count = { label: string | number | null; total: number | string };

type Completeness = { key: string; label: string; done: number }[];

/** Fixed identity colors; this order passed the colorblind-safety checks. */
const RARITY_ORDER: Rarity[] = [
    'treasure_hunt',
    'super_treasure_hunt',
    'chase',
    'exclusive',
    'limited',
    'regular',
];

const RARITY_COLOR: Record<Rarity, string> = {
    treasure_hunt: 'bg-viz-th',
    super_treasure_hunt: 'bg-viz-sth',
    chase: 'bg-viz-chase',
    exclusive: 'bg-viz-exclusive',
    limited: 'bg-viz-limited',
    regular: 'bg-viz-muted',
};

const COMPLETENESS_ICON: Record<string, typeof Camera> = {
    photo: Camera,
    purchase_price: Wallet,
    market_value: Sparkles,
    storage_location: Layers,
    tags: Tag,
};

function greeting(): string {
    const hour = new Date().getHours();

    if (hour < 11) {
        return 'Selamat pagi';
    }

    if (hour < 15) {
        return 'Selamat siang';
    }

    return hour < 19 ? 'Selamat sore' : 'Selamat malam';
}

export default function Dashboard({
    stats,
    completeness,
    byBrand,
    byMake,
    byRarity,
    byYear,
    byScale,
    mostValuable,
    topGainers,
    showcase,
    wishlist,
    recent,
}: {
    stats: Stats;
    completeness: Completeness;
    byBrand: (Count & { value: number | string })[];
    byMake: Count[];
    byRarity: Count[];
    byYear: Count[];
    byScale: Count[];
    mostValuable: Diecast[];
    topGainers: Diecast[];
    showcase: Diecast[];
    wishlist: Diecast[];
    recent: Diecast[];
}) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const [brandMeasure, setBrandMeasure] = useState<'count' | 'value'>('count');

    const empty = stats.unique_items === 0;
    const spentBase = stats.total_value - stats.value_gain;
    const gainPercent =
        stats.gain_items && spentBase > 0
            ? Math.round((stats.value_gain / spentBase) * 100)
            : null;

    const knownYears = byYear.filter((y) => y.label !== null);
    const unknownYears = Number(
        byYear.find((y) => y.label === null)?.total ?? 0,
    );
    const yearColumns = (() => {
        if (knownYears.length < 2) {
            return [];
        }

        const counts = new Map(
            knownYears.map((y) => [Number(y.label), Number(y.total)]),
        );
        const first = Number(knownYears[0].label);
        const last = Number(knownYears[knownYears.length - 1].label);

        // Every year in the range, so gaps show; past 25 years, bucket by 5.
        const step = last - first > 25 ? 5 : 1;
        const columns = [];

        for (let start = first - (first % step); start <= last; start += step) {
            let total = 0;

            for (let y = start; y < start + step; y++) {
                total += counts.get(y) ?? 0;
            }

            const label = step === 1 ? String(start) : `${start}an`;
            columns.push({
                key: String(start),
                label: step === 1 ? `'${String(start).slice(2)}` : label,
                value: total,
                tooltip: `${label}: ${total} diecast`,
            });
        }

        return columns;
    })();

    const rarityRows = RARITY_ORDER.map((rarity) => ({
        rarity,
        total: Number(byRarity.find((r) => r.label === rarity)?.total ?? 0),
    })).filter((r) => r.total > 0);
    const specialRarities = rarityRows.filter((r) => r.rarity !== 'regular');

    const brandRows = [...byBrand]
        .sort((a, b) =>
            brandMeasure === 'count'
                ? Number(b.total) - Number(a.total)
                : Number(b.value) - Number(a.value),
        )
        .slice(0, 8)
        .map((b) => ({
            key: String(b.label),
            label: String(b.label),
            value: brandMeasure === 'count' ? Number(b.total) : Number(b.value),
            display:
                brandMeasure === 'count'
                    ? `${b.total} pcs`
                    : formatRupiahCompact(b.value),
            detail:
                brandMeasure === 'count'
                    ? `${Math.round((Number(b.total) / Math.max(1, stats.total_items)) * 100)}% dari koleksi · nilai ${formatRupiah(b.value)}`
                    : `${b.total} pcs`,
            href: DiecastController.index({
                query: { brand: String(b.label) },
            }).url,
        }));

    const maxValuable = Number(mostValuable[0]?.market_value ?? 1);

    // Always a full grid: 6 → 3×2, 4–5 → 2×2, 3 → one tall + two, 1–2 → side by side.
    const collage = (() => {
        const n = showcase.length;

        if (n >= 6) {
            return { items: showcase.slice(0, 6), columns: 'grid-cols-3' };
        }

        if (n >= 4) {
            return { items: showcase.slice(0, 4), columns: 'grid-cols-2' };
        }

        return {
            items: showcase,
            columns: n === 1 ? 'grid-cols-1' : 'grid-cols-2',
        };
    })();

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                {/* Header */}
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="text-sm text-muted-foreground">
                            {greeting()}, {auth.user.name.split(' ')[0]} 👋
                        </p>
                        <h1 className="text-2xl font-semibold tracking-tight">
                            Garasi kamu
                        </h1>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href={BulkScanController.index()}>
                                <Images /> Scan massal
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href={DiecastController.create()}>
                                <Camera /> Tambah diecast
                            </Link>
                        </Button>
                    </div>
                </div>

                {stats.pending_scans > 0 && (
                    <Link
                        href={BulkScanController.index()}
                        className="flex items-center gap-3 rounded-xl border border-amber-400/50 bg-amber-50 p-3 text-sm hover:bg-amber-100 dark:bg-amber-500/10 dark:hover:bg-amber-500/20"
                    >
                        <Images className="size-4 shrink-0 text-amber-600" />
                        <span>
                            <strong>{stats.pending_scans} foto</strong> di
                            antrian scan massal belum disimpan.
                        </span>
                        <span className="ml-auto shrink-0">Lanjutkan →</span>
                    </Link>
                )}

                {empty ? (
                    <EmptyGarage />
                ) : (
                    <>
                        {/* Hero: collection value + showcase */}
                        <section className="grid grid-cols-1 overflow-hidden rounded-2xl border border-sidebar-border/70 bg-card lg:grid-cols-[1.5fr_1fr] dark:border-sidebar-border">
                            <div className="flex flex-col justify-center gap-3 p-6 md:p-8">
                                <p className="text-sm font-medium text-muted-foreground">
                                    Nilai koleksi
                                </p>
                                <p className="text-5xl font-semibold tracking-tight md:text-6xl">
                                    {stats.valued_items
                                        ? formatRupiah(stats.total_value)
                                        : '—'}
                                </p>
                                {stats.gain_items > 0 ? (
                                    <p
                                        className={cn(
                                            'flex items-center gap-1 text-sm font-medium',
                                            stats.value_gain >= 0
                                                ? 'text-viz-up'
                                                : 'text-viz-down',
                                        )}
                                    >
                                        {stats.value_gain >= 0 ? (
                                            <ArrowUpRight className="size-4" />
                                        ) : (
                                            <ArrowDownRight className="size-4" />
                                        )}
                                        {stats.value_gain >= 0 ? '+' : '−'}
                                        {formatRupiah(Math.abs(stats.value_gain))}
                                        {gainPercent !== null &&
                                            ` (${gainPercent >= 0 ? '+' : ''}${gainPercent}%)`}{' '}
                                        <span className="font-normal text-muted-foreground">
                                            dibanding harga beli
                                        </span>
                                    </p>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        Isi harga beli untuk melihat untung /
                                        rugi koleksimu.{' '}
                                        <Link
                                            href={DiecastController.index({
                                                query: {
                                                    missing: 'purchase_price',
                                                },
                                            })}
                                            className="font-medium text-foreground underline underline-offset-4"
                                        >
                                            Lengkapi
                                        </Link>
                                    </p>
                                )}
                                <dl className="mt-3 grid grid-cols-3 divide-x divide-border border-t border-border pt-4">
                                    {[
                                        [
                                            'Rata-rata / diecast',
                                            stats.valued_items
                                                ? formatRupiahCompact(
                                                      stats.total_value /
                                                          stats.valued_items,
                                                  )
                                                : '—',
                                        ],
                                        [
                                            'Termahal',
                                            mostValuable[0]
                                                ? formatRupiahCompact(
                                                      mostValuable[0]
                                                          .market_value,
                                                  )
                                                : '—',
                                        ],
                                        ['Merek', String(stats.brands)],
                                    ].map(([label, value], index) => (
                                        <div
                                            key={label}
                                            className={cn(
                                                'min-w-0',
                                                index > 0 && 'pl-3 md:pl-4',
                                            )}
                                        >
                                            <dt className="truncate text-xs text-muted-foreground">
                                                {label}
                                            </dt>
                                            <dd className="mt-0.5 text-lg font-semibold tracking-tight">
                                                {value}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                                <p className="text-xs text-muted-foreground">
                                    Dihitung dari {stats.valued_items} dari{' '}
                                    {stats.unique_items} diecast yang sudah
                                    punya harga pasaran.
                                </p>
                            </div>

                            {showcase.length > 0 && (
                                <div
                                    className={cn(
                                        'grid gap-1 bg-muted/40 p-1',
                                        collage.columns,
                                    )}
                                >
                                    {collage.items.map((d, index) => (
                                        <Link
                                            key={d.id}
                                            href={DiecastController.edit(d.id)}
                                            className={cn(
                                                'group relative overflow-hidden rounded-lg bg-muted',
                                                collage.items.length === 3 &&
                                                    index === 0
                                                    ? 'row-span-2'
                                                    : 'aspect-square',
                                            )}
                                        >
                                            <img
                                                src={d.photo_url ?? ''}
                                                alt={d.name}
                                                className="size-full object-cover transition duration-300 group-hover:scale-105"
                                            />
                                            <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent p-2 opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100">
                                                <p className="truncate text-xs font-medium text-white">
                                                    {d.name}
                                                </p>
                                                {d.market_value && (
                                                    <p className="text-xs text-white/80">
                                                        {formatRupiah(
                                                            d.market_value,
                                                        )}
                                                    </p>
                                                )}
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            )}
                        </section>

                        {/* KPI row */}
                        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                            <StatTile
                                icon={<Car className="size-4" />}
                                label="Total diecast"
                                value={`${stats.total_items} pcs`}
                                hint={`${stats.unique_items} model berbeda`}
                                href={DiecastController.index().url}
                            />
                            <StatTile
                                icon={<Wallet className="size-4" />}
                                label="Total modal"
                                value={
                                    stats.priced_items
                                        ? formatRupiah(stats.total_spent)
                                        : '—'
                                }
                                hint={`${stats.priced_items} dari ${stats.unique_items} ada harga beli`}
                            />
                            <StatTile
                                icon={<Flame className="size-4" />}
                                label="Langka"
                                value={stats.special_items}
                                hint={
                                    stats.special_items
                                        ? `${stats.super_treasure_hunts} Super Treasure Hunt`
                                        : 'Belum ada TH / STH / Chase'
                                }
                            />
                            <StatTile
                                icon={<Heart className="size-4" />}
                                label="Wishlist"
                                value={stats.wishlist}
                                hint={
                                    stats.wishlist
                                        ? 'diecast sedang diburu'
                                        : 'Tambahkan diecast buruanmu'
                                }
                                href={
                                    DiecastController.index({
                                        query: { status: 'wishlist' },
                                    }).url
                                }
                            />
                        </div>

                        {/* Most valuable + data completeness */}
                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                            <Panel
                                title="Paling berharga"
                                icon={<Trophy className="size-4" />}
                                action={
                                    <Link
                                        href={DiecastController.index({
                                            query: { sort: 'value' },
                                        })}
                                        className="text-sm text-muted-foreground hover:text-foreground"
                                    >
                                        Semua →
                                    </Link>
                                }
                            >
                                {mostValuable.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Belum ada harga pasaran. Buka diecast →
                                        "Cek harga pasaran" untuk menghitungnya
                                        dari screenshot Tokopedia.
                                    </p>
                                ) : (
                                    <ol className="space-y-3">
                                        {mostValuable.map((d, index) => (
                                            <li key={d.id}>
                                                <Link
                                                    href={DiecastController.edit(
                                                        d.id,
                                                    )}
                                                    className="group flex items-center gap-3"
                                                >
                                                    <span className="w-4 shrink-0 text-center text-sm font-semibold text-muted-foreground">
                                                        {index + 1}
                                                    </span>
                                                    <Thumb diecast={d} />
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex items-baseline justify-between gap-2">
                                                            <span className="truncate text-sm font-medium group-hover:underline">
                                                                {d.name}
                                                            </span>
                                                            <span className="shrink-0 text-sm font-semibold">
                                                                {formatRupiah(
                                                                    d.market_value,
                                                                )}
                                                            </span>
                                                        </div>
                                                        <p className="truncate text-xs text-muted-foreground">
                                                            {[
                                                                d.brand,
                                                                d.series,
                                                            ]
                                                                .filter(Boolean)
                                                                .join(' · ')}
                                                        </p>
                                                        <div className="mt-1 h-1.5">
                                                            <div
                                                                className="h-full rounded-r-[4px] bg-viz-bar"
                                                                style={{
                                                                    width: `${(Number(d.market_value) / maxValuable) * 100}%`,
                                                                }}
                                                            />
                                                        </div>
                                                    </div>
                                                </Link>
                                            </li>
                                        ))}
                                    </ol>
                                )}
                            </Panel>

                            <Panel
                                title="Kelengkapan data"
                                icon={<ClipboardList className="size-4" />}
                            >
                                <ul className="space-y-3.5">
                                    {completeness.map((item) => {
                                        const Icon =
                                            COMPLETENESS_ICON[item.key] ?? Car;
                                        const complete =
                                            item.done >= stats.unique_items;

                                        return (
                                            <li key={item.key}>
                                                <div className="mb-1.5 flex items-center gap-2 text-sm">
                                                    <Icon className="size-4 text-muted-foreground" />
                                                    <span className="flex-1">
                                                        {item.label}
                                                    </span>
                                                    <span className="text-muted-foreground tabular-nums">
                                                        {item.done}/
                                                        {stats.unique_items}
                                                    </span>
                                                    {complete ? (
                                                        <CircleCheck
                                                            className="size-4 text-viz-up"
                                                            aria-label="Lengkap"
                                                        />
                                                    ) : (
                                                        <Link
                                                            href={DiecastController.index(
                                                                {
                                                                    query: {
                                                                        missing:
                                                                            item.key,
                                                                    },
                                                                },
                                                            )}
                                                            className="text-xs font-medium underline-offset-4 hover:underline"
                                                        >
                                                            Lengkapi →
                                                        </Link>
                                                    )}
                                                </div>
                                                <Meter
                                                    value={item.done}
                                                    total={stats.unique_items}
                                                />
                                            </li>
                                        );
                                    })}
                                </ul>
                            </Panel>
                        </div>

                        {/* Breakdowns */}
                        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                            <Panel
                                title="Per merek"
                                icon={<Car className="size-4" />}
                                action={
                                    <div
                                        className="flex rounded-md border p-0.5 text-xs"
                                        role="group"
                                        aria-label="Ukuran"
                                    >
                                        {(
                                            [
                                                ['count', 'Jumlah'],
                                                ['value', 'Nilai'],
                                            ] as const
                                        ).map(([key, label]) => (
                                            <button
                                                key={key}
                                                type="button"
                                                onClick={() =>
                                                    setBrandMeasure(key)
                                                }
                                                aria-pressed={
                                                    brandMeasure === key
                                                }
                                                className={cn(
                                                    'rounded px-2 py-1',
                                                    brandMeasure === key
                                                        ? 'bg-muted font-medium'
                                                        : 'text-muted-foreground hover:text-foreground',
                                                )}
                                            >
                                                {label}
                                            </button>
                                        ))}
                                    </div>
                                }
                            >
                                <BarList rows={brandRows} />
                            </Panel>

                            <Panel
                                title="Kelangkaan"
                                icon={<Flame className="size-4" />}
                            >
                                {specialRarities.length === 0 ? (
                                    <div className="flex h-full flex-col items-center justify-center gap-2 py-6 text-center">
                                        <Flame className="size-8 text-muted-foreground/50" />
                                        <p className="text-sm font-medium">
                                            Semua masih regular
                                        </p>
                                        <p className="max-w-xs text-sm text-muted-foreground">
                                            Belum ada Treasure Hunt, Super TH,
                                            atau Chase. Semangat berburu! 🔥
                                        </p>
                                    </div>
                                ) : (
                                    <BarList
                                        rows={rarityRows.map((r) => ({
                                            key: r.rarity,
                                            label: RARITY_LABELS[r.rarity],
                                            value: r.total,
                                            display: `${r.total} pcs`,
                                            colorClass: RARITY_COLOR[r.rarity],
                                            href: DiecastController.index({
                                                query: { rarity: r.rarity },
                                            }).url,
                                        }))}
                                    />
                                )}
                            </Panel>

                            {byMake.length > 0 && (
                                <Panel
                                    title="Pabrikan mobil"
                                    icon={<Factory className="size-4" />}
                                >
                                    <BarList
                                        rows={byMake.map((m) => ({
                                            key: String(m.label),
                                            label: String(m.label),
                                            value: Number(m.total),
                                            display: `${m.total} pcs`,
                                            href: DiecastController.index({
                                                query: {
                                                    search: String(m.label),
                                                },
                                            }).url,
                                        }))}
                                    />
                                </Panel>
                            )}

                            {yearColumns.length > 0 && (
                                <Panel
                                    title="Tahun rilis"
                                    icon={<Calendar className="size-4" />}
                                >
                                    <ColumnChart columns={yearColumns} />
                                    {unknownYears > 0 && (
                                        <p className="mt-2 text-xs text-muted-foreground">
                                            {unknownYears} diecast belum ada
                                            tahun rilisnya.
                                        </p>
                                    )}
                                </Panel>
                            )}

                            {byScale.length > 1 && (
                                <Panel
                                    title="Skala"
                                    icon={<Layers className="size-4" />}
                                >
                                    <BarList
                                        rows={byScale.map((s) => ({
                                            key: String(s.label),
                                            label: String(s.label),
                                            value: Number(s.total),
                                            display: `${s.total} pcs`,
                                        }))}
                                    />
                                </Panel>
                            )}
                        </div>

                        {/* Gainers + wishlist */}
                        {(topGainers.length > 0 || wishlist.length > 0) && (
                            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                {topGainers.length > 0 && (
                                    <Panel
                                        title="Harga paling naik"
                                        icon={<ArrowUpRight className="size-4" />}
                                    >
                                        <MiniList
                                            items={topGainers}
                                            detail={(d) => (
                                                <span className="flex items-center gap-0.5 text-viz-up">
                                                    <ArrowUpRight className="size-3.5" />
                                                    {formatRupiah(
                                                        Number(d.market_value) -
                                                            Number(
                                                                d.purchase_price,
                                                            ),
                                                    )}
                                                </span>
                                            )}
                                        />
                                    </Panel>
                                )}
                                {wishlist.length > 0 && (
                                    <Panel
                                        title={`Wishlist (${stats.wishlist})`}
                                        icon={<Heart className="size-4" />}
                                        action={
                                            <Link
                                                href={DiecastController.index({
                                                    query: {
                                                        status: 'wishlist',
                                                    },
                                                })}
                                                className="text-sm text-muted-foreground hover:text-foreground"
                                            >
                                                Semua →
                                            </Link>
                                        }
                                    >
                                        <MiniList
                                            items={wishlist}
                                            detail={(d) =>
                                                d.target_price ? (
                                                    <span className="text-muted-foreground">
                                                        ≤{' '}
                                                        {formatRupiah(
                                                            d.target_price,
                                                        )}
                                                    </span>
                                                ) : null
                                            }
                                        />
                                    </Panel>
                                )}
                            </div>
                        )}

                        {/* Recent */}
                        <section className="space-y-3">
                            <div className="flex items-center justify-between">
                                <h3 className="flex items-center gap-2 font-medium">
                                    <Layers className="size-4" /> Baru
                                    ditambahkan
                                </h3>
                                <Link
                                    href={DiecastController.index()}
                                    className="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                                >
                                    Lihat semua <ArrowRight className="size-3.5" />
                                </Link>
                            </div>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                                {recent.map((diecast) => (
                                    <DiecastCard
                                        key={diecast.id}
                                        diecast={diecast}
                                    />
                                ))}
                            </div>
                        </section>
                    </>
                )}
            </div>
        </>
    );
}

function Thumb({ diecast }: { diecast: Diecast }) {
    return diecast.photo_url ? (
        <img
            src={diecast.photo_url}
            alt=""
            className="size-11 shrink-0 rounded-md object-cover"
        />
    ) : (
        <div className="flex size-11 shrink-0 items-center justify-center rounded-md bg-muted">
            <Car className="size-4 text-muted-foreground" />
        </div>
    );
}

function MiniList({
    items,
    detail,
}: {
    items: Diecast[];
    detail: (d: Diecast) => React.ReactNode;
}) {
    return (
        <ul className="space-y-2">
            {items.map((d) => (
                <li key={d.id}>
                    <Link
                        href={DiecastController.edit(d.id)}
                        className="flex items-center gap-3 rounded-md hover:bg-muted/50"
                    >
                        <Thumb diecast={d} />
                        <span className="min-w-0 flex-1 truncate text-sm">
                            {d.name}
                        </span>
                        <span className="shrink-0 text-sm">{detail(d)}</span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}

function EmptyGarage() {
    return (
        <section className="flex flex-col items-center gap-4 rounded-2xl border-2 border-dashed border-sidebar-border px-6 py-16 text-center">
            <div className="flex size-16 items-center justify-center rounded-full bg-muted">
                <Car className="size-8 text-muted-foreground" />
            </div>
            <div className="space-y-1">
                <p className="text-lg font-semibold">Garasimu masih kosong</p>
                <p className="max-w-sm text-sm text-muted-foreground">
                    Foto diecast pertamamu — AI akan membaca nama, seri, dan
                    nomornya otomatis.
                </p>
            </div>
            <div className="flex flex-wrap justify-center gap-2">
                <Button asChild>
                    <Link href={DiecastController.create()}>
                        <Camera /> Tambah diecast
                    </Link>
                </Button>
                <Button variant="outline" asChild>
                    <Link href={BulkScanController.index()}>
                        <Images /> Scan massal
                    </Link>
                </Button>
            </div>
        </section>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
