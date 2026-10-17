import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { percent } from '@/types/dex';

/**
 * Seen/caught progress on one bar: owned (strong step) · seen only (middle step) · the rest (track),
 * split by 2px surface gaps. Same ramp, so it reads as one measure; the legend carries the numbers.
 */
export function DexMeter({
    seen,
    caught,
    total,
    className,
}: {
    seen: number;
    caught: number;
    total: number;
    className?: string;
}) {
    const segments = [
        {
            key: 'caught',
            value: caught,
            className: 'bg-viz-bar',
            label: `Dimiliki: ${caught} dari ${total} (${percent(caught, total)}%)`,
        },
        {
            key: 'seen',
            value: seen - caught,
            className: 'bg-viz-bar-soft',
            label: `Dilihat, belum dimiliki: ${seen - caught}`,
        },
        {
            key: 'rest',
            value: total - seen,
            className: 'bg-viz-track',
            label: `Belum ditelusuri: ${total - seen}`,
        },
    ].filter((s) => s.value > 0);

    return (
        <div
            className={cn('flex h-2.5 w-full gap-[2px]', className)}
            role="img"
            aria-label={`Dilihat ${seen}, dimiliki ${caught}, dari ${total}`}
        >
            {segments.length === 0 && (
                <div className="h-full w-full rounded-[4px] bg-viz-track" />
            )}
            {segments.map((segment) => (
                <Tooltip key={segment.key}>
                    <TooltipTrigger asChild>
                        <div
                            className={cn(
                                'h-full min-w-1 rounded-[4px]',
                                segment.className,
                            )}
                            style={{ flexGrow: segment.value, flexBasis: 0 }}
                        />
                    </TooltipTrigger>
                    <TooltipContent>{segment.label}</TooltipContent>
                </Tooltip>
            ))}
        </div>
    );
}

/** "■ Dimiliki 12 (5%)  ■ Dilihat 34 (14%)  dari 250" — the meter's legend, in text colors. */
export function DexLegend({
    seen,
    caught,
    total,
    className,
}: {
    seen: number;
    caught: number;
    total: number;
    className?: string;
}) {
    return (
        <p
            className={cn(
                'flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground',
                className,
            )}
        >
            <span className="flex items-center gap-1.5">
                <span className="size-2.5 rounded-[3px] bg-viz-bar" />
                <span>
                    Dimiliki{' '}
                    <strong className="font-semibold text-foreground">
                        {caught}
                    </strong>{' '}
                    ({percent(caught, total)}%)
                </span>
            </span>
            <span className="flex items-center gap-1.5">
                <span className="size-2.5 rounded-[3px] bg-viz-bar-soft" />
                <span>
                    Dilihat{' '}
                    <strong className="font-semibold text-foreground">
                        {seen}
                    </strong>{' '}
                    ({percent(seen, total)}%)
                </span>
            </span>
            <span>dari {total}</span>
        </p>
    );
}
