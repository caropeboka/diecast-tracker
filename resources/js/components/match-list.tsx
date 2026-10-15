import { Link } from '@inertiajs/react';
import { Car } from 'lucide-react';
import type { ReactNode } from 'react';
import DiecastController from '@/actions/App/Http/Controllers/DiecastController';
import { Badge } from '@/components/ui/badge';
import { formatRupiah } from '@/types/diecast';
import type { ScanMatch } from '@/types/diecast';

/**
 * Diecasts in the collection that look like the scanned one.
 */
export default function MatchList({
    matches,
    actions,
}: {
    matches: ScanMatch[];
    actions?: (match: ScanMatch) => ReactNode;
}) {
    return (
        <ul className="space-y-2">
            {matches.map((match) => (
                <li key={match.id} className="flex items-center gap-3">
                    <Link
                        href={DiecastController.edit(match.id)}
                        className="flex min-w-0 flex-1 items-center gap-3 hover:underline"
                    >
                        {match.photo_url ? (
                            <img
                                src={match.photo_url}
                                alt=""
                                className="size-12 shrink-0 rounded-md object-cover"
                            />
                        ) : (
                            <div className="flex size-12 shrink-0 items-center justify-center rounded-md bg-muted">
                                <Car className="size-5 text-muted-foreground" />
                            </div>
                        )}
                        <span className="min-w-0">
                            <span className="block truncate font-medium">
                                {match.name}
                            </span>
                            <span className="block truncate text-xs text-muted-foreground">
                                {[match.brand, match.series, match.color]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </span>
                            {match.status === 'wishlist' &&
                                match.target_price && (
                                    <span className="block text-xs text-muted-foreground">
                                        Target maks{' '}
                                        {formatRupiah(match.target_price)}
                                    </span>
                                )}
                        </span>
                    </Link>
                    <Badge
                        variant={
                            match.status === 'wishlist' ? 'outline' : 'secondary'
                        }
                        className="shrink-0"
                    >
                        {match.status === 'wishlist'
                            ? 'Wishlist'
                            : match.status === 'sold'
                              ? 'Terjual'
                              : `Punya ${match.quantity}×`}
                    </Badge>
                    {actions?.(match)}
                </li>
            ))}
        </ul>
    );
}
