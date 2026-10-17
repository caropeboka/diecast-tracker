import { Link } from '@inertiajs/react';
import { Car } from 'lucide-react';
import DexController from '@/actions/App/Http/Controllers/DexController';
import { cn } from '@/lib/utils';
import type { DexRef } from '@/types/dex';

/** A dex entry in one line: thumbnail, "Hot Wheels Mainline 2026 #061", name, series. */
export function DexEntryCard({
    entry,
    className,
}: {
    entry: DexRef;
    className?: string;
}) {
    return (
        <Link
            href={DexController.show(entry.catalog_id)}
            className={cn(
                'flex items-center gap-3 rounded-lg border border-border/70 p-2 transition hover:border-brand/50',
                className,
            )}
        >
            <span className="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-md bg-muted/50">
                {entry.photo_url ? (
                    <img src={entry.photo_url} alt="" className="size-full object-contain" />
                ) : (
                    <Car className="size-5 text-muted-foreground/50" />
                )}
            </span>
            <span className="min-w-0">
                <span className="block text-xs text-muted-foreground">
                    {entry.catalog} · #{entry.number}
                </span>
                <span className="block truncate text-sm font-medium">
                    {entry.name}
                    {entry.variant_label && (
                        <span className="font-normal text-muted-foreground"> ({entry.variant_label})</span>
                    )}
                </span>
                {entry.series && (
                    <span className="block truncate text-xs text-muted-foreground">
                        {entry.series}
                        {entry.series_number && ` ${entry.series_number}`}
                        {entry.flags.sth ? ' · Super TH' : entry.flags.th ? ' · TH' : ''}
                    </span>
                )}
            </span>
        </Link>
    );
}
