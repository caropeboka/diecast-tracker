import { Link } from '@inertiajs/react';
import { Camera, Car, Heart, Images, LayoutGrid, ScanSearch } from 'lucide-react';
import BulkScanController from '@/actions/App/Http/Controllers/BulkScanController';
import CheckController from '@/actions/App/Http/Controllers/CheckController';
import DiecastController from '@/actions/App/Http/Controllers/DiecastController';
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
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
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
        title: 'Tambah diecast',
        href: DiecastController.create(),
        icon: Camera,
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
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
