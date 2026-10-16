import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import DiecastController from '@/actions/App/Http/Controllers/DiecastController';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        <header className="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-2 border-b border-sidebar-border/50 bg-background/75 px-4 backdrop-blur-md transition-[width,height] ease-linear md:rounded-t-xl md:px-4 group-has-data-[collapsible=icon]/sidebar-wrapper:h-12">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            {/* On phones the sidebar (with its add button) is hidden — keep adding one tap away. */}
            <Button
                size="sm"
                asChild
                className="ml-auto bg-brand-gradient text-brand-foreground md:hidden"
            >
                <Link href={DiecastController.create()}>
                    <Plus /> Tambah
                </Link>
            </Button>
        </header>
    );
}
