import 'leaflet/dist/leaflet.css';
import type { LeafletMouseEvent, Map as LeafletMap } from 'leaflet';
import { History, Loader2, LocateFixed, MapPin, Search } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import {
    Circle,
    CircleMarker,
    MapContainer,
    TileLayer,
    useMapEvents,
} from 'react-leaflet';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    currentPosition,
    geolocationProblem,
    lookupPlace,
    searchPlaces,
} from '@/lib/geo';
import type { GpsFix, PlaceName, PlaceResult } from '@/lib/geo';
import { cn } from '@/lib/utils';

export type PickedPlace = {
    name: string;
    lat: number;
    lng: number;
    /** GPS accuracy in meters, only when the pin is still where GPS put it. */
    accuracy: number | null;
};

type LatLng = { lat: number; lng: number };

type Lookup =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'done'; result: PlaceName };

const DEFAULT_CENTER: LatLng = { lat: -6.2, lng: 106.82 }; // Jakarta
const CLOSE_ZOOM = 17;
/** The usual "you are here" blue, so it can't be mistaken for the pin. */
const GPS_BLUE = '#3b82f6';

const round = (n: number) => Math.round(n * 1e6) / 1e6;

function MapEvents(handlers: {
    dragstart: () => void;
    moveend: (e: { target: LeafletMap }) => void;
    click: (e: LeafletMouseEvent) => void;
}) {
    useMapEvents(handlers);

    return null;
}

/**
 * Map preview for a location: the pin stays in the middle and the map moves under it,
 * so a GPS fix taken at home can be dragged to the store before saving.
 */
export default function LocationPicker({
    open,
    onOpenChange,
    initial,
    onPick,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    initial: { name: string; lat?: number | null; lng?: number | null };
    onPick: (place: PickedPlace) => void;
}) {
    const hasInitial = initial.lat != null && initial.lng != null;
    const start: LatLng = hasInitial
        ? { lat: initial.lat!, lng: initial.lng! }
        : DEFAULT_CENTER;
    const problem = geolocationProblem();
    const nameId = useId();

    const [map, setMap] = useState<LeafletMap | null>(null);
    const [center, setCenter] = useState<LatLng>(start);
    // False until something real put the pin somewhere (not just the default view).
    const [placed, setPlaced] = useState(hasInitial);
    const [moving, setMoving] = useState(false);

    const [name, setName] = useState(initial.name);
    const [lookup, setLookup] = useState<Lookup>({ status: 'idle' });
    // A name the user typed or picked stays put; a looked-up one follows the pin.
    const nameLocked = useRef(initial.name.trim() !== '');

    const [gps, setGps] = useState<GpsFix | null>(null);
    const [atGps, setAtGps] = useState(false);
    const [locating, setLocating] = useState(false);
    const [gpsError, setGpsError] = useState<string | null>(null);

    const [query, setQuery] = useState(hasInitial ? '' : initial.name);
    const [results, setResults] = useState<PlaceResult[] | null>(null);
    const [recent, setRecent] = useState<PlaceResult[]>([]);
    const [searching, setSearching] = useState(false);

    const userMoved = useRef(false);
    const skipLookup = useRef(false);
    const lookupSeq = useRef(0);
    const lookupTimer = useRef<number | undefined>(undefined);
    // The pin position (≈1 m) the current name belongs to.
    const lookedUp = useRef<string | null>(null);

    function goTo(point: LatLng, { lookup = true } = {}) {
        if (!map) {
            return;
        }

        skipLookup.current = !lookup;
        map.flyTo(
            [point.lat, point.lng],
            Math.max(map.getZoom(), CLOSE_ZOOM),
            { duration: 0.6 },
        );
        setPlaced(true);
    }

    function choose(place: PlaceResult) {
        userMoved.current = true;
        nameLocked.current = true;
        setName(place.name);
        setLookup({
            status: 'done',
            result: {
                name: place.name,
                address: place.address,
                source: place.source,
                distance: null,
            },
        });
        setResults(null);
        setAtGps(false);
        goTo(place, { lookup: false });
    }

    async function locate(auto = false) {
        setLocating(true);
        setGpsError(null);

        try {
            const fix = await currentPosition();
            setGps(fix);

            // On open, don't yank the map away if the user already moved it.
            if (!auto || !userMoved.current) {
                setAtGps(true);
                goTo(fix);
            }
        } catch (e) {
            if (!auto) {
                setGpsError((e as Error).message);
            }
        }

        setLocating(false);
    }

    async function runSearch(text = query): Promise<PlaceResult[]> {
        const q = text.trim();

        if (!q) {
            setResults(null);

            return [];
        }

        setSearching(true);

        try {
            const near = map?.getCenter() ?? center;
            const found = await searchPlaces(q, {
                lat: round(near.lat),
                lng: round(near.lng),
            });
            setResults(found);

            return found;
        } catch {
            setResults([]);

            return [];
        } finally {
            setSearching(false);
        }
    }

    function handleMoveEnd(target: LeafletMap) {
        const c = target.getCenter();
        const spot = `${c.lat.toFixed(5)},${c.lng.toFixed(5)}`;
        setCenter({ lat: c.lat, lng: c.lng });
        setMoving(false);

        // Same spot (e.g. the map resized when the keyboard opened): keep the name we have or are fetching.
        if (spot === lookedUp.current) {
            return;
        }

        window.clearTimeout(lookupTimer.current);

        if (skipLookup.current) {
            skipLookup.current = false;
            lookedUp.current = spot;
            // A lookup cancelled by this move must not leave "Mencari…" behind.
            setLookup((current) =>
                current.status === 'loading' ? { status: 'idle' } : current,
            );

            return;
        }

        if (!placed) {
            return;
        }

        lookedUp.current = spot;
        setLookup({ status: 'loading' });

        // Wait until the map settles: OpenStreetMap allows about one request per second.
        lookupTimer.current = window.setTimeout(async () => {
            const seq = ++lookupSeq.current;
            const result = await lookupPlace(round(c.lat), round(c.lng));

            if (seq !== lookupSeq.current) {
                return;
            }

            setLookup({ status: 'done', result });

            if (!nameLocked.current && result.name) {
                setName(result.name);
            }
        }, 700);
    }

    function userTouchedMap() {
        userMoved.current = true;
        skipLookup.current = false;
        setPlaced(true);
        setAtGps(false);
    }

    // Recent places as quick picks; with nothing else to go on, start the map near the latest one.
    useEffect(() => {
        searchPlaces('')
            .then(setRecent)
            .catch(() => setRecent([]));
    }, []);

    useEffect(() => {
        if (
            map &&
            recent.length &&
            !hasInitial &&
            !userMoved.current &&
            !atGps
        ) {
            skipLookup.current = true;
            map.setView([recent[0].lat, recent[0].lng], 14);
        }
        // Only when the recent list arrives.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [map, recent]);

    // Once the map exists: fix its size after the dialog animation, then find a starting point.
    useEffect(() => {
        if (!map) {
            return;
        }

        const timer = window.setTimeout(() => map.invalidateSize(), 250);

        if (!hasInitial) {
            if (!problem) {
                void locate(true);
            }

            // A name but no point yet: look it up; a single match from your own history is surely it.
            if (initial.name.trim()) {
                const typed = initial.name.trim().toLowerCase();

                void runSearch(initial.name).then((found) => {
                    const same = found.filter(
                        (p) =>
                            p.source === 'history' &&
                            p.name.toLowerCase() === typed,
                    );

                    if (same.length === 1 && !userMoved.current) {
                        choose(same[0]);
                    }
                });
            }
        }

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [map]);

    useEffect(() => () => window.clearTimeout(lookupTimer.current), []);

    const waitingForName = lookup.status === 'loading' && !nameLocked.current;

    function confirm() {
        const c = map?.getCenter() ?? center;
        onPick({
            name: name.trim(),
            lat: round(c.lat),
            lng: round(c.lng),
            accuracy: atGps && gps ? gps.accuracy : null,
        });
        onOpenChange(false);
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="max-h-[94dvh] gap-3 overflow-y-auto p-4 sm:max-w-xl sm:p-6"
                // Focusing the search box would pop up the phone keyboard over the map.
                onOpenAutoFocus={(e) => e.preventDefault()}
            >
                <DialogHeader>
                    <DialogTitle className="font-heading">
                        Pilih lokasi
                    </DialogTitle>
                    <DialogDescription>
                        Geser peta sampai pin tepat di tempatnya, atau cari
                        nama tempatnya.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex gap-2">
                    <div className="relative flex-1">
                        <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            aria-label="Cari tempat"
                            placeholder="Cari tempat, mis. Toys Kingdom GI"
                            value={query}
                            onChange={(e) => {
                                setQuery(e.target.value);

                                if (!e.target.value) {
                                    setResults(null);
                                }
                            }}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    void runSearch();
                                }
                            }}
                            className="pl-8"
                        />
                    </div>
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => void runSearch()}
                        disabled={searching || !query.trim()}
                    >
                        {searching ? (
                            <Loader2 className="animate-spin" />
                        ) : (
                            'Cari'
                        )}
                    </Button>
                </div>

                {results ? (
                    <PlaceList places={results} onChoose={choose} />
                ) : (
                    recent.length > 0 && (
                        <div className="-mx-1 flex gap-1.5 overflow-x-auto px-1 [scrollbar-width:none]">
                            {recent.map((place) => (
                                <button
                                    key={`${place.name}:${place.lat}`}
                                    type="button"
                                    onClick={() => choose(place)}
                                    className="flex shrink-0 items-center gap-1 rounded-full border px-2.5 py-1 text-xs text-muted-foreground transition hover:border-brand/60 hover:text-foreground"
                                >
                                    <History className="size-3 text-brand" />
                                    {place.name}
                                </button>
                            ))}
                        </div>
                    )
                )}

                <div className="relative overflow-hidden rounded-lg border">
                    <MapContainer
                        center={[start.lat, start.lng]}
                        zoom={hasInitial ? CLOSE_ZOOM : 11}
                        ref={setMap}
                        className="h-[42dvh] max-h-[380px] min-h-[240px] w-full"
                    >
                        <TileLayer
                            attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
                            url="https://tile.openstreetmap.org/{z}/{x}/{y}.png"
                            className="map-tiles-dark"
                            maxZoom={19}
                        />
                        {gps && (
                            <>
                                <Circle
                                    center={[gps.lat, gps.lng]}
                                    radius={gps.accuracy}
                                    interactive={false}
                                    pathOptions={{
                                        color: GPS_BLUE,
                                        weight: 1,
                                        fillOpacity: 0.12,
                                    }}
                                />
                                <CircleMarker
                                    center={[gps.lat, gps.lng]}
                                    radius={6}
                                    interactive={false}
                                    pathOptions={{
                                        color: '#ffffff',
                                        weight: 2,
                                        fillColor: GPS_BLUE,
                                        fillOpacity: 1,
                                    }}
                                />
                            </>
                        )}
                        <MapEvents
                            dragstart={() => {
                                userTouchedMap();
                                setMoving(true);
                            }}
                            moveend={(e) => handleMoveEnd(e.target)}
                            click={(e) => {
                                userTouchedMap();
                                e.target.panTo(e.latlng);
                            }}
                        />
                    </MapContainer>

                    {/* The pin stays centered; its tip marks the spot. */}
                    <div className="pointer-events-none absolute bottom-1/2 left-1/2 z-[500] -translate-x-1/2">
                        <MapPin
                            strokeWidth={1.5}
                            className={cn(
                                'size-10 fill-brand stroke-background drop-shadow-lg transition-transform',
                                moving ? '-translate-y-2' : 'translate-y-[8%]',
                            )}
                        />
                    </div>
                    <span className="pointer-events-none absolute top-1/2 left-1/2 z-[499] size-1.5 -translate-1/2 rounded-full bg-black/60" />

                    <button
                        type="button"
                        onClick={() => void locate()}
                        disabled={locating}
                        className="absolute right-2 bottom-7 z-[500] flex items-center gap-1.5 rounded-md border bg-background/90 px-2.5 py-1.5 text-xs font-medium shadow transition hover:bg-background disabled:opacity-70"
                    >
                        {locating ? (
                            <Loader2 className="size-4 animate-spin" />
                        ) : (
                            <LocateFixed className="size-4 text-brand" />
                        )}
                        Lokasi saya
                    </button>
                </div>

                {gpsError && (
                    <p className="text-xs text-destructive">{gpsError}</p>
                )}
                {problem === 'insecure' && <InsecureHelp />}

                <div className="space-y-1.5">
                    <Label htmlFor={nameId}>Nama tempat</Label>
                    <Input
                        id={nameId}
                        placeholder="Nama toko / tempat"
                        value={name}
                        onChange={(e) => {
                            setName(e.target.value);
                            nameLocked.current = e.target.value.trim() !== '';
                        }}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                            }
                        }}
                    />
                    <NameNote
                        lookup={lookup}
                        name={name}
                        onUse={(suggested) => {
                            setName(suggested);
                            nameLocked.current = true;
                        }}
                    />
                    {placed && (
                        <p className="text-[11px] text-muted-foreground tabular-nums">
                            📍 {center.lat.toFixed(5)}, {center.lng.toFixed(5)}
                            {atGps && gps ? ` · GPS ±${gps.accuracy} m` : ''}
                        </p>
                    )}
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                    >
                        Batal
                    </Button>
                    <Button
                        type="button"
                        onClick={confirm}
                        disabled={!placed || waitingForName}
                    >
                        {!placed
                            ? 'Geser peta ke lokasinya'
                            : waitingForName
                              ? 'Mencari nama tempat…'
                              : 'Pakai lokasi ini'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function PlaceList({
    places,
    onChoose,
}: {
    places: PlaceResult[];
    onChoose: (place: PlaceResult) => void;
}) {
    if (!places.length) {
        return (
            <p className="text-sm text-muted-foreground">
                Tidak ditemukan. Coba nama lain, atau geser peta ke lokasinya.
            </p>
        );
    }

    return (
        <ul className="max-h-48 divide-y overflow-y-auto rounded-lg border">
            {places.map((place) => (
                <li key={`${place.source}:${place.name}:${place.lat}`}>
                    <button
                        type="button"
                        onClick={() => onChoose(place)}
                        className="flex w-full items-start gap-2.5 px-3 py-2 text-left transition hover:bg-muted/60"
                    >
                        {place.source === 'history' ? (
                            <History className="mt-0.5 size-4 shrink-0 text-brand" />
                        ) : (
                            <MapPin className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        )}
                        <span className="min-w-0">
                            <span className="block truncate text-sm font-medium">
                                {place.name}
                            </span>
                            <span className="block truncate text-xs text-muted-foreground">
                                {place.source === 'history'
                                    ? 'Pernah kamu kunjungi'
                                    : place.address}
                            </span>
                        </span>
                    </button>
                </li>
            ))}
        </ul>
    );
}

/** Where the name came from, or the name at the pin when the user chose another one. */
function NameNote({
    lookup,
    name,
    onUse,
}: {
    lookup: Lookup;
    name: string;
    onUse: (name: string) => void;
}) {
    if (lookup.status === 'idle') {
        return null;
    }

    if (lookup.status === 'loading') {
        return (
            <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <Loader2 className="size-3 animate-spin" /> Mencari nama tempat
                di titik ini…
            </p>
        );
    }

    const { result } = lookup;

    if (!result.name) {
        return (
            <p className="text-xs text-muted-foreground">
                Nama tempat di titik ini tidak ditemukan — ketik sendiri.
            </p>
        );
    }

    if (result.name !== name.trim()) {
        return (
            <p className="text-xs text-muted-foreground">
                Di titik ini: <strong>{result.name}</strong> ·{' '}
                <button
                    type="button"
                    onClick={() => onUse(result.name!)}
                    className="font-medium text-brand underline-offset-2 hover:underline"
                >
                    pakai nama ini
                </button>
            </p>
        );
    }

    return (
        <p className="text-xs text-muted-foreground">
            {result.source === 'history'
                ? `Dari riwayatmu${result.distance ? ` (${result.distance} m dari pin)` : ''}`
                : 'Nama dari OpenStreetMap — ubah kalau kurang tepat'}
        </p>
    );
}

/** Why GPS is blocked on http:// from the phone, and the quickest fix. */
function InsecureHelp() {
    const origin = typeof window !== 'undefined' ? window.location.origin : '';

    return (
        <details className="rounded-md bg-muted/60 p-2 text-xs">
            <summary className="cursor-pointer font-medium">
                GPS tidak jalan di HP? Cara mengaktifkannya (Chrome Android)
            </summary>
            <ol className="mt-2 list-decimal space-y-1 pl-4 text-muted-foreground">
                <li>
                    Buka <code>chrome://flags</code> di Chrome HP.
                </li>
                <li>
                    Cari <strong>Insecure origins treated as secure</strong>.
                </li>
                <li>
                    Isi dengan <code>{origin}</code>, pilih{' '}
                    <strong>Enabled</strong>, lalu tekan <strong>Relaunch</strong>.
                </li>
                <li>
                    Sementara itu, kamu tetap bisa menggeser peta atau mencari
                    tempatnya.
                </li>
            </ol>
        </details>
    );
}
