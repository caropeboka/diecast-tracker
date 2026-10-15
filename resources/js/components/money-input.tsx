import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/** Number input with an "Rp" prefix. Value is the plain number as a string. */
export default function MoneyInput({
    className,
    ...props
}: Omit<React.ComponentProps<typeof Input>, 'type'>) {
    return (
        <div className="relative">
            <span className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground">
                Rp
            </span>
            <Input
                type="number"
                min={0}
                step={500}
                inputMode="numeric"
                className={cn('pl-9', className)}
                {...props}
            />
        </div>
    );
}
