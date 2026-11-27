import { Head } from '@inertiajs/react';
import { GameImage } from '@/components/games/game-image';
import { ShowArt, showDescription } from '@/components/tiers/show-art';
import { coverUrl, headerUrl } from '@/lib/games';
import { TIERS } from '@/lib/tiers';
import { cn } from '@/lib/utils';
import type { Tier, TierShow } from '@/types';

type PublicGame = { app_id: number; name: string };

type Props = { title: string } & (
    | { kind: 'games'; tiers: Record<Tier, PublicGame[]> }
    | { kind: 'shows'; tiers: Record<Tier, TierShow[]> }
);

function Game({ game }: { game: PublicGame }) {
    return (
        <li className="w-20 sm:w-24">
            <a
                href={`https://store.steampowered.com/app/${game.app_id}`}
                target="_blank"
                rel="noopener noreferrer"
                title={game.name}
                aria-label={`${game.name} na loja da Steam`}
                className="block rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                <GameImage
                    urls={[coverUrl(game.app_id), headerUrl(game.app_id)]}
                    name={game.name}
                    className="aspect-[2/3] w-full rounded-md border"
                />
            </a>
        </li>
    );
}

function Concert({ show }: { show: TierShow }) {
    return (
        <li className="w-28 sm:w-32" title={showDescription(show)}>
            <ShowArt show={show} />
        </li>
    );
}

/**
 * A shared tier list, opened by anyone with the link: read only and outside
 * the app's layout.
 */
export default function PublicTierList(props: Props) {
    const empty = TIERS.every(({ tier }) => props.tiers[tier].length === 0);

    function items(tier: Tier) {
        return props.kind === 'games'
            ? props.tiers[tier].map((game) => (
                  <Game key={game.app_id} game={game} />
              ))
            : props.tiers[tier].map((show, index) => (
                  <Concert key={index} show={show} />
              ));
    }

    return (
        <>
            <Head title={props.title} />
            <main className="mx-auto flex min-h-svh w-full max-w-5xl flex-col gap-4 p-4 sm:py-8">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {props.title}
                </h1>

                {empty ? (
                    <p className="rounded-xl border border-dashed py-12 text-center text-sm text-muted-foreground">
                        Esta tier list ainda está vazia.
                    </p>
                ) : (
                    <div className="overflow-hidden rounded-xl border">
                        {TIERS.map(({ tier, className }) => (
                            <section
                                key={tier}
                                aria-label={`Tier ${tier}`}
                                className="flex min-h-20 border-b last:border-b-0 sm:min-h-24"
                            >
                                <h2
                                    className={cn(
                                        'flex w-12 shrink-0 items-center justify-center text-2xl font-bold sm:w-16 sm:text-3xl',
                                        className,
                                    )}
                                >
                                    {tier}
                                </h2>
                                <ul className="flex flex-1 flex-wrap content-start items-start gap-2 p-2">
                                    {items(tier)}
                                </ul>
                            </section>
                        ))}
                    </div>
                )}
            </main>
        </>
    );
}
