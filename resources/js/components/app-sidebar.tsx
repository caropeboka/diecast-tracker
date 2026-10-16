import { Link, router } from '@inertiajs/react';
import {
    Car,
    Eye,
    Heart,
    Images,
    LayoutGrid,
    Map as MapIcon,
    Plus,
    ScanSearch,
} from 'lucide-react';
import { useEffect } from 'react';
import BulkScanController from '@/actions/App/Http/Controllers/BulkScanController';
import CheckController from '@/actions/App/Http/Controllers/CheckController';
import DiecastController from '@/actions/App/Http/Controllers/DiecastController';
import ExploreController from '@/actions/App/Http/Controllers/ExploreController';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const garageItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Koleksi',
        href: DiecastController.index(),
        icon: Car,
    },
    {
        title: 'Wishlist',
        href: DiecastController.index({ query: { status: 'wishlist' } }),
        icon: Heart,
    },
    {
        title: 'Ditemui',
        href: DiecastController.index({ query: { status: 'spotted' } }),
        icon: Eye,
    },
];

const toolItems: NavItem[] = [
    {
        title: 'Peta jelajah',
        href: ExploreController.index(),
        icon: MapIcon,
    },
    {
        title: 'Scan massal',
        href: BulkScanController.index(),
        icon: Images,
    },
    {
        title: 'Cek koleksi',
        href: CheckController.index(),
        icon: ScanSearch,
    },
];

export function AppSidebar() {
    const { setOpenMobile } = useSidebar();

    // On phones the sidebar is a slide-over panel: close it once a link has taken us to another page.
    useEffect(
        () => router.on('navigate', () => setOpenMobile(false)),
        [setOpenMobile],
    );

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                    <SidebarMenuItem className="mt-2">
                        <SidebarMenuButton
                            asChild
                            tooltip={{ children: 'Tambah diecast' }}
                            className="bg-brand-gradient font-semibold text-brand-foreground shadow-[0_8px_20px_-10px_var(--brand-glow)] hover:text-brand-foreground hover:brightness-110 active:text-brand-foreground"
                        >
                            <Link href={DiecastController.create()} prefetch>
                                <Plus />
                                <span>Tambah diecast</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-4 pt-2">
                <NavMain label="Garasi" items={garageItems} />
                <NavMain label="Alat" items={toolItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
