import 'leaflet/dist/leaflet.css';
import { Head, Link, router } from '@inertiajs/react';
import type { LatLngBoundsExpression, Map as LeafletMap } from 'leaflet';
import {
    CalendarDays,
    Car,
    Eye,
    LocateFixed,
    Map as MapIcon,
    MapPin,
    Route,
    ShoppingBag,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import {
    CircleMarker,
    MapContainer,
    Popup,
    TileLayer,
    Tooltip,
} from 'react-leaflet';
import CheckController from '@/actions/App/Http/Controllers/CheckController';
import DiecastController from '@/actions/App/Http/Controllers/DiecastController';
import ExploreController from '@/actions/App/Http/Controllers/ExploreController';
import { Panel, StatTile } from '@/components/dashboard/charts';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { formatRupiah } from '@/types/diecast';

type EventType = 'sighting' | 'purchase';

type PlaceItem = {
    id: string;
    type: EventType;
    diecast_id: number;
    name: string | null;
    brand: string | null;
    photo_url: string | null;
    price: string | null;
    date: string;
};

type Place = {
    key: string;
    name: string;
    lat: number | null;
    lng: number | null;
    count: number;
    sightings: number;
    purchases: number;
    visits: number;
    first: string;
    last: string;
    items: PlaceItem[];
};

type Stats = {
    places: number;
    mapped: number;
    sightings: number;
    purchases: number;
    diecasts: number;
    days: number;
    first: string | null;
    last: string | null;
    top: string | null;
};

type Day = {
    date: string;
    places: { name: string; sightings: number; purchases: number }[];
};

const RANGES = [
    ['all', 'Semua'],
    ['30', '30 hari'],
    ['90', '3 bulan'],
    ['365', '1 tahun'],
] as const;

const TYPES = [
    ['all', 'Semua', MapPin],
    ['sighting', 'Temuan', Eye],
    ['purchase', 'Pembelian', ShoppingBag],
] as const;

const formatDate = (iso: string, withYear = true) =>
    new Date(iso).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
        ...(withYear && { year: 'numeric' }),
    });

/** "2 dibeli · 3 ditemui" */
const describeCounts = (sightings: number, purchases: number) =>
    [
        purchases ? `${purchases} dibeli` : null,
        sightings ? `${sightings} ditemui` : null,
    ]
        .filter(Boolean)
        .join(' · ');

/** The style's brand color, for SVG markers (Leaflet needs a real color, not a class). */
function useBrandColor() {
    const [color, setColor] = useState('#ff6a1a');

    useEffect(() => {
        const read = () =>
            setColor(
                getComputedStyle(document.documentElement)
                    .getPropertyValue('--brand')
                    .trim() || '#ff6a1a',
            );
        read();

        const observer = new MutationObserver(read);
        observer.observe(document.documentElement, {
            attributeFilter: ['data-style'],
        });

        return () => observer.disconnect();
    }, []);

    return color;
}

/** Marker radius grows with the number of events (area ~ count). */
const radius = (count: number) => 6 + Math.sqrt(count) * 4;

export default function Explore({
    range,
    type,
    places,
    stats,
    timeline,
}: {
    range: string;
    type: string;
    places: Place[];
    stats: Stats;
    timeline: Day[];
}) {
    const brand = useBrandColor();
    const [map, setMap] = useState<LeafletMap | null>(null);
    const mapped = useMemo(
        () => places.filter((p) => p.lat !== null && p.lng !== null),
        [places],
    );

    // Frame all markers whenever the set changes.
    useEffect(() => {
        if (!map || !mapped.length) {
            return;
        }

        if (mapped.length === 1) {
            map.setView([mapped[0].lat!, mapped[0].lng!], 15);
        } else {
            map.fitBounds(
                mapped.map((p) => [p.lat!, p.lng!]) as LatLngBoundsExpression,
                { padding: [40, 40], maxZoom: 15 },
            );
        }
    }, [map, mapped]);

    function filter(next: { range?: string; type?: string }) {
        const query = { range, type, ...next };

        router.get(
            ExploreController.index.url(),
            Object.fromEntries(
                Object.entries(query).filter(([, value]) => value !== 'all'),
            ),
            { preserveScroll: true, preserveState: true },
        );
    }

    function focus(place: Place) {
        if (map && place.lat !== null && place.lng !== null) {
            map.flyTo([place.lat, place.lng], 16, { duration: 0.8 });
            document
                .getElementById('explore-map')
                ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    return (
        <>
            <Head title="Peta jelajah" />
            <div className="space-y-6 p-4 md:p-6">
                <Heading
                    title="Peta jelajah"
                    description="Tempat-tempat kamu membeli dan menemukan diecast, kapan, dan apa saja yang ada di sana."
                />

                <div className="flex flex-wrap gap-2">
                    <Segmented
                        label="Jenis"
                        value={type}
                        options={TYPES.map(([value, text, Icon]) => ({
                            value,
                            label: (
                                <>
                                    <Icon className="size-3.5" /> {text}
                                </>
                            ),
                        }))}
                        onChange={(value) => filter({ type: value })}
                    />
                    <Segmented
                        label="Rentang waktu"
                        value={range}
                        options={RANGES.map(([value, text]) => ({
                            value,
                            label: text,
                        }))}
                        onChange={(value) => filter({ range: value })}
                    />
                </div>

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <StatTile
                        icon={<MapPin />}
                        label="Tempat dijelajahi"
                        value={stats.places}
                        hint={`${stats.mapped} punya titik di peta`}
                    />
                    <StatTile
                        icon={<Car />}
                        label="Diecast"
                        value={stats.diecasts}
                        hint={
                            describeCounts(stats.sightings, stats.purchases) ||
                            '—'
                        }
                    />
                    <StatTile
                        icon={<CalendarDays />}
                        label="Hari berburu"
                        value={stats.days}
                        hint={
                            stats.top
                                ? `Paling sering: ${stats.top}`
                                : undefined
                        }
                    />
                    <StatTile
                        icon={<Route />}
                        label="Berburu sejak"
                        value={stats.first ? formatDate(stats.first) : '—'}
                        hint={
                            stats.last
                                ? `Terakhir ${formatDate(stats.last)}`
                                : undefined
                        }
                    />
                </div>

                <section
                    id="explore-map"
                    className="surface relative overflow-hidden rounded-xl"
                >
                    <MapContainer
                        center={[-6.2, 106.82]}
                        zoom={11}
                        scrollWheelZoom
                        ref={setMap}
                        className="h-[340px] w-full md:h-[460px]"
                    >
                        {/* Standard OSM tiles (no API key), darkened with a CSS filter to match the app. */}
                        <TileLayer
                            attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
                            url="https://tile.openstreetmap.org/{z}/{x}/{y}.png"
                            className="map-tiles-dark"
                            maxZoom={19}
                        />
                        {mapped.map((place) => {
                            // Filled dot: something was bought here. Ring: only spotted.
                            const bought = place.purchases > 0;

                            return (
                                <CircleMarker
                                    key={place.key}
                                    center={[place.lat!, place.lng!]}
                                    radius={radius(place.count)}
                                    pathOptions={{
                                        color: bought ? '#0b0b0d' : brand,
                                        weight: bought ? 2 : 3,
                                        fillColor: brand,
                                        fillOpacity: bought ? 0.85 : 0.15,
                                    }}
                                >
                                    <Tooltip direction="top" offset={[0, -6]}>
                                        <strong>{place.name}</strong> ·{' '}
                                        {describeCounts(
                                            place.sightings,
                                            place.purchases,
                                        )}
                                    </Tooltip>
                                    <Popup minWidth={240} maxWidth={300}>
                                        <PlacePopup place={place} />
                                    </Popup>
                                </CircleMarker>
                            );
                        })}
                    </MapContainer>

                    {mapped.length === 0 && (
                        <div className="absolute inset-0 z-[400] flex flex-col items-center justify-center gap-3 bg-background/80 p-6 text-center backdrop-blur-sm">
                            <MapIcon className="size-10 text-brand" />
                            <p className="font-heading text-lg font-semibold">
                                Belum ada titik di peta
                            </p>
                            <p className="max-w-sm text-sm text-muted-foreground">
                                Tekan tombol{' '}
                                <LocateFixed className="inline size-4" /> di{' '}
                                <strong>Beli di</strong> saat menambah diecast,
                                atau di <strong>Cek koleksi</strong> saat
                                berburu di toko. Tempatnya akan muncul di sini.
                            </p>
                            <Button asChild>
                                <Link href={CheckController.index()}>
                                    Buka Cek koleksi
                                </Link>
                            </Button>
                        </div>
                    )}

                    {mapped.length > 0 && (
                        <div className="absolute top-2 right-2 z-[400] flex max-w-[calc(100%-4rem)] flex-wrap items-center justify-end gap-x-3 gap-y-1 rounded bg-background/85 px-2 py-1 text-[11px] text-muted-foreground">
                            <span className="flex items-center gap-1">
                                <span className="size-2.5 rounded-full bg-brand" />
                                Ada pembelian
                            </span>
                            <span className="flex items-center gap-1">
                                <span className="size-2.5 rounded-full border-2 border-brand" />
                                Hanya ditemui
                            </span>
                            <span>Ukuran = jumlah diecast</span>
                        </div>
                    )}
                </section>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <Panel title="Tempat" icon={<MapPin className="size-4" />}>
                        {places.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Belum ada data di rentang waktu ini.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {places.map((place) => (
                                    <li key={place.key}>
                                        <button
                                            type="button"
                                            onClick={() => focus(place)}
                                            disabled={place.lat === null}
                                            className="flex w-full items-center gap-3 py-2.5 text-left disabled:cursor-default"
                                        >
                                            <span
                                                className={cn(
                                                    'flex size-9 shrink-0 items-center justify-center rounded-lg',
                                                    place.lat !== null
                                                        ? 'bg-brand/12 text-brand'
                                                        : 'bg-muted text-muted-foreground',
                                                )}
                                            >
                                                {place.purchases ? (
                                                    <ShoppingBag className="size-4" />
                                                ) : (
                                                    <Eye className="size-4" />
                                                )}
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-medium">
                                                    {place.name}
                                                </span>
                                                <span className="block truncate text-xs text-muted-foreground">
                                                    {place.visits}× kunjungan ·{' '}
                                                    {place.first === place.last
                                                        ? formatDate(place.last)
                                                        : `${formatDate(place.first, false)} – ${formatDate(place.last)}`}
                                                    {place.lat === null &&
                                                        ' · belum ada titik'}
                                                </span>
                                            </span>
                                            <span className="shrink-0 text-right text-xs text-muted-foreground">
                                                {describeCounts(
                                                    place.sightings,
                                                    place.purchases,
                                                )}
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <Panel
                        title="Riwayat jelajah"
                        icon={<CalendarDays className="size-4" />}
                    >
                        {timeline.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Belum ada riwayat.
                            </p>
                        ) : (
                            <ol className="relative space-y-4 border-l border-border pl-5">
                                {timeline.map((day) => (
                                    <li key={day.date} className="relative">
                                        <span className="absolute top-1.5 -left-[25px] size-2.5 rounded-full bg-brand ring-4 ring-card" />
                                        <p className="text-sm font-medium">
                                            {new Date(
                                                day.date,
                                            ).toLocaleDateString('id-ID', {
                                                weekday: 'long',
                                                day: 'numeric',
                                                month: 'long',
                                                year: 'numeric',
                                            })}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {day.places
                                                .map(
                                                    (p) =>
                                                        `${p.name} (${describeCounts(p.sightings, p.purchases)})`,
                                                )
                                                .join(' · ')}
                                        </p>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </Panel>
                </div>
            </div>
        </>
    );
}

function Segmented({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string;
    options: { value: string; label: React.ReactNode }[];
    onChange: (value: string) => void;
}) {
    return (
        <div
            className="flex rounded-lg bg-muted p-1 text-sm"
            role="group"
            aria-label={label}
        >
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    aria-pressed={value === option.value}
                    onClick={() => onChange(option.value)}
                    className={cn(
                        'flex items-center gap-1.5 rounded-md px-3 py-1.5 transition',
                        value === option.value
                            ? 'bg-card font-medium shadow-sm ring-1 ring-brand/40'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

function PlacePopup({ place }: { place: Place }) {
    return (
        <div className="space-y-2">
            <div>
                <p className="font-heading text-base font-semibold">
                    {place.name}
                </p>
                <p className="text-xs text-muted-foreground">
                    {place.visits}× kunjungan · terakhir{' '}
                    {formatDate(place.last)}
                </p>
            </div>
            <ul className="max-h-56 space-y-1.5 overflow-y-auto">
                {place.items.map((item) => (
                    <li key={item.id}>
                        <Link
                            href={DiecastController.edit(item.diecast_id)}
                            className="flex items-center gap-2 rounded hover:bg-muted/60"
                        >
                            {item.photo_url ? (
                                <img
                                    src={item.photo_url}
                                    alt=""
                                    className="size-9 shrink-0 rounded object-cover"
                                />
                            ) : (
                                <span className="flex size-9 shrink-0 items-center justify-center rounded bg-muted">
                                    <Car className="size-4 text-muted-foreground" />
                                </span>
                            )}
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-xs font-medium">
                                    {item.name ?? '—'}
                                </span>
                                <span className="block truncate text-[11px] text-muted-foreground">
                                    {item.type === 'purchase'
                                        ? 'Dibeli'
                                        : 'Ditemui'}{' '}
                                    {formatDate(item.date, false)}
                                    {item.price
                                        ? ` · ${formatRupiah(item.price)}`
                                        : ''}
                                </span>
                            </span>
                            {item.type === 'purchase' ? (
                                <ShoppingBag className="size-3.5 shrink-0 text-brand" />
                            ) : (
                                <Eye className="size-3.5 shrink-0 text-muted-foreground" />
                            )}
                        </Link>
                    </li>
                ))}
            </ul>
            {place.count > place.items.length && (
                <p className="text-[11px] text-muted-foreground">
                    +{place.count - place.items.length} lainnya
                </p>
            )}
        </div>
    );
}

Explore.layout = {
    breadcrumbs: [{ title: 'Peta jelajah', href: ExploreController.index() }],
};
