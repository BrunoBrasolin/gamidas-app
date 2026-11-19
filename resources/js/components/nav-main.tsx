import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuAction,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

export function NavMain({
    items,
    label,
}: {
    items: NavItem[];
    label?: string;
}) {
    const { currentUrl, isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
    // Sub-items stay folded away until their section is open or the
    // chevron unfolds them.
    const [unfolded, setUnfolded] = useState<Record<string, boolean>>({});

    const isActive = (item: NavItem) =>
        isCurrentUrl(item.href) ||
        (item.activePrefix !== undefined &&
            currentUrl.startsWith(item.activePrefix) &&
            !(
                item.inactivePrefix !== undefined &&
                currentUrl.startsWith(item.inactivePrefix)
            )) ||
        (item.items?.some((child) => isCurrentOrParentUrl(child.href)) ??
            false);

    return (
        <SidebarGroup className="px-2 py-0">
            {label && <SidebarGroupLabel>{label}</SidebarGroupLabel>}
            <SidebarMenu>
                {items.map((item) => {
                    const active = isActive(item);
                    const button = (
                        <SidebarMenuButton
                            asChild
                            isActive={active}
                            tooltip={{ children: item.title }}
                        >
                            <Link href={item.href} prefetch>
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                    );

                    if (!item.items) {
                        return (
                            <SidebarMenuItem key={item.title}>
                                {button}
                            </SidebarMenuItem>
                        );
                    }

                    const open = active || (unfolded[item.title] ?? false);

                    return (
                        <Collapsible
                            key={item.title}
                            asChild
                            open={open}
                            onOpenChange={(value) =>
                                setUnfolded((current) => ({
                                    ...current,
                                    [item.title]: value,
                                }))
                            }
                        >
                            <SidebarMenuItem>
                                {button}
                                {!active && (
                                    <CollapsibleTrigger asChild>
                                        <SidebarMenuAction
                                            className="transition-transform data-[state=open]:rotate-90"
                                            aria-label={
                                                open
                                                    ? `Recolher ${item.title}`
                                                    : `Mostrar ${item.title}`
                                            }
                                        >
                                            <ChevronRight />
                                        </SidebarMenuAction>
                                    </CollapsibleTrigger>
                                )}
                                <CollapsibleContent>
                                    <SidebarMenuSub>
                                        {item.items.map((child) => (
                                            <SidebarMenuSubItem
                                                key={child.title}
                                            >
                                                <SidebarMenuSubButton
                                                    asChild
                                                    isActive={isCurrentUrl(
                                                        child.href,
                                                    )}
                                                >
                                                    <Link
                                                        href={child.href}
                                                        prefetch
                                                    >
                                                        <span>
                                                            {child.title}
                                                        </span>
                                                    </Link>
                                                </SidebarMenuSubButton>
                                            </SidebarMenuSubItem>
                                        ))}
                                    </SidebarMenuSub>
                                </CollapsibleContent>
                            </SidebarMenuItem>
                        </Collapsible>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}
