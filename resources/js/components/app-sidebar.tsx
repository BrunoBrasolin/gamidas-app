import { Link, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
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
import {
    collegeNavItems,
    mainNavItems,
    showNavItems,
    knowledgeNavItems,
    timeNavItems,
} from '@/lib/navigation';
import { dashboard } from '@/routes';

export function AppSidebar() {
    const { url } = usePage();
    const { setOpenMobile } = useSidebar();

    // Layouts are persistent, so the mobile sheet would stay open across visits.
    useEffect(() => {
        setOpenMobile(false);
    }, [url, setOpenMobile]);

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
                <NavMain items={timeNavItems} label="Tempo" />
                <NavMain
                    items={knowledgeNavItems}
                    label="Base de Conhecimento"
                />
                <NavMain items={collegeNavItems} label="Faculdade" />
                <NavMain items={showNavItems} label="Lazer" />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
