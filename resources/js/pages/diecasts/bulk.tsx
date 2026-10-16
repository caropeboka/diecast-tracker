import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Camera,
    CheckCheck,
    ImagePlus,
    Loader2,
    Pause,
    Pencil,
    Play,
    RotateCw,
    Save,
    Sparkles,
    Trash2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import BulkScanController from '@/actions/App/Http/Controllers/BulkScanController';
import DiecastController from '@/actions/App/Http/Controllers/DiecastController';
import Heading from '@/components/heading';
import LocationField, { recentSavedPlace } from '@/components/location-field';
import type { PlaceValue } from '@/components/location-field';
import MatchList from '@/components/match-list';
import MoneyInput from '@/components/money-input';
import StatusToggle from '@/components/status-toggle';
import TagInput from '@/components/tag-input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { errorMessage, HttpError, postJson, requestJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { RARITY_LABELS } from '@/types/diecast';
import type { Rarity, ScanItem } from '@/types/diecast';

type Edits = {
    name?: string;
    brand?: string;
    series?: string;
    collection_number?: string;
    color?: string;
    rarity?: Rarity;
    quantity?: string;
    purchase_price?: string;
};

type Defaults = {
    status: 'owned' | 'wishlist' | 'spotted';
    purchase_date: string;
    purchase_place: string;
    storage_location: string;
    tags: string[];
};

const CONFIDENCE = {
    high: { label: 'Yakin', className: 'bg-emerald-600 text-white' },
    medium: { label: 'Cukup yakin', className: 'bg-sky-600 text-white' },
    low: { label: 'Kurang yakin', className: 'bg-amber-500 text-white' },
};

/** Ready for "save all": the AI was confident and it doesn't look like a duplicate. */
const isSafe = (item: ScanItem) =>
    item.status === 'done' &&
    !!item.fields?.name &&
    item.fields.confidence !== 'low' &&
    item.matches.length === 0;

export default function BulkScan({
    items: initialItems,
    suggestions,
    scanEnabled,
}: {
    items: ScanItem[];
    suggestions: {
        purchase_place: string[];
        storage_location: string[];
        tags: string[];
    };
    scanEnabled: boolean;
}) {
    const [items, setItems] = useState(initialItems);
    const [edits, setEdits] = useState<Record<number, Edits>>({});
    // Still at the store you just checked in Cek koleksi? Start from that place and its GPS point.
    const [savedPlace] = useState(recentSavedPlace);
    const [defaults, setDefaults] = useState<Defaults>({
        status: 'owned',
        purchase_date: new Date().toISOString().slice(0, 10),
        purchase_place: savedPlace?.name ?? '',
        storage_location: '',
        tags: [],
    });
    const [placeGeo, setPlaceGeo] = useState<Omit<PlaceValue, 'name'>>(
        savedPlace ?? {},
    );
    const [upload, setUpload] = useState<{ done: number; total: number }>();
    const [paused, setPaused] = useState(!scanEnabled);
    const [waitUntil, setWaitUntil] = useState<number | null>(null);
    const [now, setNow] = useState(() => Date.now());
    const [processingId, setProcessingId] = useState<number | null>(null);
    const [busyIds, setBusyIds] = useState<Set<number>>(new Set());
    const [savingAll, setSavingAll] = useState(false);
    const cameraInput = useRef<HTMLInputElement>(null);
    const galleryInput = useRef<HTMLInputElement>(null);

    const pending = items.filter((i) => i.status === 'pending');
    const done = items.filter((i) => i.status === 'done');
    const safe = items.filter(isSafe);

    function replace(item: ScanItem) {
        setItems((current) =>
            current.map((i) => (i.id === item.id ? item : i)),
        );
    }

    function remove(id: number) {
        setItems((current) => current.filter((i) => i.id !== id));
    }

    function setBusy(id: number, busy: boolean) {
        setBusyIds((current) => {
            const next = new Set(current);

            if (busy) {
                next.add(id);
            } else {
                next.delete(id);
            }

            return next;
        });
    }

    // Countdown while waiting for the AI quota to recover.
    useEffect(() => {
        if (!waitUntil) {
            return;
        }

        const timer = setInterval(() => {
            setNow(Date.now());

            if (Date.now() >= waitUntil) {
                setWaitUntil(null);
            }
        }, 1000);

        return () => clearInterval(timer);
    }, [waitUntil]);

    // Process the queue one photo at a time while the page is open.
    // The ref guards against React running this effect twice for the same item.
    const inFlight = useRef<number | null>(null);

    useEffect(() => {
        const next = items.find((i) => i.status === 'pending');

        if (!next || paused || waitUntil || inFlight.current !== null) {
            return;
        }

        inFlight.current = next.id;
        setProcessingId(next.id);

        postJson<ScanItem>(BulkScanController.process.url(next.id), {})
            .then(replace)
            .catch((error) => {
                if (error instanceof HttpError && error.status === 429) {
                    const seconds = Number(error.data.retry_after ?? 60);
                    setWaitUntil(Date.now() + seconds * 1000);
                } else {
                    setPaused(true);
                    toast.error(errorMessage(error));
                }
            })
            .finally(() => {
                inFlight.current = null;
                setProcessingId(null);
            });
    }, [items, paused, waitUntil, processingId]);

    async function addFiles(files: FileList | null) {
        const images = Array.from(files ?? []).filter((f) =>
            f.type.startsWith('image/'),
        );

        if (!images.length) {
            return;
        }

        setUpload({ done: 0, total: images.length });

        for (const [index, file] of images.entries()) {
            const body = new FormData();
            body.append('photo', file);

            try {
                const item = await postJson<ScanItem>(
                    BulkScanController.upload.url(),
                    body,
                );
                setItems((current) => [...current, item]);
            } catch (error) {
                toast.error(`${file.name}: ${errorMessage(error)}`);
            }

            setUpload({ done: index + 1, total: images.length });
        }

        setUpload(undefined);
    }

    async function retry(item: ScanItem) {
        setBusy(item.id, true);

        try {
            replace(
                await postJson<ScanItem>(
                    BulkScanController.process.url(item.id),
                    {},
                ),
            );
        } catch (error) {
            toast.error(errorMessage(error));
        }

        setBusy(item.id, false);
    }

    async function save(item: ScanItem): Promise<boolean> {
        const edit = edits[item.id] ?? {};
        setBusy(item.id, true);

        try {
            const wishlist = defaults.status === 'wishlist';

            await postJson(BulkScanController.save.url(item.id), {
                ...defaults,
                purchase_date: wishlist
                    ? null
                    : defaults.purchase_date || null,
                purchase_latitude: wishlist ? null : (placeGeo.lat ?? null),
                purchase_longitude: wishlist ? null : (placeGeo.lng ?? null),
                ...Object.fromEntries(
                    Object.entries(edit).map(([k, v]) => [
                        k,
                        v === '' ? null : v,
                    ]),
                ),
            });
            remove(item.id);

            return true;
        } catch (error) {
            toast.error(
                `${edit.name ?? item.fields?.name ?? 'Foto'}: ${errorMessage(error)}`,
            );

            return false;
        } finally {
            setBusy(item.id, false);
        }
    }

    async function discard(item: ScanItem) {
        setBusy(item.id, true);

        try {
            await requestJson(
                'DELETE',
                BulkScanController.destroy.url(item.id),
            );
            remove(item.id);
        } catch (error) {
            toast.error(errorMessage(error));
            setBusy(item.id, false);
        }
    }

    async function saveAll() {
        setSavingAll(true);
        let saved = 0;

        for (const item of safe) {
            if (await save(item)) {
                saved++;
            }
        }

        setSavingAll(false);
        toast.success(`${saved} diecast disimpan ke koleksi.`);
    }

    const value = (item: ScanItem, key: keyof Edits): string =>
        String(
            edits[item.id]?.[key] ??
                (key === 'quantity'
                    ? '1'
                    : key === 'purchase_price'
                      ? ''
                      : (item.fields?.[key] ?? '')),
        );

    const edit = (item: ScanItem, key: keyof Edits, v: string) =>
        setEdits((current) => ({
            ...current,
            [item.id]: { ...current[item.id], [key]: v },
        }));

    return (
        <>
            <Head title="Scan massal" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    title="Scan massal"
                    description="Upload banyak foto sekaligus. AI membaca satu per satu, kamu tinggal cek dan simpan."
                />

                {!scanEnabled && (
                    <div className="flex gap-2 rounded-lg border border-amber-400/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        Scan AI belum aktif. Isi API key di Settings → AI Scan.
                    </div>
                )}

                {/* Upload */}
                <div className="flex flex-col gap-4 rounded-xl border-2 border-dashed border-sidebar-border p-6 text-center sm:flex-row sm:items-center sm:text-left">
                    <div className="flex-1">
                        <p className="font-medium">Tambah foto ke antrian</p>
                        <p className="text-sm text-muted-foreground">
                            Satu foto = satu diecast. Foto depan kartunya dengan
                            cahaya terang.
                        </p>
                        {upload && (
                            <p className="mt-2 flex items-center gap-2 text-sm">
                                <Loader2 className="size-4 animate-spin" />
                                Mengupload {upload.done}/{upload.total}…
                            </p>
                        )}
                    </div>
                    <div className="flex justify-center gap-2">
                        <Button
                            onClick={() => galleryInput.current?.click()}
                            disabled={!!upload}
                        >
                            <ImagePlus /> Pilih banyak foto
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => cameraInput.current?.click()}
                            disabled={!!upload}
                        >
                            <Camera /> Kamera
                        </Button>
                    </div>
                    <input
                        ref={galleryInput}
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        multiple
                        className="hidden"
                        onChange={(e) => {
                            void addFiles(e.target.files);
                            e.target.value = '';
                        }}
                    />
                    <input
                        ref={cameraInput}
                        type="file"
                        accept="image/*"
                        capture="environment"
                        className="hidden"
                        onChange={(e) => {
                            void addFiles(e.target.files);
                            e.target.value = '';
                        }}
                    />
                </div>

                {items.length > 0 && (
                    <>
                        {/* Progress + controls */}
                        <div className="flex flex-wrap items-center gap-3 rounded-xl border border-sidebar-border/70 p-3 text-sm dark:border-sidebar-border">
                            <span>
                                <strong>{pending.length}</strong> menunggu AI ·{' '}
                                <strong>{done.length}</strong> siap dicek ·{' '}
                                <strong>
                                    {
                                        items.filter(
                                            (i) => i.status === 'failed',
                                        ).length
                                    }
                                </strong>{' '}
                                gagal
                            </span>
                            {processingId !== null && (
                                <span className="flex items-center gap-1.5 text-muted-foreground">
                                    <Loader2 className="size-4 animate-spin" />
                                    AI sedang membaca…
                                </span>
                            )}
                            {waitUntil && (
                                <span className="text-amber-600 dark:text-amber-400">
                                    Kuota AI penuh, lanjut otomatis dalam{' '}
                                    {Math.max(
                                        0,
                                        Math.ceil((waitUntil - now) / 1000),
                                    )}{' '}
                                    detik
                                </span>
                            )}
                            <div className="ml-auto flex gap-2">
                                {pending.length > 0 && scanEnabled && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setPaused(!paused)}
                                    >
                                        {paused ? <Play /> : <Pause />}
                                        {paused ? 'Lanjutkan' : 'Jeda'}
                                    </Button>
                                )}
                                <Button
                                    size="sm"
                                    onClick={() => void saveAll()}
                                    disabled={!safe.length || savingAll}
                                    title="Diecast yang AI kurang yakin atau mirip dengan koleksimu dilewati — cek satu per satu."
                                >
                                    {savingAll ? (
                                        <Loader2 className="animate-spin" />
                                    ) : (
                                        <CheckCheck />
                                    )}
                                    Simpan semua yang yakin ({safe.length})
                                </Button>
                            </div>
                        </div>

                        {/* Shared defaults */}
                        <details className="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                            <summary className="cursor-pointer font-medium">
                                Isian yang sama untuk semua (tanggal beli, toko,
                                lokasi simpan, tag)
                            </summary>
                            <div className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <div className="grid gap-2 sm:col-span-2">
                                    <Label>Simpan sebagai</Label>
                                    <StatusToggle
                                        compact
                                        options={[
                                            'owned',
                                            'wishlist',
                                            'spotted',
                                        ]}
                                        value={defaults.status}
                                        onChange={(status) =>
                                            setDefaults({
                                                ...defaults,
                                                status: status as Defaults['status'],
                                            })
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="d-date">
                                        {defaults.status === 'spotted'
                                            ? 'Tanggal ditemui'
                                            : 'Tanggal beli'}
                                    </Label>
                                    <Input
                                        id="d-date"
                                        type="date"
                                        value={defaults.purchase_date}
                                        onChange={(e) =>
                                            setDefaults({
                                                ...defaults,
                                                purchase_date: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label>
                                        {defaults.status === 'spotted'
                                            ? 'Ditemui di'
                                            : 'Beli di'}
                                    </Label>
                                    <LocationField
                                        value={{
                                            name: defaults.purchase_place,
                                            ...placeGeo,
                                        }}
                                        onChange={({ name, ...geo }) => {
                                            setDefaults({
                                                ...defaults,
                                                purchase_place: name,
                                            });
                                            setPlaceGeo(geo);
                                        }}
                                        places={suggestions.purchase_place}
                                        placeholder="Toko / marketplace / teman"
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="d-storage">
                                        Lokasi simpan
                                    </Label>
                                    <Input
                                        id="d-storage"
                                        list="d-storage-options"
                                        value={defaults.storage_location}
                                        onChange={(e) =>
                                            setDefaults({
                                                ...defaults,
                                                storage_location:
                                                    e.target.value,
                                            })
                                        }
                                    />
                                    <datalist id="d-storage-options">
                                        {suggestions.storage_location.map(
                                            (p) => (
                                                <option key={p} value={p} />
                                            ),
                                        )}
                                    </datalist>
                                </div>
                                <div className="grid gap-2 sm:col-span-2 lg:col-span-4">
                                    <Label>Tag</Label>
                                    <TagInput
                                        value={defaults.tags}
                                        onChange={(tags) =>
                                            setDefaults({ ...defaults, tags })
                                        }
                                        suggestions={suggestions.tags}
                                    />
                                </div>
                            </div>
                        </details>

                        {/* Items */}
                        <div className="grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                            {items.map((item) => {
                                const busy = busyIds.has(item.id);
                                const confidence =
                                    item.fields?.confidence &&
                                    CONFIDENCE[item.fields.confidence];

                                return (
                                    <div
                                        key={item.id}
                                        className={cn(
                                            'flex gap-3 rounded-xl border border-sidebar-border/70 p-3 dark:border-sidebar-border',
                                            busy && 'opacity-60',
                                        )}
                                    >
                                        <img
                                            src={item.photo_url}
                                            alt=""
                                            className="size-28 shrink-0 rounded-lg bg-muted object-cover sm:size-32"
                                        />

                                        <div className="min-w-0 flex-1 space-y-2">
                                            {item.status === 'pending' && (
                                                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                                    {processingId ===
                                                    item.id ? (
                                                        <>
                                                            <Loader2 className="size-4 animate-spin" />
                                                            AI sedang membaca…
                                                        </>
                                                    ) : (
                                                        'Menunggu giliran…'
                                                    )}
                                                </p>
                                            )}

                                            {item.status === 'failed' && (
                                                <>
                                                    <p className="text-sm text-destructive">
                                                        {item.error}
                                                    </p>
                                                    <div className="flex flex-wrap gap-2">
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                void retry(item)
                                                            }
                                                            disabled={busy}
                                                        >
                                                            <RotateCw /> Coba
                                                            lagi
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={DiecastController.create(
                                                                    {
                                                                        query: {
                                                                            scan_item:
                                                                                item.id,
                                                                        },
                                                                    },
                                                                )}
                                                            >
                                                                <Pencil /> Isi
                                                                manual
                                                            </Link>
                                                        </Button>
                                                    </div>
                                                </>
                                            )}

                                            {item.status === 'done' && (
                                                <>
                                                    <div className="flex flex-wrap items-center gap-1.5">
                                                        {confidence && (
                                                            <Badge
                                                                className={cn(
                                                                    'border-transparent',
                                                                    confidence.className,
                                                                )}
                                                            >
                                                                <Sparkles />
                                                                {
                                                                    confidence.label
                                                                }
                                                            </Badge>
                                                        )}
                                                        {item.matches.length >
                                                            0 && (
                                                            <Badge variant="outline">
                                                                Mirip koleksimu
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    <Input
                                                        aria-label="Nama"
                                                        placeholder="Nama model"
                                                        value={value(
                                                            item,
                                                            'name',
                                                        )}
                                                        onChange={(e) =>
                                                            edit(
                                                                item,
                                                                'name',
                                                                e.target.value,
                                                            )
                                                        }
                                                        className="h-8 font-medium"
                                                    />
                                                    <div className="grid grid-cols-2 gap-2">
                                                        <Input
                                                            aria-label="Merek"
                                                            placeholder="Merek"
                                                            value={value(
                                                                item,
                                                                'brand',
                                                            )}
                                                            onChange={(e) =>
                                                                edit(
                                                                    item,
                                                                    'brand',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            className="h-8"
                                                        />
                                                        <Input
                                                            aria-label="Nomor koleksi"
                                                            placeholder="No. koleksi"
                                                            value={value(
                                                                item,
                                                                'collection_number',
                                                            )}
                                                            onChange={(e) =>
                                                                edit(
                                                                    item,
                                                                    'collection_number',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            className="h-8"
                                                        />
                                                        <Input
                                                            aria-label="Seri"
                                                            placeholder="Seri"
                                                            value={value(
                                                                item,
                                                                'series',
                                                            )}
                                                            onChange={(e) =>
                                                                edit(
                                                                    item,
                                                                    'series',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            className="col-span-2 h-8"
                                                        />
                                                        <Select
                                                            value={
                                                                (edits[item.id]
                                                                    ?.rarity ??
                                                                    item.fields
                                                                        ?.rarity ??
                                                                    'regular') as string
                                                            }
                                                            onValueChange={(
                                                                v,
                                                            ) =>
                                                                edit(
                                                                    item,
                                                                    'rarity',
                                                                    v,
                                                                )
                                                            }
                                                        >
                                                            <SelectTrigger
                                                                size="sm"
                                                                className="w-full"
                                                                aria-label="Kelangkaan"
                                                            >
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {Object.entries(
                                                                    RARITY_LABELS,
                                                                ).map(
                                                                    ([
                                                                        v,
                                                                        l,
                                                                    ]) => (
                                                                        <SelectItem
                                                                            key={
                                                                                v
                                                                            }
                                                                            value={
                                                                                v
                                                                            }
                                                                        >
                                                                            {l}
                                                                        </SelectItem>
                                                                    ),
                                                                )}
                                                            </SelectContent>
                                                        </Select>
                                                        <MoneyInput
                                                            aria-label="Harga beli"
                                                            placeholder="Harga"
                                                            value={value(
                                                                item,
                                                                'purchase_price',
                                                            )}
                                                            onChange={(e) =>
                                                                edit(
                                                                    item,
                                                                    'purchase_price',
                                                                    e.target
                                                                        .value,
                                                                )
                                                            }
                                                            className="h-8"
                                                        />
                                                    </div>

                                                    {item.matches.length >
                                                        0 && (
                                                        <details className="rounded-md bg-sky-50 p-2 text-sm dark:bg-sky-500/10">
                                                            <summary className="cursor-pointer">
                                                                Sudah ada{' '}
                                                                {
                                                                    item.matches
                                                                        .length
                                                                }{' '}
                                                                yang mirip
                                                            </summary>
                                                            <div className="mt-2">
                                                                <MatchList
                                                                    matches={
                                                                        item.matches
                                                                    }
                                                                />
                                                            </div>
                                                        </details>
                                                    )}

                                                    <div className="flex flex-wrap gap-2">
                                                        <Button
                                                            size="sm"
                                                            onClick={() =>
                                                                void save(item)
                                                            }
                                                            disabled={
                                                                busy ||
                                                                !value(
                                                                    item,
                                                                    'name',
                                                                )
                                                            }
                                                        >
                                                            <Save /> Simpan
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={DiecastController.create(
                                                                    {
                                                                        query: {
                                                                            scan_item:
                                                                                item.id,
                                                                        },
                                                                    },
                                                                )}
                                                            >
                                                                <Pencil />{' '}
                                                                Lengkap
                                                            </Link>
                                                        </Button>
                                                    </div>
                                                </>
                                            )}
                                        </div>

                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            className="size-8 shrink-0 text-muted-foreground hover:text-destructive"
                                            onClick={() => void discard(item)}
                                            disabled={
                                                busy || processingId === item.id
                                            }
                                            aria-label="Buang foto ini"
                                            title="Buang"
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                );
                            })}
                        </div>
                    </>
                )}

                {items.length === 0 && !upload && (
                    <p className="text-center text-sm text-muted-foreground">
                        Antrian kosong. Pilih foto-foto diecast untuk mulai.
                    </p>
                )}
            </div>
        </>
    );
}

BulkScan.layout = {
    breadcrumbs: [{ title: 'Scan massal', href: BulkScanController.index() }],
};
