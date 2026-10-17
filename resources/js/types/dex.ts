import type { DiecastStatus } from '@/types/diecast';

export type DexFlags = {
    new?: boolean;
    th?: boolean;
    sth?: boolean;
    /** Store exclusive, e.g. "Walmart". */
    exclusive?: string;
};

export type DexCounts = {
    total: number;
    seen: number;
    caught: number;
    wanted: number;
};

/** A dex entry as shown next to a diecast (link, suggestion, prefill). */
export type DexRef = {
    item_id: number;
    catalog_id: number;
    catalog: string;
    year: number | null;
    number: string;
    name: string;
    variant_label: string | null;
    series: string | null;
    series_number: string | null;
    flags: DexFlags;
    photo_url: string | null;
    score: number | null;
    /** The entry's release year, when the collection says otherwise. */
    year_fix: number | null;
};

export type DexPrefill = DexRef & {
    fields: {
        name: string;
        brand: string;
        series: string | null;
        collection_number: string;
        year: number | null;
        rarity: 'regular' | 'treasure_hunt' | 'super_treasure_hunt';
        scale: string | null;
    };
};

export type DexLinkState = {
    linked: DexRef | null;
    match: 'auto' | 'confirmed' | 'ignored' | null;
    suggestions: DexRef[];
};

export type DexCatalogSummary = DexCounts & {
    id: number;
    brand: string;
    name: string;
    year: number | null;
    source: DexSource['key'] | 'custom';
    synced_at: string | null;
};

export type DexBrandSummary = DexCounts & {
    brand: string;
    catalogs: number;
};

export type DexSource = {
    key: 'hotwheels_wiki' | 'matchbox_wiki';
    brand: string;
    label: string;
    first_year: number;
    last_year: number;
    synced: number[];
};

export type DexVariant = {
    id: number;
    variant: number;
    variant_label: string | null;
    name: string;
    toy_code: string | null;
    flags: DexFlags;
    photo_url: string | null;
    seen: boolean;
    caught: boolean;
    wanted: boolean;
};

export type DexEntry = {
    number: string;
    sort: number;
    name: string;
    series: string | null;
    series_number: string | null;
    photo_url: string | null;
    flags: DexFlags;
    seen: boolean;
    caught: boolean;
    wanted: boolean;
    variants: DexVariant[];
    diecasts: {
        id: number;
        name: string;
        status: DiecastStatus;
        photo_url: string | null;
        catalog_item_id: number;
        catalog_match: 'auto' | 'confirmed' | null;
    }[];
};

export type DexSeries = {
    series: string;
    total: number;
    seen: number;
    caught: number;
};

export const percent = (part: number, total: number) =>
    total ? Math.round((part / total) * 100) : 0;
