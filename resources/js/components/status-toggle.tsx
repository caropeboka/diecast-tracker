import { BadgeDollarSign, Car, Eye, Heart } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { DiecastStatus } from '@/types/diecast';

const OPTIONS: Record<
    DiecastStatus,
    { label: string; hint: string; icon: LucideIcon }
> = {
    owned: { label: 'Dimiliki', hint: 'Sudah ada di garasi', icon: Car },
    wishlist: { label: 'Wishlist', hint: 'Masih diburu', icon: Heart },
    spotted: { label: 'Ditemui', hint: 'Pernah lihat di luar', icon: Eye },
    sold: { label: 'Terjual', hint: 'Pernah dimiliki', icon: BadgeDollarSign },
};

/**
 * Segmented "mode" picker for a diecast's status. It decides which fields the form shows,
 * so it sits at the top instead of being a dropdown among the other fields.
 */
export default function StatusToggle({
    value,
    onChange,
    options = ['owned', 'wishlist', 'spotted', 'sold'],
    compact = false,
}: {
    value: DiecastStatus;
    onChange: (status: DiecastStatus) => void;
    options?: DiecastStatus[];
    compact?: boolean;
}) {
    return (
        <div
            role="radiogroup"
            aria-label="Status diecast"
            className={cn(
                'grid gap-1 rounded-xl bg-muted p-1',
                options.length === 4
                    ? 'grid-cols-2 sm:grid-cols-4'
                    : options.length === 3
                      ? 'grid-cols-3'
                      : 'grid-cols-2',
            )}
        >
            {options.map((key) => {
                const { label, hint, icon: Icon } = OPTIONS[key];
                const active = value === key;

                return (
                    <button
                        key={key}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        onClick={() => onChange(key)}
                        className={cn(
                            'flex items-center justify-center gap-2 rounded-lg px-3 text-sm transition',
                            compact ? 'py-1.5' : 'py-2.5 sm:justify-start',
                            active
                                ? 'bg-card text-foreground shadow-sm ring-1 ring-brand/50'
                                : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                        )}
                    >
                        <Icon
                            className={cn(
                                'size-4 shrink-0',
                                active && 'text-brand',
                            )}
                        />
                        <span className="text-left">
                            <span className="block font-medium">{label}</span>
                            {!compact && (
                                <span className="hidden text-xs text-muted-foreground sm:block">
                                    {hint}
                                </span>
                            )}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}
