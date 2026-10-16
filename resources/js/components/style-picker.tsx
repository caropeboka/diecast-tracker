import { Check } from 'lucide-react';
import { useStyle } from '@/hooks/use-style';
import type { Style } from '@/hooks/use-style';
import { cn } from '@/lib/utils';

/** Hard-coded swatches so each card previews its own style regardless of the active one. */
const STYLE_PREVIEWS: Record<
    Style,
    {
        name: string;
        tagline: string;
        bg: string;
        card: string;
        brand: string;
        brand2: string;
        font: string;
        caps: boolean;
        radius: string;
    }
> = {
    garage: {
        name: 'Garage',
        tagline: 'Sporty seperti pit-stop: arang + oranye racing.',
        bg: '#0b0b0d',
        card: '#16161a',
        brand: '#ff6a1a',
        brand2: '#ffb020',
        font: "'Saira Condensed', sans-serif",
        caps: true,
        radius: '6px',
    },
    showroom: {
        name: 'Showroom Malam',
        tagline: 'Mewah seperti katalog kolektor: navy + emas.',
        bg: '#070b18',
        card: '#111833',
        brand: '#e3b341',
        brand2: '#f5d98b',
        font: "'Fraunces', Georgia, serif",
        caps: false,
        radius: '14px',
    },
    neon: {
        name: 'Neon Track',
        tagline: 'Modern & futuristik: hitam + cyan/ungu.',
        bg: '#060608',
        card: '#121217',
        brand: '#22d3ee',
        brand2: '#8b5cf6',
        font: "'Space Grotesk', sans-serif",
        caps: false,
        radius: '12px',
    },
};

export default function StylePicker() {
    const { style, updateStyle } = useStyle();

    return (
        <div className="grid gap-3 sm:grid-cols-3">
            {(Object.keys(STYLE_PREVIEWS) as Style[]).map((key) => {
                const preview = STYLE_PREVIEWS[key];
                const active = style === key;

                return (
                    <button
                        key={key}
                        type="button"
                        onClick={() => updateStyle(key)}
                        aria-pressed={active}
                        className={cn(
                            'group overflow-hidden rounded-xl border-2 text-left transition',
                            active
                                ? 'border-brand'
                                : 'border-border hover:border-muted-foreground/50',
                        )}
                    >
                        {/* Mini mock of the app in this style */}
                        <div
                            className="space-y-2 p-3"
                            style={{ background: preview.bg }}
                        >
                            <div className="flex items-center gap-1.5">
                                <span
                                    className="size-3 rounded-sm"
                                    style={{
                                        background: `linear-gradient(135deg, ${preview.brand}, ${preview.brand2})`,
                                    }}
                                />
                                <span
                                    className="text-[11px] font-semibold text-white"
                                    style={{
                                        fontFamily: preview.font,
                                        textTransform: preview.caps
                                            ? 'uppercase'
                                            : 'none',
                                    }}
                                >
                                    Garasi kamu
                                </span>
                            </div>
                            <div
                                className="space-y-1.5 p-2"
                                style={{
                                    background: preview.card,
                                    borderRadius: preview.radius,
                                }}
                            >
                                <div
                                    className="text-[9px]"
                                    style={{ color: '#9a9aa5' }}
                                >
                                    Nilai koleksi
                                </div>
                                <div
                                    className="text-base leading-none font-semibold text-white"
                                    style={{ fontFamily: preview.font }}
                                >
                                    Rp 227.500
                                </div>
                                <div
                                    className="h-1.5 w-3/4 rounded-full"
                                    style={{ background: preview.brand }}
                                />
                                <div
                                    className="h-1.5 w-1/2 rounded-full"
                                    style={{ background: preview.brand, opacity: 0.6 }}
                                />
                            </div>
                            <div
                                className="inline-block px-2 py-0.5 text-[10px] font-semibold"
                                style={{
                                    background: preview.brand,
                                    color: '#111',
                                    borderRadius: preview.radius,
                                }}
                            >
                                + Tambah
                            </div>
                        </div>
                        <div className="flex items-start justify-between gap-2 bg-card p-3">
                            <div>
                                <p className="text-sm font-medium">
                                    {preview.name}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {preview.tagline}
                                </p>
                            </div>
                            {active && (
                                <Check
                                    className="size-4 shrink-0 text-brand"
                                    aria-label="Dipakai"
                                />
                            )}
                        </div>
                    </button>
                );
            })}
        </div>
    );
}
