import type { ReactNode } from 'react';
import { Brain } from 'lucide-react';

/**
 * Full-screen frame of the public game pages: always dark and colorful,
 * whatever the appearance setting, sized for a phone held upright.
 */
export function GameShell({
    header,
    children,
}: {
    header?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="min-h-svh bg-gradient-to-b from-indigo-950 via-slate-900 to-slate-950 text-white">
            <div className="mx-auto flex min-h-svh w-full max-w-md flex-col gap-4 px-4 py-4">
                <header className="flex items-center justify-between gap-3">
                    <span className="flex items-center gap-2 text-sm font-semibold tracking-wide text-white/80">
                        <Brain className="size-5 text-fuchsia-300" aria-hidden />
                        Jogo da Atenção
                    </span>
                    {header}
                </header>
                <main className="flex flex-1 flex-col">{children}</main>
                <footer className="text-center text-[11px] text-white/40">
                    Demonstração didática — não é um teste psicológico.
                </footer>
            </div>
        </div>
    );
}

export function GameCard({
    children,
    className = '',
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={`animate-in rounded-3xl border border-white/10 bg-white/5 p-5 shadow-2xl backdrop-blur duration-300 fade-in slide-in-from-bottom-4 ${className}`}
        >
            {children}
        </div>
    );
}

export function GameButton({
    children,
    onClick,
    disabled,
    type = 'button',
}: {
    children: ReactNode;
    onClick?: () => void;
    disabled?: boolean;
    type?: 'button' | 'submit';
}) {
    return (
        <button
            type={type}
            onClick={onClick}
            disabled={disabled}
            className="w-full rounded-2xl bg-gradient-to-r from-fuchsia-500 to-indigo-500 px-6 py-4 text-lg font-bold shadow-lg shadow-fuchsia-500/30 transition active:scale-95 disabled:opacity-50"
        >
            {children}
        </button>
    );
}
