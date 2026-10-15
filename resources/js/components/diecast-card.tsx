import { Link } from '@inertiajs/react';
import { Car, Sparkles } from 'lucide-react';
import DiecastController from '@/actions/App/Http/Controllers/DiecastController';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { formatRupiah, RARITY_LABELS, RARITY_STYLES } from '@/types/diecast';
import type { Diecast } from '@/types/diecast';

export default function DiecastCard({ diecast }: { diecast: Diecast }) {
    return (
        <Link
            href={DiecastController.edit(diecast.id)}
            className="group overflow-hidden rounded-xl border border-sidebar-border/70 bg-card transition hover:-translate-y-0.5 hover:shadow-md dark:border-sidebar-border"
        >
            <div className="relative aspect-square bg-muted/50">
                {diecast.photo_url ? (
                    <img
                        src={diecast.photo_url}
                        alt={diecast.name}
                        loading="lazy"
                        className="size-full object-cover transition group-hover:scale-[1.03]"
                    />
                ) : (
                    <div className="flex size-full items-center justify-center text-muted-foreground/50">
                        <Car className="size-12" />
                    </div>
                )}

                <div className="absolute top-2 left-2 flex flex-wrap gap-1">
                    {diecast.rarity !== 'regular' && (
                        <Badge className={cn(RARITY_STYLES[diecast.rarity])}>
                            {diecast.rarity === 'super_treasure_hunt' && (
                                <Sparkles />
                            )}
                            {RARITY_LABELS[diecast.rarity]}
                        </Badge>
                    )}
                    {diecast.status === 'wishlist' && (
                        <Badge variant="secondary">Wishlist</Badge>
                    )}
                    {diecast.status === 'sold' && (
                        <Badge variant="outline" className="bg-background">
                            Terjual
                        </Badge>
                    )}
                </div>

                {diecast.quantity > 1 && (
                    <Badge className="absolute top-2 right-2">
                        {diecast.quantity}×
                    </Badge>
                )}
            </div>

            <div className="space-y-0.5 p-3">
                <p className="truncate text-xs text-muted-foreground">
                    {[diecast.brand, diecast.year].filter(Boolean).join(' · ') ||
                        '—'}
                </p>
                <p className="line-clamp-2 leading-snug font-medium">
                    {diecast.name}
                </p>
                {diecast.series && (
                    <p className="truncate text-xs text-muted-foreground">
                        {diecast.series}
                    </p>
                )}
                {diecast.status === 'wishlist' && diecast.target_price && (
                    <p className="text-xs font-medium text-rose-600 dark:text-rose-400">
                        Target ≤ {formatRupiah(diecast.target_price)}
                    </p>
                )}
                {diecast.status !== 'wishlist' && diecast.market_value && (
                    <p className="text-xs font-medium text-emerald-600 dark:text-emerald-400">
                        Nilai {formatRupiah(diecast.market_value)}
                    </p>
                )}
            </div>
        </Link>
    );
}
