import { usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';

/** Brand tile (mark on the style's gradient) + app name, for the sidebar header. */
export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-brand-gradient text-brand-foreground shadow-[0_6px_18px_-6px_var(--brand-glow)]">
                <AppLogoIcon className="w-6 fill-current" />
            </div>
            <div className="ml-1 grid flex-1 text-left">
                <span className="truncate font-heading text-base leading-tight font-semibold">
                    {name}
                </span>
                <span className="truncate text-[11px] leading-tight text-muted-foreground">
                    Koleksi diecast
                </span>
            </div>
        </>
    );
}
