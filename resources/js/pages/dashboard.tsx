import { Head, Link } from '@inertiajs/react';
import {
    Camera,
    Car,
    Flame,
    Heart,
    Images,
    Layers,
    TrendingUp,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import BulkScanController from '@/actions/App/Http/Controllers/BulkScanController';
import DiecastController from '@/actions/App/Http/Controllers/DiecastController';
import DiecastCard from '@/components/diecast-card';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { formatRupiah } from '@/types/diecast';
import type { Diecast } from '@/types/diecast';

type Stats = {
    total_items: number;
    unique_items: number;
    total_spent: number;
    total_value: number;
    value_gain: number;
    valued_items: number;
    treasure_hunts: number;
    super_treasure_hunts: number;
    wishlist: number;
    pending_scans: number;
};

type Breakdown = { label: string; total: number | string }[];

function StatCard({
    icon: Icon,
    label,
    value,
    hint,
    hintClassName,
}: {
    icon: LucideIcon;
    label: string;
    value: string | number;
    hint?: string;
    hintClassName?: string;
}) {
    return (
        <div className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Icon className="size-4" />
                {label}
            </div>
            <p className="mt-2 text-2xl font-semibold tracking-tight tabular-nums">
                {value}
            </p>
            {hint && (
                <p className={cn('text-xs text-muted-foreground', hintClassName)}>
                    {hint}
                </p>
            )}
        </div>
    );
}

function BreakdownList({ title, rows }: { title: string; rows: Breakdown }) {
    const max = Math.max(1, ...rows.map((row) => Number(row.total)));

    return (
        <div className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <h3 className="mb-3 font-medium">{title}</h3>
            {rows.length === 0 ? (
                <p className="text-sm text-muted-foreground">Belum ada data.</p>
            ) : (
                <ul className="space-y-2.5">
                    {rows.map((row) => (
                        <li key={row.label} className="space-y-1">
                            <div className="flex justify-between text-sm">
                                <span>{row.label}</span>
                                <span className="text-muted-foreground tabular-nums">
                                    {row.total}
                                </span>
                            </div>
                            <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                <div
                                    className="h-full rounded-full bg-primary"
                                    style={{
                                        width: `${(Number(row.total) / max) * 100}%`,
                                    }}
                                />
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function MiniList({
    title,
    icon: Icon,
    items,
    empty,
    detail,
    href,
}: {
    title: string;
    icon: LucideIcon;
    items: Diecast[];
    empty: string;
    detail: (d: Diecast) => React.ReactNode;
    href: string | { url: string; method: 'get' };
}) {
    return (
        <div className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <div className="mb-3 flex items-center justify-between">
                <h3 className="flex items-center gap-2 font-medium">
                    <Icon className="size-4" /> {title}
                </h3>
                <Link
                    href={href}
                    className="text-sm text-muted-foreground hover:text-foreground"
                >
                    Semua →
                </Link>
            </div>
            {items.length === 0 ? (
                <p className="text-sm text-muted-foreground">{empty}</p>
            ) : (
                <ul className="space-y-2">
                    {items.map((d) => (
                        <li key={d.id}>
                            <Link
                                href={DiecastController.edit(d.id)}
                                className="flex items-center gap-3 rounded-md hover:bg-muted/50"
                            >
                                {d.photo_url ? (
                                    <img
                                        src={d.photo_url}
                                        alt=""
                                        className="size-10 shrink-0 rounded object-cover"
                                    />
                                ) : (
                                    <div className="flex size-10 shrink-0 items-center justify-center rounded bg-muted">
                                        <Car className="size-4 text-muted-foreground" />
                                    </div>
                                )}
                                <span className="min-w-0 flex-1 truncate text-sm">
                                    {d.name}
                                </span>
                                <span className="shrink-0 text-sm tabular-nums">
                                    {detail(d)}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

export default function Dashboard({
    stats,
    byBrand,
    byScale,
    topGainers,
    wishlist,
    recent,
}: {
    stats: Stats;
    byBrand: Breakdown;
    byScale: Breakdown;
    topGainers: Diecast[];
    wishlist: Diecast[];
    recent: Diecast[];
}) {
    const gainPositive = stats.value_gain >= 0;

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">
                            Garasi kamu
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Ringkasan koleksi diecast.
                        </p>
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
                        <Images className="size-4 text-amber-600" />
                        <span>
                            <strong>{stats.pending_scans} foto</strong> di
                            antrian scan massal belum disimpan.
                        </span>
                        <span className="ml-auto">Lanjutkan →</span>
                    </Link>
                )}

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <StatCard
                        icon={Car}
                        label="Total diecast"
                        value={stats.total_items}
                        hint={`${stats.unique_items} model berbeda`}
                    />
                    <StatCard
                        icon={Wallet}
                        label="Total modal"
                        value={formatRupiah(stats.total_spent)}
                    />
                    <StatCard
                        icon={TrendingUp}
                        label="Nilai pasaran"
                        value={
                            stats.valued_items
                                ? formatRupiah(stats.total_value)
                                : '—'
                        }
                        hint={
                            stats.valued_items
                                ? `${gainPositive ? '+' : ''}${formatRupiah(stats.value_gain)} dari harga beli · ${stats.valued_items} item ada nilainya`
                                : 'Isi "harga pasaran" di diecast untuk melihat nilai koleksi'
                        }
                        hintClassName={
                            stats.valued_items
                                ? gainPositive
                                    ? 'text-emerald-600 dark:text-emerald-400'
                                    : 'text-rose-600 dark:text-rose-400'
                                : undefined
                        }
                    />
                    <StatCard
                        icon={Flame}
                        label="Treasure Hunt"
                        value={stats.treasure_hunts}
                        hint={`${stats.super_treasure_hunts} Super TH`}
                    />
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <MiniList
                        title="Harga paling naik"
                        icon={TrendingUp}
                        items={topGainers}
                        empty="Isi harga beli dan harga pasaran untuk melihat diecast yang nilainya naik."
                        href={DiecastController.index({
                            query: { sort: 'value' },
                        })}
                        detail={(d) => (
                            <span className="text-emerald-600 dark:text-emerald-400">
                                +
                                {formatRupiah(
                                    Number(d.market_value) -
                                        Number(d.purchase_price),
                                )}
                            </span>
                        )}
                    />
                    <MiniList
                        title={`Wishlist (${stats.wishlist})`}
                        icon={Heart}
                        items={wishlist}
                        empty="Belum ada wishlist. Tambahkan diecast buruanmu beserta target harganya."
                        href={DiecastController.index({
                            query: { status: 'wishlist' },
                        })}
                        detail={(d) =>
                            d.target_price ? (
                                <span className="text-muted-foreground">
                                    ≤ {formatRupiah(d.target_price)}
                                </span>
                            ) : null
                        }
                    />
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <BreakdownList title="Per merek" rows={byBrand} />
                    <BreakdownList title="Per skala" rows={byScale} />
                </div>

                <div className="space-y-3">
                    <div className="flex items-center justify-between">
                        <h3 className="flex items-center gap-2 font-medium">
                            <Layers className="size-4" /> Baru ditambahkan
                        </h3>
                        <Link
                            href={DiecastController.index()}
                            className="text-sm text-muted-foreground hover:text-foreground"
                        >
                            Lihat semua →
                        </Link>
                    </div>
                    {recent.length === 0 ? (
                        <div className="rounded-xl border border-dashed border-sidebar-border p-8 text-center text-sm text-muted-foreground">
                            Belum ada diecast. Mulai dengan memfoto satu!
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                            {recent.map((diecast) => (
                                <DiecastCard key={diecast.id} diecast={diecast} />
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </>
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
