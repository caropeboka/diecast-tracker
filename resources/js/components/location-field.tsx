import { LocateFixed, MapPin } from 'lucide-react';
import { lazy, Suspense, useId, useState } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

// The map (Leaflet) only loads when the picker is opened.
const LocationPicker = lazy(() => import('@/components/location-picker'));

export type PlaceValue = {
    name: string;
    lat?: number | null;
    lng?: number | null;
    accuracy?: number | null;
    /** When the point was chosen (ms since epoch). */
    at?: number | null;
};

/** A point older than this is probably from another store. */
const FRESH_MS = 3 * 60 * 60 * 1000;

/** The "where am I now" place from Cek koleksi, remembered on this device. */
const SAVED_PLACE_KEY = 'check-place';

/** The remembered place; its point is dropped once stale (you've likely moved on). */
export function readSavedPlace(): PlaceValue {
    try {
        const raw = localStorage.getItem(SAVED_PLACE_KEY) ?? '';
        // Older versions stored only the name.
        const place: PlaceValue = raw.startsWith('{')
            ? JSON.parse(raw)
            : { name: raw };
        const { latitude, longitude } = freshCoordinates(place);

        return { ...place, lat: latitude, lng: longitude };
    } catch {
        return { name: '' };
    }
}

export function savePlace(value: PlaceValue): void {
    try {
        localStorage.setItem(SAVED_PLACE_KEY, JSON.stringify(value));
    } catch {
        // Storage unavailable (private mode): the place just won't be remembered.
    }
}

/** The saved place, only if its point is recent (you're probably still there). */
export function recentSavedPlace(): PlaceValue | null {
    const place = readSavedPlace();

    return place.name && place.lat != null ? place : null;
}

/** The coordinates worth saving, or nulls when missing/stale. */
export function freshCoordinates(value: PlaceValue) {
    const fresh =
        value.lat != null &&
        value.lng != null &&
        value.at != null &&
        Date.now() - value.at < FRESH_MS;

    return {
        latitude: fresh ? value.lat! : null,
        longitude: fresh ? value.lng! : null,
    };
}

/**
 * Place name input with a map button: pick the point on a map preview (starting from GPS,
 * a search, or your own places), adjust it, and confirm — the name follows the pin.
 */
export default function LocationField({
    value,
    onChange,
    places,
    placeholder = 'Lokasi sekarang (mis. Toys Kingdom GI)',
    className,
    inputClassName,
}: {
    value: PlaceValue;
    onChange: (value: PlaceValue) => void;
    places: string[];
    placeholder?: string;
    className?: string;
    inputClassName?: string;
}) {
    const [picking, setPicking] = useState(false);
    const listId = useId();
    const hasPoint = value.lat != null && value.lng != null;

    return (
        <div className={cn('space-y-1.5', className)}>
            <div className="flex items-center gap-2">
                <MapPin className="size-4 shrink-0 text-brand" />
                <Input
                    aria-label="Nama tempat"
                    list={listId}
                    placeholder={placeholder}
                    value={value.name}
                    onChange={(e) => onChange({ ...value, name: e.target.value })}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                        }
                    }}
                    className={inputClassName}
                />
                <datalist id={listId}>
                    {places.map((p) => (
                        <option key={p} value={p} />
                    ))}
                </datalist>
                <button
                    type="button"
                    onClick={() => setPicking(true)}
                    title="Pilih titik di peta"
                    aria-label="Pilih titik di peta"
                    className="flex size-8 shrink-0 items-center justify-center rounded-md bg-brand/12 text-brand transition hover:bg-brand/20"
                >
                    <LocateFixed className="size-4" />
                </button>
            </div>

            {hasPoint && (
                <p className="pl-6 text-xs text-muted-foreground">
                    📍 {value.lat!.toFixed(5)}, {value.lng!.toFixed(5)}
                    {value.accuracy ? ` · GPS ±${value.accuracy} m` : ''} ·{' '}
                    <button
                        type="button"
                        onClick={() => setPicking(true)}
                        className="underline-offset-2 hover:text-foreground hover:underline"
                    >
                        ubah titik
                    </button>{' '}
                    ·{' '}
                    <button
                        type="button"
                        onClick={() =>
                            onChange({
                                ...value,
                                lat: null,
                                lng: null,
                                accuracy: null,
                                at: null,
                            })
                        }
                        className="underline-offset-2 hover:text-foreground hover:underline"
                    >
                        hapus titik
                    </button>
                </p>
            )}

            {picking && (
                <Suspense fallback={null}>
                    <LocationPicker
                        open
                        onOpenChange={setPicking}
                        initial={value}
                        onPick={(place) =>
                            onChange({
                                // Keep what was typed if the picker has no name.
                                name: place.name || value.name,
                                lat: place.lat,
                                lng: place.lng,
                                accuracy: place.accuracy,
                                at: Date.now(),
                            })
                        }
                    />
                </Suspense>
            )}
        </div>
    );
}
