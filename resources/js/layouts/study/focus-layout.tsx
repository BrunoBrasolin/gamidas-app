import type { ReactNode } from 'react';

/**
 * Full-screen layout of the study reader and editor: no app sidebar, a warm
 * paper background and room for the page's own top bar.
 */
export default function StudyFocusLayout({ children }: { children: ReactNode }) {
    return (
        <div className="min-h-dvh bg-paper text-ink [padding-left:env(safe-area-inset-left)] [padding-right:env(safe-area-inset-right)]">
            {children}
        </div>
    );
}
