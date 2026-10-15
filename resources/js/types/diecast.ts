export type Rarity =
    | 'regular'
    | 'treasure_hunt'
    | 'super_treasure_hunt'
    | 'chase'
    | 'limited'
    | 'exclusive';
export type Packaging = 'carded' | 'boxed' | 'loose';
export type Condition = 'mint' | 'near_mint' | 'good' | 'fair' | 'damaged';
export type DiecastStatus = 'owned' | 'wishlist' | 'sold';

export type Diecast = {
    id: number;
    name: string;
    brand: string | null;
    make: string | null;
    series: string | null;
    collection_number: string | null;
    year: number | null;
    scale: string | null;
    color: string | null;
    rarity: Rarity;
    packaging: Packaging;
    condition: Condition | null;
    status: DiecastStatus;
    quantity: number;
    purchase_price: string | null;
    market_value: string | null;
    target_price: string | null;
    purchase_date: string | null;
    purchase_place: string | null;
    storage_location: string | null;
    notes: string | null;
    photo_url: string | null;
    photos: DiecastPhoto[];
    tags?: Tag[];
    ai_scanned: boolean;
    created_at: string;
};

export type DiecastPhoto = { id: number; path: string; url: string };

export type Tag = { id: number; name: string };

export type DiecastSuggestions = {
    brand: string[];
    series: string[];
    purchase_place: string[];
    storage_location: string[];
    tags: string[];
};

export type ScanFields = Partial<
    Pick<
        Diecast,
        | 'name'
        | 'brand'
        | 'make'
        | 'series'
        | 'collection_number'
        | 'year'
        | 'scale'
        | 'color'
        | 'rarity'
        | 'packaging'
        | 'condition'
        | 'notes'
    >
> & { confidence?: 'high' | 'medium' | 'low' };

export type ScanMatch = Diecast;

export type ScanResponse = {
    fields: ScanFields | null;
    error: string | null;
    matches: ScanMatch[];
};

export type ScanItem = {
    id: number;
    status: 'pending' | 'done' | 'failed' | 'saved';
    photo_url: string;
    fields: ScanFields | null;
    error: string | null;
    matches: ScanMatch[];
};

/** Prefill for the add form when coming from the bulk queue or a store check. */
export type ScanItemPrefill = {
    id: number;
    photo_path: string;
    photo_url: string;
    fields: ScanFields | null;
    matches: ScanMatch[];
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export const RARITY_LABELS: Record<Rarity, string> = {
    regular: 'Regular',
    treasure_hunt: 'Treasure Hunt',
    super_treasure_hunt: 'Super Treasure Hunt',
    chase: 'Chase',
    limited: 'Limited',
    exclusive: 'Exclusive',
};

export const PACKAGING_LABELS: Record<Packaging, string> = {
    carded: 'Carded (blister)',
    boxed: 'Box',
    loose: 'Loose',
};

export const CONDITION_LABELS: Record<Condition, string> = {
    mint: 'Mint',
    near_mint: 'Near mint',
    good: 'Good',
    fair: 'Fair',
    damaged: 'Rusak',
};

export const STATUS_LABELS: Record<DiecastStatus, string> = {
    owned: 'Dimiliki',
    wishlist: 'Wishlist',
    sold: 'Terjual',
};

export const SCALES = ['1:64', '1:43', '1:32', '1:24', '1:18', '1:87', '1:100'];

/** Tailwind classes for the rarity badge, so special pieces stand out in the grid. */
export const RARITY_STYLES: Record<Rarity, string> = {
    regular: '',
    treasure_hunt:
        'border-transparent bg-emerald-600 text-white dark:bg-emerald-500',
    super_treasure_hunt:
        'border-transparent bg-gradient-to-r from-amber-500 to-orange-600 text-white',
    chase: 'border-transparent bg-violet-600 text-white',
    limited: 'border-transparent bg-sky-600 text-white',
    exclusive: 'border-transparent bg-rose-600 text-white',
};

export function formatRupiah(value: number | string | null): string {
    if (value === null || value === '') {
        return '-';
    }

    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(Number(value));
}
