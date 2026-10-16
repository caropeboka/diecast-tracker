import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Camera,
    CircleCheck,
    CirclePlus,
    Eye,
    Heart,
    Loader2,
    Plus,
    ScanSearch,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import CheckController from '@/actions/App/Http/Controllers/CheckController';
import DiecastController from '@/actions/App/Http/Controllers/DiecastController';
import LocationField, {
    freshCoordinates,
    readSavedPlace,
    savePlace,
} from '@/components/location-field';
import type { PlaceValue } from '@/components/location-field';
import MatchList from '@/components/match-list';
import MoneyInput from '@/components/money-input';
import { Button } from '@/components/ui/button';
import { errorMessage, postJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { formatRupiah, RARITY_LABELS } from '@/types/diecast';
import type { Diecast, ScanItem } from '@/types/diecast';

type Verdict = 'owned' | 'wishlist' | 'spotted' | 'new' | 'failed';

function verdictOf(item: ScanItem): Verdict {
    if (item.status === 'failed') {
        return 'failed';
    }

    const statuses = item.matches.map((m) => m.status);

    if (statuses.includes('owned')) {
        return 'owned';
    }

    if (statuses.includes('wishlist')) {
        return 'wishlist';
    }

    return statuses.includes('spotted') ? 'spotted' : 'new';
}

const VERDICTS = {
    owned: {
        title: 'Sudah punya',
        icon: CircleCheck,
        className:
            'border-emerald-500/50 bg-emerald-50 text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-100',
    },
    wishlist: {
        title: 'Ada di wishlist kamu!',
        icon: Heart,
        className:
            'border-rose-500/50 bg-rose-50 text-rose-900 dark:bg-rose-500/10 dark:text-rose-100',
    },
    spotted: {
        title: 'Pernah kamu temui',
        icon: Eye,
        className:
            'border-violet-500/50 bg-violet-50 text-violet-900 dark:bg-violet-500/10 dark:text-violet-100',
    },
    new: {
        title: 'Belum punya',
        icon: CirclePlus,
        className:
            'border-sky-500/50 bg-sky-50 text-sky-900 dark:bg-sky-500/10 dark:text-sky-100',
    },
    failed: {
        title: 'Tidak terbaca',
        icon: AlertTriangle,
        className:
            'border-amber-500/50 bg-amber-50 text-amber-900 dark:bg-amber-500/10 dark:text-amber-100',
    },
};

export default function CheckCollection({
    scanEnabled,
    places,
}: {
    scanEnabled: boolean;
    places: string[];
}) {
    const [checking, setChecking] = useState(false);
    const [results, setResults] = useState<ScanItem[]>([]);
    const [place, setPlace] = useState<PlaceValue>(readSavedPlace);
    const [prices, setPrices] = useState<Record<number, string>>({});
    const [logged, setLogged] = useState<Record<number, string>>({});
    const [busy, setBusy] = useState<number | null>(null);
    const cameraInput = useRef<HTMLInputElement>(null);

    // Remember the store between checks (and for "Beli di" when adding what you just bought).
    useEffect(() => savePlace(place), [place]);

    async function check(file: File | undefined) {
        if (!file) {
            return;
        }

        const body = new FormData();
        body.append('photo', file);
        setChecking(true);

        try {
            const item = await postJson<ScanItem>(
                CheckController.check.url(),
                body,
            );
            setResults((current) => [item, ...current].slice(0, 10));
        } catch (error) {
            toast.error(errorMessage(error));
        }

        setChecking(false);
    }

    function replaceMatch(item: ScanItem, updated: Diecast) {
        setResults((current) =>
            current.map((r) =>
                r.id === item.id
                    ? {
                          ...r,
                          matches: r.matches.map((m) =>
                              m.id === updated.id ? { ...m, ...updated } : m,
                          ),
                      }
                    : r,
            ),
        );
    }

    async function quick(
        item: ScanItem,
        match: Diecast,
        action: 'increment' | 'acquire',
    ) {
        try {
            const updated = await postJson<Diecast>(
                CheckController.quick.url(match.id),
                { action },
            );
            replaceMatch(item, updated);
            toast.success(
                action === 'increment'
                    ? `${updated.name}: sekarang punya ${updated.quantity}×`
                    : `${updated.name} dipindah ke koleksi 🎉`,
            );
        } catch (error) {
            toast.error(errorMessage(error));
        }
    }

    /** Log a sighting here — on an existing entry (match) or as a new "Ditemui" diecast. */
    async function spot(item: ScanItem, match?: Diecast) {
        setBusy(item.id);

        try {
            const diecast = await postJson<Diecast>(
                CheckController.spot.url(item.id),
                {
                    diecast_id: match?.id ?? null,
                    place: place.name || null,
                    ...freshCoordinates(place),
                    price: prices[item.id] || null,
                },
            );

            if (match) {
                replaceMatch(item, diecast);
            }

            setLogged((current) => ({
                ...current,
                [item.id]: place.name || 'tempat ini',
            }));
            toast.success(`${diecast.name} dicatat sebagai temuan.`);
        } catch (error) {
            toast.error(errorMessage(error));
        }

        setBusy(null);
    }

    return (
        <>
            <Head title="Cek koleksi" />
            <div className="mx-auto w-full max-w-xl space-y-5 p-4 md:p-6">
                <div className="text-center">
                    <h1 className="font-heading text-2xl font-semibold">
                        Cek koleksi
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Lagi di toko? Foto diecast-nya, langsung ketahuan sudah
                        punya atau belum.
                    </p>
                </div>

                <LocationField
                    value={place}
                    onChange={setPlace}
                    places={places}
                    className="surface rounded-xl px-3 py-2"
                    inputClassName="h-8 border-0 bg-transparent px-1 shadow-none focus-visible:ring-0"
                />

                {!scanEnabled ? (
                    <div className="flex gap-2 rounded-lg border border-amber-400/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        Fitur ini butuh scan AI. Isi API key di Settings → AI
                        Scan.
                    </div>
                ) : (
                    <Button
                        size="lg"
                        className="h-20 w-full text-lg"
                        onClick={() => cameraInput.current?.click()}
                        disabled={checking}
                    >
                        {checking ? (
                            <>
                                <Loader2 className="size-6 animate-spin" />
                                Mengecek…
                            </>
                        ) : (
                            <>
                                <Camera className="size-6" />
                                {results.length
                                    ? 'Cek diecast lain'
                                    : 'Foto diecast'}
                            </>
                        )}
                    </Button>
                )}

                <input
                    ref={cameraInput}
                    type="file"
                    accept="image/*"
                    capture="environment"
                    className="hidden"
                    onChange={(e) => {
                        void check(e.target.files?.[0]);
                        e.target.value = '';
                    }}
                />

                {results.map((item, index) => {
                    const verdict = verdictOf(item);
                    const style = VERDICTS[verdict];
                    const Icon = style.icon;
                    const fields = item.fields;
                    const loggedAt = logged[item.id];
                    const canSpot = !loggedAt && verdict !== 'failed';

                    return (
                        <div
                            key={item.id}
                            className={cn(
                                'space-y-3 rounded-xl border-2 p-4',
                                style.className,
                                index > 0 && 'opacity-70',
                            )}
                        >
                            <div className="flex gap-3">
                                <img
                                    src={item.photo_url}
                                    alt=""
                                    className="size-20 shrink-0 rounded-lg object-cover"
                                />
                                <div className="min-w-0">
                                    <p className="flex items-center gap-1.5 text-lg font-semibold">
                                        <Icon className="size-5 shrink-0" />
                                        {style.title}
                                    </p>
                                    {fields?.name ? (
                                        <>
                                            <p className="font-medium">
                                                {fields.name}
                                            </p>
                                            <p className="text-sm opacity-80">
                                                {[
                                                    fields.brand,
                                                    fields.series,
                                                    fields.collection_number,
                                                    fields.rarity &&
                                                    fields.rarity !== 'regular'
                                                        ? RARITY_LABELS[
                                                              fields.rarity
                                                          ]
                                                        : null,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        </>
                                    ) : (
                                        <p className="text-sm">
                                            {item.error ??
                                                'AI tidak bisa mengenali diecast ini.'}
                                        </p>
                                    )}
                                </div>
                            </div>

                            {item.matches.length > 0 && (
                                <div className="rounded-lg bg-background/70 p-3 text-foreground">
                                    <MatchList
                                        matches={item.matches}
                                        actions={(match) => (
                                            <div className="flex shrink-0 gap-1">
                                                {match.status === 'owned' && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            void quick(
                                                                item,
                                                                match,
                                                                'increment',
                                                            )
                                                        }
                                                        title="Beli satu lagi (+1 jumlah)"
                                                    >
                                                        <Plus /> 1
                                                    </Button>
                                                )}
                                                {(match.status === 'wishlist' ||
                                                    match.status ===
                                                        'spotted') && (
                                                    <>
                                                        {canSpot && (
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                disabled={
                                                                    busy ===
                                                                    item.id
                                                                }
                                                                onClick={() =>
                                                                    void spot(
                                                                        item,
                                                                        match,
                                                                    )
                                                                }
                                                                title="Catat temuan di lokasi ini"
                                                            >
                                                                <Eye />
                                                            </Button>
                                                        )}
                                                        <Button
                                                            size="sm"
                                                            onClick={() =>
                                                                void quick(
                                                                    item,
                                                                    match,
                                                                    'acquire',
                                                                )
                                                            }
                                                            title="Sudah dapat — pindahkan ke koleksi"
                                                        >
                                                            <CircleCheck />{' '}
                                                            Dapat
                                                        </Button>
                                                    </>
                                                )}
                                            </div>
                                        )}
                                    />
                                </div>
                            )}

                            {loggedAt ? (
                                <p className="flex items-center gap-1.5 text-sm font-medium">
                                    <Eye className="size-4" /> Dicatat sebagai
                                    temuan di {loggedAt}.
                                </p>
                            ) : (
                                canSpot && (
                                    <div className="flex items-center gap-2 rounded-lg bg-background/70 p-2 text-foreground">
                                        <span className="shrink-0 pl-1 text-xs text-muted-foreground">
                                            Harga di rak
                                        </span>
                                        <MoneyInput
                                            aria-label="Harga di rak"
                                            placeholder="Opsional"
                                            value={prices[item.id] ?? ''}
                                            onChange={(e) =>
                                                setPrices((current) => ({
                                                    ...current,
                                                    [item.id]: e.target.value,
                                                }))
                                            }
                                            className="h-8"
                                        />
                                    </div>
                                )
                            )}

                            <div className="flex flex-wrap gap-2">
                                {verdict === 'new' && canSpot && (
                                    <Button
                                        size="sm"
                                        disabled={busy === item.id}
                                        onClick={() => void spot(item)}
                                    >
                                        {busy === item.id ? (
                                            <Loader2 className="animate-spin" />
                                        ) : (
                                            <Eye />
                                        )}
                                        Tandai ditemui
                                    </Button>
                                )}
                                <Button size="sm" variant="secondary" asChild>
                                    <Link
                                        href={DiecastController.create({
                                            query: {
                                                scan_item: item.id,
                                                return: 'check',
                                            },
                                        })}
                                    >
                                        <ScanSearch />
                                        {verdict === 'owned'
                                            ? 'Tambah sebagai item terpisah'
                                            : 'Tambah ke koleksi'}
                                    </Link>
                                </Button>
                                {verdict !== 'wishlist' && (
                                    <Button size="sm" variant="outline" asChild>
                                        <Link
                                            href={DiecastController.create({
                                                query: {
                                                    scan_item: item.id,
                                                    status: 'wishlist',
                                                    return: 'check',
                                                },
                                            })}
                                        >
                                            <Heart /> Wishlist
                                        </Link>
                                    </Button>
                                )}
                            </div>

                            {verdict === 'spotted' && (
                                <p className="text-xs opacity-80">
                                    Harga termurah yang pernah kamu catat:{' '}
                                    {formatRupiah(
                                        item.matches.find(
                                            (m) => m.sightings_min_price,
                                        )?.sightings_min_price ?? null,
                                    )}
                                </p>
                            )}
                        </div>
                    );
                })}
            </div>
        </>
    );
}

CheckCollection.layout = {
    breadcrumbs: [{ title: 'Cek koleksi', href: CheckController.index() }],
};
