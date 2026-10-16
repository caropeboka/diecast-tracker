import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

/**
 * Small, dependency-free chart pieces for the dashboard.
 * Specs: thin marks (≤24px), 4px rounded data-end and square baseline, hairline axis,
 * text in text colors (never the series color), hover/focus tooltip on every mark.
 */

export type BarRow = {
    key: string;
    label: string;
    value: number;
    /** Text at the bar's tip, e.g. "3" or "Rp 54 rb". */
    display: string;
    /** Extra line in the tooltip. */
    detail?: string;
    /** Tailwind bg class for an identity color; defaults to the single-series blue. */
    colorClass?: string;
    href?: string;
};

/** Horizontal bars, sorted by the caller. One series → one color; identity rows may pass colorClass. */
export function BarList({ rows }: { rows: BarRow[] }) {
    const max = Math.max(1, ...rows.map((row) => row.value));

    return (
        <ul className="space-y-3">
            {rows.map((row) => {
                const content = (
                    <>
                        <div className="flex items-baseline justify-between gap-3 text-sm">
                            <span className="truncate">{row.label}</span>
                            <span className="shrink-0 text-muted-foreground tabular-nums">
                                {row.display}
                            </span>
                        </div>
                        <div className="mt-1.5 h-2.5">
                            <div
                                className={cn(
                                    'h-full rounded-r-[4px] transition-[filter] group-hover:brightness-110 group-focus-visible:brightness-110',
                                    row.colorClass ?? 'bg-viz-bar',
                                )}
                                style={{
                                    width: `${Math.max(2, (row.value / max) * 100)}%`,
                                }}
                            />
                        </div>
                    </>
                );

                return (
                    <li key={row.key}>
                        <Tooltip>
                            <TooltipTrigger asChild>
                                {row.href ? (
                                    <Link
                                        href={row.href}
                                        className="group block rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        {content}
                                    </Link>
                                ) : (
                                    <div
                                        tabIndex={0}
                                        className="group rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        {content}
                                    </div>
                                )}
                            </TooltipTrigger>
                            <TooltipContent side="top">
                                <p className="font-semibold">{row.display}</p>
                                <p className="opacity-80">{row.label}</p>
                                {row.detail && (
                                    <p className="opacity-80">{row.detail}</p>
                                )}
                            </TooltipContent>
                        </Tooltip>
                    </li>
                );
            })}
        </ul>
    );
}

/** Vertical columns over an ordered axis (years). Height includes the label band — no nested scroll. */
export function ColumnChart({
    columns,
    height = 140,
}: {
    columns: { key: string; label: string; value: number; tooltip: string }[];
    height?: number;
}) {
    const max = Math.max(1, ...columns.map((c) => c.value));
    // Thin out x labels when there are many columns.
    const every = Math.ceil(columns.length / 10);

    return (
        <div>
            <div
                className="flex items-end gap-[2px] border-b border-border"
                style={{ height }}
            >
                {columns.map((column) => (
                    <Tooltip key={column.key}>
                        <TooltipTrigger asChild>
                            <div
                                tabIndex={0}
                                className="group flex h-full flex-1 flex-col items-center justify-end outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                aria-label={column.tooltip}
                            >
                                {column.value > 0 && (
                                    <span className="mb-1 text-xs text-muted-foreground tabular-nums">
                                        {column.value}
                                    </span>
                                )}
                                <div
                                    className="w-full max-w-6 rounded-t-[4px] bg-viz-bar transition-[filter] group-hover:brightness-110"
                                    style={{
                                        height: `${(column.value / max) * (height - 22)}px`,
                                    }}
                                />
                            </div>
                        </TooltipTrigger>
                        <TooltipContent side="top">{column.tooltip}</TooltipContent>
                    </Tooltip>
                ))}
            </div>
            <div className="mt-1.5 flex gap-[2px]">
                {columns.map((column, index) => (
                    <span
                        key={column.key}
                        className="flex-1 text-center text-[11px] text-muted-foreground tabular-nums"
                    >
                        {index % every === 0 ? column.label : ''}
                    </span>
                ))}
            </div>
        </div>
    );
}

/** Progress against a total. Track is a lighter step of the fill's own ramp. */
export function Meter({ value, total }: { value: number; total: number }) {
    const percent = total ? Math.round((value / total) * 100) : 0;

    return (
        <div
            className="h-2 overflow-hidden rounded-full bg-viz-track"
            role="meter"
            aria-valuemin={0}
            aria-valuemax={total}
            aria-valuenow={value}
        >
            <div
                className="h-full rounded-full bg-viz-bar"
                style={{ width: `${percent}%` }}
            />
        </div>
    );
}

/** Stat tile: label · value · optional hint/delta line. */
export function StatTile({
    icon,
    label,
    value,
    hint,
    hintClassName,
    href,
}: {
    icon: ReactNode;
    label: string;
    value: ReactNode;
    hint?: ReactNode;
    hintClassName?: string;
    href?: string;
}) {
    const body = (
        <>
            <div className="flex items-center gap-2.5 text-sm text-muted-foreground">
                <span className="flex size-8 items-center justify-center rounded-lg bg-brand/12 text-brand [&>svg]:size-4">
                    {icon}
                </span>
                {label}
            </div>
            <p className="mt-3 text-2xl font-semibold tracking-tight">
                {value}
            </p>
            {hint && (
                <p className={cn('mt-0.5 text-xs text-muted-foreground', hintClassName)}>
                    {hint}
                </p>
            )}
        </>
    );

    const className = 'surface block rounded-xl p-4';

    return href ? (
        <Link href={href} className={cn(className, 'lift')}>
            {body}
        </Link>
    ) : (
        <div className={className}>{body}</div>
    );
}

/** Card frame with a title row. */
export function Panel({
    title,
    icon,
    action,
    children,
    className,
}: {
    title: string;
    icon?: ReactNode;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={cn('surface rounded-xl p-4 md:p-5', className)}>
            <div className="mb-4 flex items-center justify-between gap-2">
                <h3 className="flex items-center gap-2 font-heading text-base font-semibold [&>svg]:text-brand">
                    {icon}
                    {title}
                </h3>
                {action}
            </div>
            {children}
        </section>
    );
}
