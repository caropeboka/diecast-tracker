import {
    CircleAlert,
    CircleCheck,
    CircleMinus,
    ExternalLink,
    ImagePlus,
    Loader2,
    Search,
    Sparkles,
    Trash2,
    X,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import PriceCheckController from '@/actions/App/Http/Controllers/PriceCheckController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { errorMessage, postJson, requestJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { formatRupiah } from '@/types/diecast';
import type { Diecast, PriceCheck } from '@/types/diecast';

const MAX_SCREENSHOTS = 6;

const ACCURACY = {
    high: {
        label: 'Akurasi tinggi',
        className: 'bg-emerald-600 text-white',
    },
    medium: { label: 'Akurasi sedang', className: 'bg-sky-600 text-white' },
    low: { label: 'Akurasi rendah', className: 'bg-amber-500 text-white' },
    insufficient: {
        label: 'Data kurang',
        className: 'bg-rose-600 text-white',
    },
};

const formatDate = (iso: string) =>
    new Date(iso).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

/** Search keywords that usually find the right listings on Tokopedia/Shopee. */
function searchQuery(diecast: Diecast): string {
    const rarity =
        diecast.rarity === 'super_treasure_hunt'
            ? 'super treasure hunt'
            : diecast.rarity === 'treasure_hunt'
              ? 'treasure hunt'
              : diecast.rarity === 'chase'
                ? 'chase'
                : '';

    return [
        diecast.brand,
        diecast.name.replace(/['"]/g, ''),
        rarity,
        diecast.packaging === 'loose' ? 'loose' : '',
    ]
        .filter(Boolean)
        .join(' ');
}

/**
 * Market price from marketplace screenshots: search on Tokopedia/Shopee, upload screenshots,
 * the AI reads every listing and the app computes the median.
 */
export default function PriceCheckPanel({
    diecast,
    initialChecks,
    onApplied,
}: {
    diecast: Diecast;
    initialChecks: PriceCheck[];
    onApplied: (value: number) => void;
}) {
    const [checks, setChecks] = useState(initialChecks);
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState(() => searchQuery(diecast));
    const [files, setFiles] = useState<{ file: File; url: string }[]>([]);
    const [analyzing, setAnalyzing] = useState(false);
    const [applyingId, setApplyingId] = useState<number | null>(null);
    const [showOlder, setShowOlder] = useState(false);
    const input = useRef<HTMLInputElement>(null);

    function addFiles(list: FileList | null) {
        const images = Array.from(list ?? [])
            .filter((f) => f.type.startsWith('image/'))
            .slice(0, MAX_SCREENSHOTS - files.length)
            .map((file) => ({ file, url: URL.createObjectURL(file) }));

        setFiles((current) => [...current, ...images]);
    }

    async function analyze() {
        const body = new FormData();
        files.forEach(({ file }) => body.append('screenshots[]', file));
        setAnalyzing(true);

        try {
            const check = await postJson<PriceCheck>(
                PriceCheckController.store.url(diecast.id),
                body,
            );
            setChecks((current) => [check, ...current]);
            setFiles([]);
            setOpen(false);
        } catch (error) {
            toast.error(errorMessage(error));
        }

        setAnalyzing(false);
    }

    async function apply(check: PriceCheck) {
        setApplyingId(check.id);

        try {
            const result = await postJson<{ market_value: string }>(
                PriceCheckController.apply.url(check.id),
                {},
            );
            const value = Math.round(Number(result.market_value));
            onApplied(value);
            setChecks((current) =>
                current.map((c) =>
                    c.id === check.id
                        ? { ...c, applied_at: new Date().toISOString() }
                        : c,
                ),
            );
            toast.success(`Harga pasaran diset ${formatRupiah(value)}.`);
        } catch (error) {
            toast.error(errorMessage(error));
        }

        setApplyingId(null);
    }

    async function remove(check: PriceCheck) {
        try {
            await requestJson(
                'DELETE',
                PriceCheckController.destroy.url(check.id),
            );
            setChecks((current) => current.filter((c) => c.id !== check.id));
        } catch (error) {
            toast.error(errorMessage(error));
        }
    }

    const [latest, ...older] = checks;
    const q = encodeURIComponent(query);

    return (
        <section className="space-y-4 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="font-medium">Cek harga pasaran</h3>
                {!open && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => setOpen(true)}
                    >
                        <Search /> {checks.length ? 'Cek ulang' : 'Cek harga'}
                    </Button>
                )}
            </div>

            {!open && !latest && (
                <p className="text-sm text-muted-foreground">
                    Cari diecast ini di Tokopedia/Shopee, screenshot hasilnya,
                    lalu AI akan membaca semua listing dan menghitung harga
                    pasarannya.
                </p>
            )}

            {open && (
                <div className="space-y-4 rounded-lg bg-muted/40 p-3">
                    <div className="space-y-2">
                        <p className="text-sm font-medium">
                            1. Cari di marketplace
                        </p>
                        <Input
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            onKeyDown={(e) => {
                                // Don't submit the diecast form.
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                }
                            }}
                            aria-label="Kata kunci pencarian"
                        />
                        <div className="flex flex-wrap gap-2">
                            <Button type="button" size="sm" variant="secondary" asChild>
                                <a
                                    href={`https://www.tokopedia.com/search?st=product&q=${q}`}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Tokopedia <ExternalLink />
                                </a>
                            </Button>
                            <Button type="button" size="sm" variant="secondary" asChild>
                                <a
                                    href={`https://shopee.co.id/search?keyword=${q}`}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    Shopee <ExternalLink />
                                </a>
                            </Button>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Tips: urutkan "Terlaris", lalu screenshot 2–4
                            layar sambil scroll ke bawah.
                        </p>
                    </div>

                    <div className="space-y-2">
                        <p className="text-sm font-medium">
                            2. Upload screenshot ({files.length}/
                            {MAX_SCREENSHOTS})
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {files.map(({ url }, index) => (
                                <div
                                    key={url}
                                    className="relative h-24 w-14 overflow-hidden rounded border"
                                >
                                    <img
                                        src={url}
                                        alt=""
                                        className="size-full object-cover object-top"
                                    />
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setFiles((current) =>
                                                current.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                        className="absolute top-0.5 right-0.5 rounded-full bg-black/60 p-0.5 text-white"
                                        aria-label="Hapus screenshot"
                                    >
                                        <X className="size-3" />
                                    </button>
                                </div>
                            ))}
                            {files.length < MAX_SCREENSHOTS && (
                                <button
                                    type="button"
                                    onClick={() => input.current?.click()}
                                    className="flex h-24 w-14 flex-col items-center justify-center gap-1 rounded border-2 border-dashed text-xs text-muted-foreground hover:border-primary hover:text-foreground"
                                >
                                    <ImagePlus className="size-5" />
                                    Tambah
                                </button>
                            )}
                        </div>
                        <input
                            ref={input}
                            type="file"
                            accept="image/*"
                            multiple
                            className="hidden"
                            onChange={(e) => {
                                addFiles(e.target.files);
                                e.target.value = '';
                            }}
                        />
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            onClick={() => void analyze()}
                            disabled={!files.length || analyzing}
                        >
                            {analyzing ? (
                                <Loader2 className="animate-spin" />
                            ) : (
                                <Sparkles />
                            )}
                            {analyzing
                                ? `AI membaca ${files.length} screenshot…`
                                : 'Hitung harga pasaran'}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setOpen(false)}
                            disabled={analyzing}
                        >
                            Batal
                        </Button>
                    </div>
                </div>
            )}

            {latest && (
                <PriceCheckResult
                    check={latest}
                    diecast={diecast}
                    applying={applyingId === latest.id}
                    onApply={() => void apply(latest)}
                    onDelete={() => void remove(latest)}
                />
            )}

            {older.length > 0 && (
                <div className="space-y-2">
                    <button
                        type="button"
                        onClick={() => setShowOlder(!showOlder)}
                        className="text-sm text-muted-foreground hover:text-foreground"
                    >
                        {showOlder ? 'Sembunyikan' : 'Lihat'} {older.length}{' '}
                        cek sebelumnya
                    </button>
                    {showOlder &&
                        older.map((check) => (
                            <PriceCheckResult
                                key={check.id}
                                check={check}
                                diecast={diecast}
                                applying={applyingId === check.id}
                                onApply={() => void apply(check)}
                                onDelete={() => void remove(check)}
                                compact
                            />
                        ))}
                </div>
            )}
        </section>
    );
}

function PriceCheckResult({
    check,
    diecast,
    applying,
    onApply,
    onDelete,
    compact = false,
}: {
    check: PriceCheck;
    diecast: Diecast;
    applying: boolean;
    onApply: () => void;
    onDelete: () => void;
    compact?: boolean;
}) {
    const [showListings, setShowListings] = useState(!compact);
    const { stats } = check;
    const accuracy = ACCURACY[check.accuracy];
    const counted = check.listings.filter((l) => l.status === 'included');
    const target = diecast.status === 'wishlist' ? diecast.target_price : null;

    return (
        <div
            className={cn(
                'space-y-3 rounded-lg border p-3',
                compact && 'bg-muted/30',
            )}
        >
            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                <span>Dicek {formatDate(check.created_at)}</span>
                <span>·</span>
                <span>
                    {check.listings.length} listing terbaca dari{' '}
                    {check.screenshot_urls.length} screenshot
                </span>
                <Badge className={cn('border-transparent', accuracy.className)}>
                    {accuracy.label}
                </Badge>
                {check.applied_at && (
                    <Badge variant="outline">Dipakai</Badge>
                )}
                <button
                    type="button"
                    onClick={onDelete}
                    className="ml-auto text-muted-foreground hover:text-destructive"
                    aria-label="Hapus hasil cek ini"
                    title="Hapus"
                >
                    <Trash2 className="size-4" />
                </button>
            </div>

            {stats ? (
                <div className="grid gap-3 sm:grid-cols-[auto_1fr] sm:items-center">
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Median ({stats.count} listing)
                        </p>
                        <p className="text-3xl font-semibold tracking-tight tabular-nums">
                            {formatRupiah(stats.median)}
                        </p>
                    </div>
                    <div className="space-y-1 text-sm sm:pl-4">
                        <p>
                            Rentang wajar:{' '}
                            <strong>
                                {formatRupiah(stats.q1)} –{' '}
                                {formatRupiah(stats.q3)}
                            </strong>
                        </p>
                        <p className="text-muted-foreground">
                            Termurah {formatRupiah(stats.min)} · termahal{' '}
                            {formatRupiah(stats.max)}
                        </p>
                        {target && (
                            <p
                                className={
                                    Number(target) >= stats.median
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-amber-600 dark:text-amber-400'
                                }
                            >
                                Target kamu {formatRupiah(target)} —{' '}
                                {Number(target) >= stats.median
                                    ? 'realistis, di atas median pasar.'
                                    : 'di bawah median, perlu sabar cari promo.'}
                            </p>
                        )}
                    </div>
                </div>
            ) : (
                <p className="text-sm">Belum ada listing yang cocok.</p>
            )}

            <ul className="space-y-1 text-sm">
                {check.reasons.map((reason) => (
                    <li key={reason} className="flex gap-2">
                        <CircleCheck className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        {reason}
                    </li>
                ))}
                {check.tips.map((tip) => (
                    <li
                        key={tip}
                        className="flex gap-2 text-amber-700 dark:text-amber-300"
                    >
                        <CircleAlert className="mt-0.5 size-4 shrink-0" />
                        {tip}
                    </li>
                ))}
            </ul>

            {check.ai_note && (
                <p className="flex gap-2 text-sm text-muted-foreground">
                    <Sparkles className="mt-0.5 size-4 shrink-0 text-amber-500" />
                    {check.ai_note}
                </p>
            )}

            {stats && (
                <Button
                    type="button"
                    size="sm"
                    onClick={onApply}
                    disabled={applying}
                    variant={check.applied_at ? 'outline' : 'default'}
                >
                    {applying && <Loader2 className="animate-spin" />}
                    Pakai {formatRupiah(stats.suggested)} sebagai harga pasaran
                </Button>
            )}

            <div>
                <button
                    type="button"
                    onClick={() => setShowListings(!showListings)}
                    className="text-sm font-medium hover:underline"
                >
                    {showListings ? 'Sembunyikan' : 'Lihat'} rincian{' '}
                    {check.listings.length} listing ({counted.length} dihitung)
                </button>

                {showListings && (
                    <ul className="mt-2 divide-y rounded-md border text-sm">
                        {check.listings.map((listing, index) => (
                            <li
                                key={index}
                                className={cn(
                                    'flex items-start gap-2 p-2',
                                    listing.status !== 'included' &&
                                        'text-muted-foreground',
                                )}
                            >
                                {listing.status === 'included' ? (
                                    <CircleCheck className="mt-0.5 size-4 shrink-0 text-emerald-600" />
                                ) : listing.status === 'outlier' ? (
                                    <CircleAlert className="mt-0.5 size-4 shrink-0 text-amber-500" />
                                ) : (
                                    <CircleMinus className="mt-0.5 size-4 shrink-0" />
                                )}
                                <div className="min-w-0 flex-1">
                                    <p className="line-clamp-2">
                                        {listing.title}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {[
                                            listing.shop,
                                            listing.location,
                                            listing.sold
                                                ? `${listing.sold}+ terjual`
                                                : null,
                                            listing.is_ad ? 'Iklan' : null,
                                            listing.marketplace !== 'tokopedia'
                                                ? listing.marketplace
                                                : null,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                    {listing.status !== 'included' && (
                                        <p className="text-xs">
                                            Tidak dihitung:{' '}
                                            {listing.excluded_reason}
                                            {listing.reason &&
                                                ` — ${listing.reason}`}
                                        </p>
                                    )}
                                </div>
                                <span
                                    className={cn(
                                        'shrink-0 tabular-nums',
                                        listing.status === 'included' &&
                                            'font-medium',
                                        listing.status !== 'included' &&
                                            'line-through',
                                    )}
                                >
                                    {formatRupiah(listing.price_idr)}
                                    {listing.is_price_range && '+'}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {showListings && check.screenshot_urls.length > 0 && (
                <div className="flex gap-2 overflow-x-auto">
                    {check.screenshot_urls.map((url) => (
                        <a
                            key={url}
                            href={url}
                            target="_blank"
                            rel="noreferrer"
                            className="shrink-0"
                        >
                            <img
                                src={url}
                                alt="Screenshot"
                                className="h-28 rounded border object-cover object-top"
                            />
                        </a>
                    ))}
                </div>
            )}
        </div>
    );
}
