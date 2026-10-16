import { Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Camera,
    ImagePlus,
    Loader2,
    ScanSearch,
    Sparkles,
    Star,
    Trash2,
    X,
} from 'lucide-react';
import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import DiecastController from '@/actions/App/Http/Controllers/DiecastController';
import InputError from '@/components/input-error';
import LocationField, { recentSavedPlace } from '@/components/location-field';
import MatchList from '@/components/match-list';
import MoneyInput from '@/components/money-input';
import PriceCheckPanel from '@/components/price-check-panel';
import SightingsPanel from '@/components/sightings-panel';
import TagInput from '@/components/tag-input';
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
import StatusToggle from '@/components/status-toggle';
import { postJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import {
    CONDITION_LABELS,
    PACKAGING_LABELS,
    RARITY_LABELS,
    SCALES,
} from '@/types/diecast';
import type {
    Condition,
    Diecast,
    DiecastStatus,
    DiecastSuggestions,
    Packaging,
    PriceCheck,
    Rarity,
    ScanFields,
    ScanItemPrefill,
    ScanResponse,
} from '@/types/diecast';

const COMMON_BRANDS = [
    'Hot Wheels',
    'Matchbox',
    'Tomica',
    'Mini GT',
    'Inno64',
    'Tarmac Works',
    'Pop Race',
    'Kaido House',
    'Majorette',
    'Greenlight',
    'Johnny Lightning',
    'M2 Machines',
    'Maisto',
    'Bburago',
];

const MAX_PHOTOS = 8;

const SECTION_TITLES: Record<DiecastStatus, string> = {
    owned: 'Kepemilikan & harga',
    wishlist: 'Buruan & target harga',
    spotted: 'Temuan',
    sold: 'Riwayat & harga',
};
const MAX_SCAN_PHOTOS = 4;

type FormData = {
    name: string;
    brand: string;
    make: string;
    series: string;
    collection_number: string;
    year: string;
    scale: string;
    color: string;
    rarity: Rarity;
    packaging: Packaging;
    condition: Condition | '';
    status: DiecastStatus;
    quantity: string;
    purchase_price: string;
    market_value: string;
    target_price: string;
    purchase_date: string;
    purchase_place: string;
    purchase_latitude: number | null;
    purchase_longitude: number | null;
    storage_location: string;
    notes: string;
    photos: string[];
    // First sighting, only when adding a new "Ditemui" diecast.
    sighting_place: string;
    sighting_latitude: number | null;
    sighting_longitude: number | null;
    sighting_date: string;
    sighting_price: string;
    tags: string[];
    ai_scanned: boolean;
};

type Photo = { path: string; url: string };

type ScanState =
    | { state: 'idle' }
    | { state: 'scanning' }
    | { state: 'done'; result: ScanResponse }
    | { state: 'failed'; message: string };

const CONFIDENCE_LABELS = {
    high: 'Yakin',
    medium: 'Cukup yakin',
    low: 'Kurang yakin — cek lagi',
};

const money = (value: string | null | undefined) =>
    value ? String(Math.round(Number(value))) : '';

function initialData(
    diecast: Diecast | undefined,
    status: DiecastStatus,
): FormData {
    return {
        name: diecast?.name ?? '',
        brand: diecast?.brand ?? '',
        make: diecast?.make ?? '',
        series: diecast?.series ?? '',
        collection_number: diecast?.collection_number ?? '',
        year: diecast?.year?.toString() ?? '',
        scale: diecast?.scale ?? '1:64',
        color: diecast?.color ?? '',
        rarity: diecast?.rarity ?? 'regular',
        packaging: diecast?.packaging ?? 'carded',
        condition: diecast?.condition ?? '',
        status: diecast?.status ?? status,
        quantity: diecast?.quantity.toString() ?? '1',
        purchase_price: money(diecast?.purchase_price),
        market_value: money(diecast?.market_value),
        target_price: money(diecast?.target_price),
        purchase_date:
            diecast?.purchase_date ??
            (diecast || status === 'wishlist'
                ? ''
                : new Date().toISOString().slice(0, 10)),
        purchase_place: diecast?.purchase_place ?? '',
        purchase_latitude: diecast?.purchase_latitude ?? null,
        purchase_longitude: diecast?.purchase_longitude ?? null,
        storage_location: diecast?.storage_location ?? '',
        notes: diecast?.notes ?? '',
        photos: diecast?.photos.map((p) => p.path) ?? [],
        sighting_place: '',
        sighting_latitude: null,
        sighting_longitude: null,
        sighting_date: new Date().toISOString().slice(0, 10),
        sighting_price: '',
        tags: diecast?.tags?.map((t) => t.name) ?? [],
        ai_scanned: diecast?.ai_scanned ?? false,
    };
}

/** Map AI fields onto form values; returns the values and which keys were filled. */
function fieldsFromScan(fields: ScanFields) {
    const next: Partial<FormData> = { ai_scanned: true };
    const filled = new Set<keyof FormData>();

    const textKeys = [
        'name',
        'brand',
        'make',
        'series',
        'collection_number',
        'scale',
        'color',
        'notes',
    ] as const;

    for (const key of textKeys) {
        if (fields[key]) {
            next[key] = fields[key];
            filled.add(key);
        }
    }

    if (fields.year) {
        next.year = String(fields.year);
        filled.add('year');
    }

    if (fields.rarity) {
        next.rarity = fields.rarity;

        if (fields.rarity !== 'regular') {
            filled.add('rarity');
        }
    }

    if (fields.packaging) {
        next.packaging = fields.packaging;
        filled.add('packaging');
    }

    if (fields.condition) {
        next.condition = fields.condition;
        filled.add('condition');
    }

    return { next, filled };
}

export default function DiecastForm({
    diecast,
    status = 'owned',
    suggestions,
    scanEnabled,
    scanItem,
    returnTo,
    priceChecks = [],
}: {
    diecast?: Diecast;
    status?: DiecastStatus;
    suggestions: DiecastSuggestions;
    scanEnabled: boolean;
    scanItem?: ScanItemPrefill | null;
    returnTo?: string;
    priceChecks?: PriceCheck[];
}) {
    const prefill = scanItem?.fields ? fieldsFromScan(scanItem.fields) : null;

    const form = useForm<FormData>(() => {
        const data = initialData(diecast, status);

        if (scanItem) {
            data.photos = [scanItem.photo_path];
            Object.assign(data, prefill?.next ?? {});
        }

        // Just checked/spotted in a store (location taken < 3 h ago)? That's probably where it's from.
        const here = diecast ? null : recentSavedPlace();

        if (here) {
            Object.assign(data, {
                purchase_place: here.name,
                purchase_latitude: here.lat ?? null,
                purchase_longitude: here.lng ?? null,
                sighting_place: here.name,
                sighting_latitude: here.lat ?? null,
                sighting_longitude: here.lng ?? null,
            });
        }

        return data;
    });
    const { data, setData, errors, processing } = form;

    const [photos, setPhotos] = useState<Photo[]>(() =>
        scanItem
            ? [{ path: scanItem.photo_path, url: scanItem.photo_url }]
            : (diecast?.photos.map((p) => ({ path: p.path, url: p.url })) ??
              []),
    );
    const [selected, setSelected] = useState(0);
    const [uploading, setUploading] = useState(0);
    const [uploadError, setUploadError] = useState<string | null>(null);
    const [scan, setScan] = useState<ScanState>(() =>
        scanItem?.fields
            ? {
                  state: 'done',
                  result: {
                      fields: scanItem.fields,
                      error: null,
                      matches: scanItem.matches,
                  },
              }
            : { state: 'idle' },
    );
    const [aiFields, setAiFields] = useState<Set<keyof FormData>>(
        () => prefill?.filled ?? new Set(),
    );
    const [dragging, setDragging] = useState(false);
    const [priceSource, setPriceSource] = useState(
        diecast?.market_value_source ?? null,
    );
    const cameraInput = useRef<HTMLInputElement>(null);
    const galleryInput = useRef<HTMLInputElement>(null);

    const brandOptions = Array.from(
        new Set([...suggestions.brand, ...COMMON_BRANDS]),
    );
    const busy = uploading > 0 || scan.state === 'scanning';

    function changeStatus(status: DiecastStatus) {
        setData((current) => ({
            ...current,
            status,
            // Moving a new entry out of the wishlist: it was bought today unless told otherwise.
            purchase_date:
                !diecast && status !== 'wishlist' && !current.purchase_date
                    ? new Date().toISOString().slice(0, 10)
                    : current.purchase_date,
        }));
    }

    function update<K extends keyof FormData>(key: K, value: FormData[K]) {
        setData((current) => ({ ...current, [key]: value }));

        if (aiFields.has(key)) {
            const next = new Set(aiFields);
            next.delete(key);
            setAiFields(next);
        }
    }

    function changePhotos(next: Photo[]) {
        setPhotos(next);
        setData((current) => ({ ...current, photos: next.map((p) => p.path) }));
    }

    async function runScan(paths: string[]) {
        setScan({ state: 'scanning' });

        try {
            const result = await postJson<ScanResponse>(
                DiecastController.scan.url(),
                { photos: paths.slice(0, MAX_SCAN_PHOTOS) },
            );

            if (result.fields) {
                const { next, filled } = fieldsFromScan(result.fields);
                setData((current) => ({ ...current, ...next }));
                setAiFields(filled);
            }

            setScan({ state: 'done', result });
        } catch (error) {
            setScan({ state: 'failed', message: (error as Error).message });
        }
    }

    async function addFiles(files: FileList | File[] | null | undefined) {
        const images = Array.from(files ?? []).filter((f) =>
            f.type.startsWith('image/'),
        );
        const room = MAX_PHOTOS - photos.length;

        if (!images.length) {
            return;
        }

        setUploadError(
            images.length > room ? `Maksimal ${MAX_PHOTOS} foto.` : null,
        );

        const wasEmpty = photos.length === 0;
        let current = photos;
        setUploading(Math.min(images.length, room));

        for (const file of images.slice(0, room)) {
            const body = new FormData();
            body.append('photo', file);

            try {
                const uploaded = await postJson<Photo>(
                    DiecastController.uploadPhoto.url(),
                    body,
                );
                current = [...current, uploaded];
                changePhotos(current);
            } catch (error) {
                setUploadError(
                    error instanceof TypeError
                        ? 'Koneksi terputus saat upload. Coba lagi.'
                        : (error as Error).message,
                );
            }

            setUploading((n) => n - 1);
        }

        // First photo(s) on a new diecast: let the AI fill the form straight away.
        if (wasEmpty && !diecast && scanEnabled && current.length) {
            void runScan(current.map((p) => p.path));
        }
    }

    function removePhoto(index: number) {
        changePhotos(photos.filter((_, i) => i !== index));
        setSelected(0);
    }

    function makeCover(index: number) {
        changePhotos([photos[index], ...photos.filter((_, i) => i !== index)]);
        setSelected(0);
    }

    function submit(addAnother: boolean) {
        form.transform(
            ({
                sighting_place,
                sighting_latitude,
                sighting_longitude,
                sighting_date,
                sighting_price,
                ...current
            }) => {
                // Not in the garage: no purchase/storage details to keep.
                const notOwned =
                    current.status === 'wishlist' ||
                    current.status === 'spotted';

                return {
                    ...current,
                    condition: current.condition || null,
                    target_price:
                        current.status === 'wishlist'
                            ? current.target_price
                            : null,
                    ...(notOwned && {
                        purchase_price: null,
                        purchase_date: null,
                        purchase_place: null,
                        purchase_latitude: null,
                        purchase_longitude: null,
                        storage_location: null,
                    }),
                    sighting:
                        current.status === 'spotted' && !diecast
                            ? {
                                  place: sighting_place,
                                  latitude: sighting_latitude,
                                  longitude: sighting_longitude,
                                  seen_on: sighting_date || null,
                                  price: sighting_price || null,
                              }
                            : null,
                    add_another: addAnother,
                    scan_item_id: scanItem?.id ?? null,
                    return_to: returnTo ?? null,
                };
            },
        );

        if (diecast) {
            form.submit(DiecastController.update(diecast.id));

            return;
        }

        form.submit(DiecastController.store(), {
            onSuccess: () => {
                if (addAnother) {
                    form.reset();
                    setPhotos([]);
                    setScan({ state: 'idle' });
                    setAiFields(new Set());
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            },
        });
    }

    const field = (key: keyof FormData, label: string, children: ReactNode) => (
        <div className="grid gap-2">
            <Label htmlFor={key} className="flex items-center gap-1.5">
                {label}
                {aiFields.has(key) && (
                    <Sparkles
                        className="size-3.5 text-brand"
                        aria-label="Diisi oleh AI"
                    />
                )}
            </Label>
            {children}
            <InputError message={errors[key]} />
        </div>
    );

    const aiRing = (key: keyof FormData) =>
        aiFields.has(key) && 'border-brand/60 bg-brand/8';

    const textInput = (
        key: keyof FormData,
        label: string,
        props: React.ComponentProps<typeof Input> = {},
    ) =>
        field(
            key,
            label,
            <Input
                id={key}
                value={String(data[key] ?? '')}
                onChange={(e) =>
                    update(key, e.target.value as FormData[typeof key])
                }
                className={cn(aiRing(key))}
                aria-invalid={!!errors[key]}
                {...props}
            />,
        );

    const optionSelect = <T extends string>(
        key: 'rarity' | 'packaging',
        label: string,
        options: Record<T, string>,
    ) =>
        field(
            key,
            label,
            <Select
                value={data[key]}
                onValueChange={(v) => update(key, v as FormData[typeof key])}
            >
                <SelectTrigger id={key} className={cn('w-full', aiRing(key))}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {Object.entries<string>(options).map(([value, text]) => (
                        <SelectItem key={value} value={value}>
                            {text}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>,
        );

    const current = photos[selected] ?? photos[0];

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                submit(false);
            }}
            className="grid gap-6 lg:grid-cols-[minmax(0,380px)_1fr]"
        >
            {/* Mode first: it decides which ownership fields appear below. */}
            <div className="lg:col-span-2">
                <StatusToggle value={data.status} onChange={changeStatus} />
                <InputError message={errors.status} />
            </div>

            {/* Photos + AI scan */}
            <div className="space-y-3 lg:sticky lg:top-4 lg:self-start">
                <div
                    onDragOver={(e) => {
                        e.preventDefault();
                        setDragging(true);
                    }}
                    onDragLeave={() => setDragging(false)}
                    onDrop={(e) => {
                        e.preventDefault();
                        setDragging(false);
                        void addFiles(e.dataTransfer.files);
                    }}
                    className={cn(
                        'relative flex aspect-square items-center justify-center overflow-hidden rounded-xl border-2 border-dashed border-sidebar-border bg-muted/40 transition-colors',
                        dragging && 'border-primary bg-primary/5',
                    )}
                >
                    {current ? (
                        <img
                            src={current.url}
                            alt="Foto diecast"
                            className="size-full object-contain"
                        />
                    ) : (
                        <div className="flex flex-col items-center gap-2 p-6 text-center text-sm text-muted-foreground">
                            <ImagePlus className="size-10 opacity-60" />
                            <p className="font-medium text-foreground">
                                Foto diecast kamu
                            </p>
                            <p>
                                {scanEnabled
                                    ? 'Foto depan kartunya agar AI bisa membaca nama, seri, dan nomornya. Bisa tambah foto belakang kartu juga.'
                                    : 'Seret foto ke sini atau pilih dari galeri.'}
                            </p>
                        </div>
                    )}

                    {busy && (
                        <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-background/80 backdrop-blur-sm">
                            <Loader2 className="size-8 animate-spin text-primary" />
                            <p className="text-sm font-medium">
                                {uploading > 0
                                    ? `Mengupload ${uploading} foto…`
                                    : 'AI sedang membaca foto…'}
                            </p>
                        </div>
                    )}
                </div>

                {photos.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {photos.map((photo, index) => (
                            <div
                                key={photo.path}
                                className={cn(
                                    'group relative size-16 overflow-hidden rounded-md border-2',
                                    index === selected
                                        ? 'border-primary'
                                        : 'border-transparent',
                                )}
                            >
                                <button
                                    type="button"
                                    onClick={() => setSelected(index)}
                                    className="size-full"
                                >
                                    <img
                                        src={photo.url}
                                        alt=""
                                        className="size-full object-cover"
                                    />
                                </button>
                                {index === 0 && (
                                    <span className="absolute bottom-0 left-0 bg-black/60 px-1 text-[10px] text-white">
                                        Cover
                                    </span>
                                )}
                                <div className="absolute top-0.5 right-0.5 flex gap-0.5 opacity-100 transition sm:opacity-0 sm:group-hover:opacity-100">
                                    {index > 0 && (
                                        <button
                                            type="button"
                                            onClick={() => makeCover(index)}
                                            className="rounded-full bg-black/60 p-0.5 text-white"
                                            aria-label="Jadikan cover"
                                            title="Jadikan cover"
                                        >
                                            <Star className="size-3" />
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        onClick={() => removePhoto(index)}
                                        className="rounded-full bg-black/60 p-0.5 text-white"
                                        aria-label="Hapus foto"
                                    >
                                        <X className="size-3" />
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}

                <div className="grid grid-cols-2 gap-2">
                    <Button
                        type="button"
                        onClick={() => cameraInput.current?.click()}
                        disabled={busy || photos.length >= MAX_PHOTOS}
                    >
                        <Camera /> {photos.length ? 'Foto lagi' : 'Ambil foto'}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => galleryInput.current?.click()}
                        disabled={busy || photos.length >= MAX_PHOTOS}
                    >
                        <ImagePlus /> Galeri
                    </Button>
                </div>

                {scanEnabled && photos.length > 0 && (
                    <Button
                        type="button"
                        variant="secondary"
                        className="w-full"
                        disabled={busy}
                        onClick={() => void runScan(data.photos)}
                    >
                        <ScanSearch />
                        {scan.state === 'idle'
                            ? 'Isi otomatis dengan AI'
                            : 'Baca ulang dengan AI'}
                        {photos.length > 1 &&
                            ` (${Math.min(photos.length, MAX_SCAN_PHOTOS)} foto)`}
                    </Button>
                )}

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

                {uploadError && (
                    <p className="text-sm text-destructive">{uploadError}</p>
                )}
                <InputError message={errors.photos} />
                <ScanFeedback scan={scan} />
            </div>

            {/* Fields */}
            <div className="space-y-6">
                <section className="surface space-y-4 rounded-xl p-4 md:p-5">
                    <h3 className="font-heading text-base font-semibold">
                        Identitas
                    </h3>

                    {textInput('name', 'Nama model *', {
                        placeholder: "'71 Datsun 510",
                        required: true,
                    })}

                    <div className="grid gap-4 sm:grid-cols-2">
                        {textInput('brand', 'Merek', {
                            list: 'brand-options',
                            placeholder: 'Hot Wheels',
                        })}
                        {textInput('make', 'Pabrikan mobil', {
                            placeholder: 'Nissan',
                        })}
                        {textInput('series', 'Seri', {
                            list: 'series-options',
                            placeholder: 'HW J-Imports',
                        })}
                        {textInput('collection_number', 'Nomor koleksi', {
                            placeholder: '123/250',
                        })}
                        {textInput('color', 'Warna', {
                            placeholder: 'Metallic Blue',
                        })}
                        {textInput('year', 'Tahun rilis', {
                            type: 'number',
                            inputMode: 'numeric',
                            placeholder: '2026',
                        })}
                        {textInput('scale', 'Skala', { list: 'scale-options' })}
                        {optionSelect('rarity', 'Kelangkaan', RARITY_LABELS)}
                        {optionSelect('packaging', 'Kemasan', PACKAGING_LABELS)}
                        {field(
                            'condition',
                            'Kondisi',
                            <Select
                                value={data.condition || 'unknown'}
                                onValueChange={(v) =>
                                    update(
                                        'condition',
                                        v === 'unknown' ? '' : (v as Condition),
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="condition"
                                    className={cn(
                                        'w-full',
                                        aiRing('condition'),
                                    )}
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="unknown">
                                        Belum dinilai
                                    </SelectItem>
                                    {Object.entries(CONDITION_LABELS).map(
                                        ([value, label]) => (
                                            <SelectItem
                                                key={value}
                                                value={value}
                                            >
                                                {label}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>,
                        )}
                    </div>
                </section>

                <section className="surface space-y-4 rounded-xl p-4 md:p-5">
                    <h3 className="font-heading text-base font-semibold">
                        {SECTION_TITLES[data.status]}
                    </h3>

                    <div className="grid gap-4 sm:grid-cols-2">
                        {data.status !== 'spotted' &&
                            textInput('quantity', 'Jumlah', {
                                type: 'number',
                                min: 1,
                                inputMode: 'numeric',
                                required: true,
                            })}

                        {data.status === 'spotted' ? (
                            diecast ? (
                                <p className="text-sm text-muted-foreground sm:col-span-2">
                                    Tempat, tanggal, dan harga setiap kali
                                    ditemui ada di{' '}
                                    <strong>Catatan temuan</strong> di bawah.
                                </p>
                            ) : (
                                <>
                                    {field(
                                        'sighting_place',
                                        'Ditemui di',
                                        <LocationField
                                            value={{
                                                name: data.sighting_place,
                                                lat: data.sighting_latitude,
                                                lng: data.sighting_longitude,
                                            }}
                                            onChange={(place) =>
                                                setData((current) => ({
                                                    ...current,
                                                    sighting_place: place.name,
                                                    sighting_latitude:
                                                        place.lat ?? null,
                                                    sighting_longitude:
                                                        place.lng ?? null,
                                                }))
                                            }
                                            places={suggestions.purchase_place}
                                            placeholder="Toys Kingdom GI, teman, event…"
                                        />,
                                    )}
                                    {textInput('sighting_date', 'Tanggal', {
                                        type: 'date',
                                    })}
                                    {field(
                                        'sighting_price',
                                        'Harga di sana',
                                        <MoneyInput
                                            id="sighting_price"
                                            placeholder="Opsional"
                                            value={data.sighting_price}
                                            onChange={(e) =>
                                                update(
                                                    'sighting_price',
                                                    e.target.value,
                                                )
                                            }
                                        />,
                                    )}
                                    <InputError
                                        className="sm:col-span-2"
                                        message={
                                            (errors as Record<string, string>)[
                                                'sighting.place'
                                            ] ??
                                            (errors as Record<string, string>)[
                                                'sighting.seen_on'
                                            ] ??
                                            (errors as Record<string, string>)[
                                                'sighting.price'
                                            ]
                                        }
                                    />
                                </>
                            )
                        ) : data.status === 'wishlist' ? (
                            field(
                                'target_price',
                                'Target harga maksimal',
                                <MoneyInput
                                    id="target_price"
                                    placeholder="500000"
                                    value={data.target_price}
                                    onChange={(e) =>
                                        update('target_price', e.target.value)
                                    }
                                />,
                            )
                        ) : (
                            <>
                                {field(
                                    'purchase_price',
                                    'Harga beli (per pcs)',
                                    <MoneyInput
                                        id="purchase_price"
                                        placeholder="35000"
                                        value={data.purchase_price}
                                        onChange={(e) =>
                                            update(
                                                'purchase_price',
                                                e.target.value,
                                            )
                                        }
                                    />,
                                )}
                                {field(
                                    'market_value',
                                    'Harga pasaran sekarang (per pcs)',
                                    <>
                                        <MoneyInput
                                            id="market_value"
                                            placeholder="Opsional"
                                            value={data.market_value}
                                            onChange={(e) => {
                                                update(
                                                    'market_value',
                                                    e.target.value,
                                                );
                                                setPriceSource('manual');
                                            }}
                                        />
                                        {data.market_value &&
                                            priceSource === 'price_check' && (
                                                <p className="text-xs text-muted-foreground">
                                                    Dari cek harga screenshot
                                                    marketplace
                                                </p>
                                            )}
                                    </>,
                                )}
                                {textInput('purchase_date', 'Tanggal beli', {
                                    type: 'date',
                                })}
                                {field(
                                    'purchase_place',
                                    'Beli di',
                                    <LocationField
                                        value={{
                                            name: data.purchase_place,
                                            lat: data.purchase_latitude,
                                            lng: data.purchase_longitude,
                                        }}
                                        onChange={(place) =>
                                            setData((current) => ({
                                                ...current,
                                                purchase_place: place.name,
                                                purchase_latitude:
                                                    place.lat ?? null,
                                                purchase_longitude:
                                                    place.lng ?? null,
                                            }))
                                        }
                                        places={suggestions.purchase_place}
                                        placeholder="Toko / marketplace / teman"
                                    />,
                                )}
                                {textInput(
                                    'storage_location',
                                    'Lokasi simpan',
                                    {
                                        list: 'storage-options',
                                        placeholder: 'Rak A / Box 3',
                                    },
                                )}
                            </>
                        )}
                    </div>

                    {field(
                        'tags',
                        'Tag',
                        <TagInput
                            id="tags"
                            value={data.tags}
                            onChange={(tags) => update('tags', tags)}
                            suggestions={suggestions.tags}
                        />,
                    )}

                    {field(
                        'notes',
                        'Catatan',
                        <textarea
                            id="notes"
                            rows={3}
                            value={data.notes}
                            onChange={(e) => update('notes', e.target.value)}
                            className={cn(
                                'w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm',
                                aiRing('notes'),
                            )}
                            placeholder="Varian, kondisi kartu, cerita dapetinnya…"
                        />,
                    )}
                </section>

                {diecast && (
                    <SightingsPanel
                        diecast={diecast}
                        places={suggestions.purchase_place}
                    />
                )}

                {diecast && (
                    <PriceCheckPanel
                        diecast={diecast}
                        initialChecks={priceChecks}
                        onApplied={(value) => {
                            update('market_value', String(value));
                            setPriceSource('price_check');
                        }}
                    />
                )}

                <datalist id="brand-options">
                    {brandOptions.map((brand) => (
                        <option key={brand} value={brand} />
                    ))}
                </datalist>
                <datalist id="series-options">
                    {suggestions.series.map((series) => (
                        <option key={series} value={series} />
                    ))}
                </datalist>
                <datalist id="scale-options">
                    {SCALES.map((scale) => (
                        <option key={scale} value={scale} />
                    ))}
                </datalist>
                <datalist id="place-options">
                    {suggestions.purchase_place.map((place) => (
                        <option key={place} value={place} />
                    ))}
                </datalist>
                <datalist id="storage-options">
                    {suggestions.storage_location.map((location) => (
                        <option key={location} value={location} />
                    ))}
                </datalist>

                <div className="flex flex-wrap items-center gap-3">
                    <Button type="submit" disabled={processing || busy}>
                        {processing && <Loader2 className="animate-spin" />}
                        {diecast ? 'Simpan perubahan' : 'Simpan'}
                    </Button>
                    {!diecast && !scanItem && (
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={processing || busy}
                            onClick={() => submit(true)}
                        >
                            <Camera /> Simpan & tambah lagi
                        </Button>
                    )}
                    <Button type="button" variant="ghost" asChild>
                        <Link href={DiecastController.index()}>Batal</Link>
                    </Button>
                    {diecast && <DeleteButton diecast={diecast} />}
                </div>
            </div>
        </form>
    );
}

function ScanFeedback({ scan }: { scan: ScanState }) {
    if (scan.state === 'failed') {
        return (
            <div className="flex gap-2 rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive">
                <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                {scan.message}
            </div>
        );
    }

    if (scan.state !== 'done') {
        return null;
    }

    const { fields, error, matches } = scan.result;

    return (
        <div className="space-y-3">
            {error && (
                <div className="flex gap-2 rounded-lg border border-amber-400/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                    {error}
                </div>
            )}

            {fields && (
                <div className="flex items-start gap-2 rounded-lg border border-brand/40 bg-brand/10 p-3 text-sm">
                    <Sparkles className="mt-0.5 size-4 shrink-0 text-brand" />
                    <div>
                        <p className="font-medium">
                            Field bertanda ✦ diisi oleh AI.
                        </p>
                        <p className="text-muted-foreground">
                            Keyakinan:{' '}
                            {CONFIDENCE_LABELS[fields.confidence ?? 'medium']}.
                            Periksa dulu sebelum menyimpan.
                        </p>
                    </div>
                </div>
            )}

            {matches.length > 0 && (
                <div className="rounded-lg border border-sky-400/50 bg-sky-50 p-3 text-sm dark:bg-sky-500/10">
                    <p className="mb-2 font-medium">
                        Mirip dengan yang sudah ada di koleksimu:
                    </p>
                    <MatchList matches={matches} />
                </div>
            )}
        </div>
    );
}

function DeleteButton({ diecast }: { diecast: Diecast }) {
    const [confirming, setConfirming] = useState(false);

    if (!confirming) {
        return (
            <Button
                type="button"
                variant="ghost"
                className="ml-auto text-destructive hover:text-destructive"
                onClick={() => setConfirming(true)}
            >
                <Trash2 /> Hapus
            </Button>
        );
    }

    return (
        <div className="ml-auto flex items-center gap-2 text-sm">
            Yakin hapus?
            <Button type="button" variant="destructive" size="sm" asChild>
                <Link href={DiecastController.destroy(diecast.id)} as="button">
                    Ya, hapus
                </Link>
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => setConfirming(false)}
            >
                Batal
            </Button>
        </div>
    );
}
