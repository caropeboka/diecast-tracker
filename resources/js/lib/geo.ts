import ExploreController from '@/actions/App/Http/Controllers/ExploreController';
import { requestJson } from '@/lib/http';

export type PlaceName = {
    name: string | null;
    address: string | null;
    source: 'history' | 'osm' | 'none';
    distance: number | null;
};

export type PlaceResult = {
    name: string;
    address: string | null;
    lat: number;
    lng: number;
    source: 'history' | 'osm';
};

export type GpsFix = { lat: number; lng: number; accuracy: number };

/**
 * Browsers only share GPS with secure pages (https or localhost). On the phone over
 * http://192.168.x.x the API exists but always fails — say so up front instead.
 */
export function geolocationProblem(): 'insecure' | 'unsupported' | null {
    if (typeof window === 'undefined' || !('geolocation' in navigator)) {
        return 'unsupported';
    }

    return window.isSecureContext ? null : 'insecure';
}

const GEO_ERRORS: Record<number, string> = {
    1: 'Izin lokasi ditolak. Izinkan akses lokasi untuk situs ini di pengaturan browser.',
    2: 'Lokasi tidak ditemukan. Pastikan GPS / lokasi HP menyala.',
    3: 'Mencari lokasi terlalu lama. Coba lagi di tempat yang sinyalnya lebih baik.',
};

/** The phone's GPS position, with errors worded for the user. */
export async function currentPosition(): Promise<GpsFix> {
    const problem = geolocationProblem();

    if (problem) {
        throw new Error(
            problem === 'insecure'
                ? 'Browser hanya memberi lokasi GPS untuk halaman HTTPS. Geser peta atau cari tempatnya, atau lihat petunjuk di bawah.'
                : 'Browser ini tidak mendukung GPS. Geser peta atau cari tempatnya.',
        );
    }

    try {
        const pos = await new Promise<GeolocationPosition>((resolve, reject) =>
            navigator.geolocation.getCurrentPosition(resolve, reject, {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 30000,
            }),
        );

        return {
            lat: pos.coords.latitude,
            lng: pos.coords.longitude,
            accuracy: Math.round(pos.coords.accuracy),
        };
    } catch (error) {
        const code = (error as GeolocationPositionError).code;

        throw new Error(GEO_ERRORS[code] ?? 'Gagal mengambil lokasi.');
    }
}

/** Suggested name for a point: the user's own name for a spot nearby, else OpenStreetMap. */
export async function lookupPlace(lat: number, lng: number): Promise<PlaceName> {
    try {
        return await requestJson<PlaceName>(
            'GET',
            ExploreController.lookup.url({ query: { lat, lng } }),
        );
    } catch {
        // The name is a nicety; the point is what matters.
        return { name: null, address: null, source: 'none', distance: null };
    }
}

/** Places matching a search (empty query = the user's recent places), near the given point. */
export function searchPlaces(
    q: string,
    near?: { lat: number; lng: number },
): Promise<PlaceResult[]> {
    return requestJson<PlaceResult[]>(
        'GET',
        ExploreController.search.url({
            query: { q, ...(near && { lat: near.lat, lng: near.lng }) },
        }),
    );
}
