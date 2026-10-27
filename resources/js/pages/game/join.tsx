import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    GameButton,
    GameCard,
    GameShell,
} from '@/components/attention/game-shell';
import game from '@/routes/game';

/**
 * Public entry: type the room code shown on the projector.
 */
export default function GameJoin({
    code: initial,
    error,
}: {
    code: string;
    error: string | null;
}) {
    const [code, setCode] = useState(initial);

    function submit(event: React.FormEvent) {
        event.preventDefault();
        router.visit(game.landing({ query: { code: code.trim() } }));
    }

    return (
        <GameShell>
            <Head title="Jogo da Atenção" />
            <div className="flex flex-1 flex-col justify-center gap-6">
                <div className="space-y-2 text-center">
                    <p className="text-5xl">🧠</p>
                    <h1 className="text-3xl font-black">Jogo da Atenção</h1>
                    <p className="text-white/70">
                        Digite o código da sala que aparece na tela.
                    </p>
                </div>
                <GameCard>
                    <form onSubmit={submit} className="space-y-4">
                        <input
                            value={code}
                            onChange={(event) =>
                                setCode(event.target.value.toUpperCase())
                            }
                            maxLength={8}
                            autoFocus
                            autoCapitalize="characters"
                            autoComplete="off"
                            placeholder="CÓDIGO"
                            aria-label="Código da sala"
                            className="w-full rounded-2xl border-2 border-white/20 bg-white/10 px-4 py-4 text-center font-mono text-3xl font-bold tracking-[0.4em] uppercase placeholder:text-white/30 focus:border-fuchsia-400 focus:outline-none"
                        />
                        {error && (
                            <p className="text-center text-sm text-rose-300">
                                {error}
                            </p>
                        )}
                        <GameButton type="submit" disabled={code.trim() === ''}>
                            Entrar na sala
                        </GameButton>
                    </form>
                </GameCard>
            </div>
        </GameShell>
    );
}
