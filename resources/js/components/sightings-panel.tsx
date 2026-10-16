import { Eye, ImagePlus, Loader2, MapPin, Plus, Trash2, X } from 'lucide-react';
import { useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { toast } from 'sonner';
import SightingController from '@/actions/App/Http/Controllers/SightingController';
import LocationField, { freshCoordinates } from '@/components/location-field';
import type { PlaceValue } from '@/components/location-field';
import MoneyInput from '@/components/money-input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { errorMessage, postJson, requestJson } from '@/lib/http';
import { formatRupiah } from '@/types/diecast';
import type { Diecast, Sighting } from '@/types/diecast';

const today = () => new Date().toISOString().slice(0, 10);

const formatDate = (iso: string) =>
    new Date(iso).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

// This panel lives inside the diecast <form>: Enter must not submit that form.
const stopEnter = (e: KeyboardEvent) => {
    if (e.key === 'Enter') {
        e.preventDefault();
    }
};

/**
 * "Catatan temuan": every place this diecast was seen, with the asking price — handy for
 * comparing stores before buying a wishlist item.
 */
export default function SightingsPanel({
    diecast,
    places,
}: {
    diecast: Diecast;
    places: string[];
}) {
    const [sightings, setSightings] = useState<Sighting[]>(
        diecast.sightings ?? [],
    );
    const [open, setOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const [draft, setDraft] = useState({
        seen_on: today(),
        price: '',
        notes: '',
    });
    const [location, setLocation] = useState<PlaceValue>({ name: '' });
    const [photo, setPhoto] = useState<File | null>(null);
    const photoInput = useRef<HTMLInputElement>(null);

    const prices = sightings
        .filter((s) => s.price !== null)
        .map((s) => Number(s.price));
    const cheapest = prices.length ? Math.min(...prices) : null;

    async function save() {
        const body = new FormData();
        Object.entries(draft).forEach(([key, value]) => body.append(key, value));
        body.append('place', location.name);

        const { latitude, longitude } = freshCoordinates(location);

        if (latitude != null && longitude != null) {
            body.append('latitude', String(latitude));
            body.append('longitude', String(longitude));
        }

        if (photo) {
            body.append('photo', photo);
        }

        setSaving(true);

        try {
            const created = await postJson<Sighting>(
                SightingController.store.url(diecast.id),
                body,
            );
            setSightings((current) =>
                [created, ...current].sort((a, b) =>
                    b.seen_on.localeCompare(a.seen_on),
                ),
            );
            setDraft({ seen_on: today(), price: '', notes: '' });
            setPhoto(null);
            setOpen(false);
            toast.success('Temuan dicatat.');
        } catch (error) {
            toast.error(errorMessage(error));
        }

        setSaving(false);
    }

    async function remove(sighting: Sighting) {
        try {
            await requestJson(
                'DELETE',
                SightingController.destroy.url(sighting.id),
            );
            setSightings((current) => current.filter((s) => s.id !== sighting.id));
        } catch (error) {
            toast.error(errorMessage(error));
        }
    }

    return (
        <section className="surface space-y-4 rounded-xl p-4 md:p-5">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="flex items-center gap-2 font-heading text-base font-semibold">
                    <Eye className="size-4 text-brand" />
                    Catatan temuan
                    {sightings.length > 0 && (
                        <span className="text-sm font-normal text-muted-foreground">
                            ({sightings.length}×)
                        </span>
                    )}
                </h3>
                {!open && (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => setOpen(true)}
                    >
                        <Plus /> Catat temuan
                    </Button>
                )}
            </div>

            {!open && sightings.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    Pernah lihat diecast ini di toko, di koleksi teman, atau di
                    event? Catat tempat dan harganya untuk membandingkan sebelum
                    membeli.
                </p>
            )}

            {open && (
                <div className="space-y-3 rounded-lg bg-muted/40 p-3">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5 sm:col-span-2">
                            <Label>Ditemui di</Label>
                            <LocationField
                                value={location}
                                onChange={setLocation}
                                places={places}
                                placeholder="Toys Kingdom GI, teman, event…"
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="sighting-date">Tanggal</Label>
                            <Input
                                id="sighting-date"
                                type="date"
                                max={today()}
                                value={draft.seen_on}
                                onKeyDown={stopEnter}
                                onChange={(e) =>
                                    setDraft({ ...draft, seen_on: e.target.value })
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="sighting-price">Harga di sana</Label>
                            <MoneyInput
                                id="sighting-price"
                                placeholder="Opsional"
                                value={draft.price}
                                onKeyDown={stopEnter}
                                onChange={(e) =>
                                    setDraft({ ...draft, price: e.target.value })
                                }
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="sighting-notes">Catatan</Label>
                            <Input
                                id="sighting-notes"
                                placeholder="Kartu agak penyok, stok tinggal 1…"
                                value={draft.notes}
                                onKeyDown={stopEnter}
                                onChange={(e) =>
                                    setDraft({ ...draft, notes: e.target.value })
                                }
                            />
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={() => photoInput.current?.click()}
                        >
                            <ImagePlus /> {photo ? photo.name : 'Tambah foto'}
                        </Button>
                        {photo && (
                            <button
                                type="button"
                                onClick={() => setPhoto(null)}
                                aria-label="Hapus foto"
                                className="text-muted-foreground hover:text-foreground"
                            >
                                <X className="size-4" />
                            </button>
                        )}
                        <input
                            ref={photoInput}
                            type="file"
                            accept="image/*"
                            className="hidden"
                            onChange={(e) => {
                                setPhoto(e.target.files?.[0] ?? null);
                                e.target.value = '';
                            }}
                        />
                        <div className="ml-auto flex gap-2">
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => setOpen(false)}
                                disabled={saving}
                            >
                                Batal
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                onClick={() => void save()}
                                disabled={saving}
                            >
                                {saving && <Loader2 className="animate-spin" />}
                                Simpan temuan
                            </Button>
                        </div>
                    </div>
                </div>
            )}

            {sightings.length > 0 && (
                <ul className="divide-y rounded-lg border">
                    {sightings.map((sighting) => {
                        const isCheapest =
                            cheapest !== null &&
                            prices.length > 1 &&
                            Number(sighting.price) === cheapest;

                        return (
                            <li
                                key={sighting.id}
                                className="flex items-center gap-3 p-3 text-sm"
                            >
                                {sighting.photo_url ? (
                                    <a
                                        href={sighting.photo_url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="shrink-0"
                                    >
                                        <img
                                            src={sighting.photo_url}
                                            alt=""
                                            className="size-11 rounded-md object-cover"
                                        />
                                    </a>
                                ) : (
                                    <span className="flex size-11 shrink-0 items-center justify-center rounded-md bg-muted">
                                        <MapPin className="size-4 text-muted-foreground" />
                                    </span>
                                )}
                                <div className="min-w-0 flex-1">
                                    <p className="truncate font-medium">
                                        {sighting.place ?? 'Tempat tidak dicatat'}
                                    </p>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {[formatDate(sighting.seen_on), sighting.notes]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                </div>
                                <div className="flex shrink-0 items-center gap-2">
                                    {isCheapest && (
                                        <Badge className="border-transparent bg-viz-up text-white">
                                            Termurah
                                        </Badge>
                                    )}
                                    <span className="font-medium">
                                        {sighting.price
                                            ? formatRupiah(sighting.price)
                                            : '—'}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => void remove(sighting)}
                                        className="text-muted-foreground hover:text-destructive"
                                        aria-label="Hapus temuan ini"
                                        title="Hapus"
                                    >
                                        <Trash2 className="size-4" />
                                    </button>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </section>
    );
}
