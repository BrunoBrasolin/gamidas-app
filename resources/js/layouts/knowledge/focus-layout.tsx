import type { ReactNode } from 'react';

/**
 * Full-screen layout of the knowledge reader and editor: no app sidebar, a warm
 * paper background and room for the page's own top bar.
 */
export default function KnowledgeFocusLayout({
    children,
}: {
    children: ReactNode;
}) {
    return (
        <div className="min-h-dvh bg-paper [padding-right:env(safe-area-inset-right)] [padding-left:env(safe-area-inset-left)] text-ink">
            {children}
        </div>
    );
}
