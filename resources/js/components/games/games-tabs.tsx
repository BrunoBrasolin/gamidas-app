import { Link } from '@inertiajs/react';
import { Crown, Gift, Library, ListTodo, Users } from 'lucide-react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import games from '@/routes/games';

const TABS = [
    { title: 'Biblioteca', href: games.index(), icon: Library },
    { title: 'Para zerar', href: games.backlog(), icon: ListTodo },
    { title: 'Tier list', href: games.tiers(), icon: Crown },
    { title: 'Lista de desejos', href: games.wishlist(), icon: Gift },
    { title: 'Amigos', href: games.friends(), icon: Users },
];

/**
 * The sections of the user's Steam account.
 */
export function GamesTabs() {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <nav
            aria-label="Seções da Steam"
            className="-mx-4 flex gap-1 overflow-x-auto overflow-y-hidden border-b px-4"
        >
            {TABS.map((tab) => {
                const active = isCurrentUrl(tab.href);

                return (
                    <Link
                        key={tab.title}
                        href={tab.href}
                        aria-current={active ? 'page' : undefined}
                        className={cn(
                            '-mb-px flex shrink-0 items-center gap-2 border-b-2 px-3 py-2 text-sm font-medium transition-colors',
                            active
                                ? 'border-primary text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                    >
                        <tab.icon className="size-4" aria-hidden />
                        {tab.title}
                    </Link>
                );
            })}
        </nav>
    );
}
