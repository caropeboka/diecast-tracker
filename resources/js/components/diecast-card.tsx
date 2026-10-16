import { Link } from '@inertiajs/react';
import { Car, Eye, Heart, Sparkles } from 'lucide-react';
import DiecastController from '@/actions/App/Http/Controllers/DiecastController';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import {
    formatRupiahCompact,
    RARITY_LABELS,
    RARITY_STYLES,
} from '@/types/diecast';
import type { Diecast } from '@/types/diecast';

/** Photo-forward collection card: the car fills the card, name sits on a dark gradient over the photo. */
export default function DiecastCard({ diecast }: { diecast: Diecast }) {
    const subtitle = [diecast.brand, diecast.year].filter(Boolean).join(' · ');

    return (
        <Link
            href={DiecastController.edit(diecast.id)}
            className="surface lift group block overflow-hidden rounded-xl"
        >
            <div className="relative aspect-[4/5] overflow-hidden bg-muted">
                {diecast.photo_url ? (
                    <img
                        src={diecast.photo_url}
                        alt={diecast.name}
                        loading="lazy"
                        className="size-full object-cover transition duration-500 group-hover:scale-105"
                    />
                ) : (
                    <div className="flex size-full items-center justify-center text-muted-foreground/40">
                        <Car className="size-12" />
                    </div>
                )}

                <div className="absolute top-2 left-2 flex flex-wrap gap-1">
                    {diecast.rarity !== 'regular' && (
                        <Badge
                            className={cn(
                                'shadow-md',
                                RARITY_STYLES[diecast.rarity],
                            )}
                        >
                            {diecast.rarity === 'super_treasure_hunt' && (
                                <Sparkles />
                            )}
                            {RARITY_LABELS[diecast.rarity]}
                        </Badge>
                    )}
                    {diecast.status === 'wishlist' && (
                        <Badge className="border-transparent bg-black/70 text-white backdrop-blur">
                            <Heart /> Wishlist
                        </Badge>
                    )}
                    {diecast.status === 'spotted' && (
                        <Badge className="border-transparent bg-black/70 text-white backdrop-blur">
                            <Eye /> Ditemui
                        </Badge>
                    )}
                    {diecast.status === 'sold' && (
                        <Badge className="border-transparent bg-black/70 text-white backdrop-blur">
                            Terjual
                        </Badge>
                    )}
                </div>

                {diecast.quantity > 1 && (
                    <Badge className="absolute top-2 right-2 border-transparent bg-brand text-brand-foreground shadow-md">
                        {diecast.quantity}×
                    </Badge>
                )}

                <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/90 via-black/55 to-transparent p-3 pt-12">
                    {subtitle && (
                        <p className="truncate text-[11px] font-medium tracking-wide text-white/70 uppercase">
                            {subtitle}
                        </p>
                    )}
                    <p className="line-clamp-2 leading-snug font-semibold text-white">
                        {diecast.name}
                    </p>
                </div>
            </div>

            <div className="flex items-center justify-between gap-2 px-3 py-2.5 text-xs">
                <span className="min-w-0 truncate text-muted-foreground">
                    {diecast.status === 'spotted' && diecast.sightings_count
                        ? `Ditemui ${diecast.sightings_count}×`
                        : (diecast.series ?? diecast.color ?? '—')}
                </span>
                {diecast.status === 'spotted'
                    ? diecast.sightings_min_price && (
                          <span className="shrink-0 font-semibold text-brand">
                              mulai{' '}
                              {formatRupiahCompact(diecast.sightings_min_price)}
                          </span>
                      )
                    : diecast.status === 'wishlist'
                      ? diecast.target_price && (
                            <span className="shrink-0 text-muted-foreground">
                                ≤ {formatRupiahCompact(diecast.target_price)}
                            </span>
                        )
                      : diecast.market_value && (
                            <span className="shrink-0 font-semibold text-brand">
                                {formatRupiahCompact(diecast.market_value)}
                            </span>
                        )}
            </div>
        </Link>
    );
}
