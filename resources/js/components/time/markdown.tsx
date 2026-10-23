import Markdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { cn } from '@/lib/utils';

/**
 * Renders a user's Markdown safely: raw HTML is dropped (never injected),
 * and react-markdown's URL filter turns javascript:/data: links into empty
 * ones. GitHub-flavored extensions add tables, checklists and strikethrough.
 */
export function MarkdownView({
    content,
    className,
}: {
    content: string;
    className?: string;
}) {
    return (
        <div className={cn('markdown', className)}>
            <Markdown
                remarkPlugins={[remarkGfm]}
                skipHtml
                components={{
                    a: ({ href, children }) => (
                        <a
                            href={href}
                            target="_blank"
                            rel="noopener noreferrer nofollow"
                        >
                            {children}
                        </a>
                    ),
                    img: ({ alt }) => <span>{alt ? `[${alt}]` : ''}</span>,
                }}
            >
                {content}
            </Markdown>
        </div>
    );
}
