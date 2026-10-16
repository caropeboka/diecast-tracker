import { Head, Link, router } from '@inertiajs/react';
import { Camera, ChevronLeft, ChevronRight, Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import DiecastController from '@/actions/App/Http/Controllers/DiecastController';
import DiecastCard from '@/components/diecast-card';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { RARITY_LABELS, STATUS_LABELS } from '@/types/diecast';
import type { Diecast, Paginated } from '@/types/diecast';

type Filters = {
    search?: string;
    brand?: string;
    status?: string;
    rarity?: string;
    tag?: string;
    missing?: string;
    sort?: string;
};

/** "Lengkapi" filters linked from the dashboard's data-completeness card. */
const MISSING_LABELS: Record<string, string> = {
    photo: 'Belum ada foto',
    purchase_price: 'Belum ada harga beli',
    market_value: 'Belum ada harga pasaran',
    storage_location: 'Belum ada lokasi simpan',
    tags: 'Belum ada tag',
};

const SORTS = {
    newest: 'Terbaru',
    oldest: 'Terlama',
    name: 'Nama A–Z',
    brand: 'Merek',
    year: 'Tahun rilis',
    price: 'Harga beli tertinggi',
    value: 'Nilai pasaran tertinggi',
};

const ALL = 'all';

export default function DiecastIndex({
    diecasts,
    filters,
    brands,
    tags,
}: {
    diecasts: Paginated<Diecast>;
    filters: Filters;
    brands: string[];
    tags: string[];
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const firstRender = useRef(true);

    function apply(next: Filters) {
        const query = Object.fromEntries(
            Object.entries({ ...filters, ...next }).filter(
                ([, value]) => value && value !== ALL,
            ),
        );

        router.get(DiecastController.index.url(), query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;

            return;
        }

        const timeout = setTimeout(() => apply({ search }), 350);

        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const filterSelect = (
        key: keyof Filters,
        placeholder: string,
        options: Record<string, string>,
    ) => (
        <Select
            value={filters[key] ?? ALL}
            onValueChange={(value) => apply({ [key]: value })}
        >
            <SelectTrigger className="w-full sm:w-auto sm:min-w-44">
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>{placeholder}</SelectItem>
                {Object.entries(options).map(([value, label]) => (
                    <SelectItem key={value} value={value}>
                        {label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );

    const hasFilters = Boolean(
        filters.search ||
            filters.brand ||
            filters.status ||
            filters.rarity ||
            filters.tag ||
            filters.missing,
    );

    return (
        <>
            <Head title="Koleksi" />
            <div className="space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Koleksi diecast"
                        description={`${diecasts.total} item tercatat`}
                    />
                    <Button asChild>
                        <Link href={DiecastController.create()}>
                            <Camera /> Tambah diecast
                        </Link>
                    </Button>
                </div>

                {filters.missing && MISSING_LABELS[filters.missing] && (
                    <div className="flex items-center gap-2 text-sm">
                        <span className="text-muted-foreground">
                            Menampilkan:
                        </span>
                        <button
                            type="button"
                            onClick={() => apply({ missing: undefined })}
                            className="inline-flex items-center gap-1 rounded-full border bg-muted px-3 py-1 hover:bg-muted/70"
                        >
                            {MISSING_LABELS[filters.missing]}
                            <X className="size-3.5" aria-label="Hapus filter" />
                        </button>
                    </div>
                )}

                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                    <div className="relative flex-1 sm:min-w-64">
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Cari nama, seri, warna, lokasi…"
                            className="pl-9"
                        />
                    </div>
                    {filterSelect(
                        'brand',
                        'Semua merek',
                        Object.fromEntries(brands.map((b) => [b, b])),
                    )}
                    {filterSelect('status', 'Semua kecuali ditemui', STATUS_LABELS)}
                    {filterSelect('rarity', 'Semua kelangkaan', RARITY_LABELS)}
                    {tags.length > 0 &&
                        filterSelect(
                            'tag',
                            'Semua tag',
                            Object.fromEntries(tags.map((t) => [t, t])),
                        )}
                    <Select
                        value={filters.sort ?? 'newest'}
                        onValueChange={(sort) => apply({ sort })}
                    >
                        <SelectTrigger className="w-full sm:w-auto sm:min-w-44">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {Object.entries(SORTS).map(([value, label]) => (
                                <SelectItem key={value} value={value}>
                                    {label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {diecasts.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed border-sidebar-border px-6 py-16 text-center">
                        <Camera className="size-10 text-muted-foreground/60" />
                        {hasFilters ? (
                            <>
                                <p className="font-medium">
                                    Tidak ada yang cocok
                                </p>
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        setSearch('');
                                        router.get(
                                            DiecastController.index.url(),
                                        );
                                    }}
                                >
                                    Reset filter
                                </Button>
                            </>
                        ) : (
                            <>
                                <p className="font-medium">
                                    Koleksimu masih kosong
                                </p>
                                <p className="max-w-sm text-sm text-muted-foreground">
                                    Foto diecast pertamamu — AI akan membaca
                                    nama, seri, dan nomornya otomatis.
                                </p>
                                <Button asChild>
                                    <Link href={DiecastController.create()}>
                                        <Camera /> Tambah diecast pertama
                                    </Link>
                                </Button>
                            </>
                        )}
                    </div>
                ) : (
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-6">
                        {diecasts.data.map((diecast) => (
                            <DiecastCard key={diecast.id} diecast={diecast} />
                        ))}
                    </div>
                )}

                {diecasts.last_page > 1 && (
                    <div className="flex items-center justify-between text-sm text-muted-foreground">
                        <span>
                            {diecasts.from}–{diecasts.to} dari {diecasts.total}
                        </span>
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={!diecasts.prev_page_url}
                                asChild={!!diecasts.prev_page_url}
                            >
                                {diecasts.prev_page_url ? (
                                    <Link
                                        href={diecasts.prev_page_url}
                                        preserveScroll={false}
                                    >
                                        <ChevronLeft /> Sebelumnya
                                    </Link>
                                ) : (
                                    <span>
                                        <ChevronLeft /> Sebelumnya
                                    </span>
                                )}
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={!diecasts.next_page_url}
                                asChild={!!diecasts.next_page_url}
                            >
                                {diecasts.next_page_url ? (
                                    <Link href={diecasts.next_page_url}>
                                        Berikutnya <ChevronRight />
                                    </Link>
                                ) : (
                                    <span>
                                        Berikutnya <ChevronRight />
                                    </span>
                                )}
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

DiecastIndex.layout = {
    breadcrumbs: [{ title: 'Koleksi', href: DiecastController.index() }],
};
