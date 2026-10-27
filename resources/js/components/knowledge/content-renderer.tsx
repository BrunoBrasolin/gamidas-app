import type { ReactNode } from 'react';
import { safeHref } from '@/lib/knowledge';
import type { KnowledgeAttachmentMap, KnowledgeNode } from '@/types';
import {
    FileCard,
    MissingAttachment,
    KnowledgeFigure,
} from './attachment-viewers';

type Context = {
    attachments: KnowledgeAttachmentMap;
    headingIds: WeakMap<KnowledgeNode, string>;
};

function intAttr(node: KnowledgeNode, name: string): number | undefined {
    const value = node.attrs?.[name];

    return typeof value === 'number' && Number.isFinite(value)
        ? value
        : undefined;
}

function renderText(node: KnowledgeNode, key: number): ReactNode {
    let element: ReactNode = node.text ?? '';

    for (const mark of node.marks ?? []) {
        switch (mark.type) {
            case 'bold':
                element = <strong>{element}</strong>;
                break;
            case 'italic':
                element = <em>{element}</em>;
                break;
            case 'underline':
                element = <u>{element}</u>;
                break;
            case 'strike':
                element = <s>{element}</s>;
                break;
            case 'code':
                element = <code>{element}</code>;
                break;
            case 'link': {
                const href = safeHref(mark.attrs?.href);

                if (href) {
                    element = (
                        <a
                            href={href}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {element}
                        </a>
                    );
                }

                break;
            }
        }
    }

    return <span key={key}>{element}</span>;
}

function renderChildren(node: KnowledgeNode, context: Context): ReactNode[] {
    return (node.content ?? []).map((child, index) =>
        renderNode(child, index, context),
    );
}

function renderNode(
    node: KnowledgeNode,
    key: number,
    context: Context,
): ReactNode {
    const children = () => renderChildren(node, context);

    switch (node.type) {
        case 'text':
            return renderText(node, key);
        case 'paragraph':
            return <p key={key}>{children()}</p>;
        case 'heading': {
            // The page title is the h1; document levels 1–3 become h2–h4 and
            // keep the look of the editor through the .knowledge-hN classes.
            const level = Math.min(3, Math.max(1, intAttr(node, 'level') ?? 2));
            const Tag = (['h2', 'h3', 'h4'] as const)[level - 1];

            return (
                <Tag
                    key={key}
                    id={context.headingIds.get(node)}
                    className={`knowledge-h${level} scroll-mt-24`}
                >
                    {children()}
                </Tag>
            );
        }
        case 'bulletList':
            return <ul key={key}>{children()}</ul>;
        case 'orderedList':
            return (
                <ol key={key} start={intAttr(node, 'start')}>
                    {children()}
                </ol>
            );
        case 'listItem':
            return <li key={key}>{children()}</li>;
        case 'blockquote':
            return <blockquote key={key}>{children()}</blockquote>;
        case 'codeBlock':
            return (
                <pre key={key}>
                    <code>{children()}</code>
                </pre>
            );
        case 'horizontalRule':
            return <hr key={key} />;
        case 'hardBreak':
            return <br key={key} />;
        case 'table':
            return (
                <div key={key} className="knowledge-table">
                    <table>
                        <tbody>{children()}</tbody>
                    </table>
                </div>
            );
        case 'tableRow':
            return <tr key={key}>{children()}</tr>;
        case 'tableHeader':
        case 'tableCell': {
            const Cell = node.type === 'tableHeader' ? 'th' : 'td';

            return (
                <Cell
                    key={key}
                    colSpan={intAttr(node, 'colspan')}
                    rowSpan={intAttr(node, 'rowspan')}
                >
                    {children()}
                </Cell>
            );
        }
        case 'knowledgeImage':
        case 'knowledgeFile': {
            const id = intAttr(node, 'attachmentId');
            const attachment =
                id === undefined ? undefined : context.attachments[String(id)];
            const kind = node.type === 'knowledgeImage' ? 'image' : 'file';

            if (!attachment) {
                return <MissingAttachment key={key} kind={kind} />;
            }

            const alt =
                typeof node.attrs?.alt === 'string' ? node.attrs.alt : '';

            return kind === 'image' ? (
                <KnowledgeFigure key={key} attachment={attachment} alt={alt} />
            ) : (
                <FileCard key={key} attachment={attachment} />
            );
        }
        default:
            // Unknown nodes: keep their text, drop the wrapper.
            return node.content ? <div key={key}>{children()}</div> : null;
    }
}

/**
 * Renders a stored knowledge page (Tiptap JSON) as React elements. Nothing is
 * injected as HTML: text goes through React and links are limited to web and
 * e-mail addresses, so the output is safe by construction.
 */
export function ContentRenderer({
    doc,
    attachments,
    headingIds,
}: {
    doc: KnowledgeNode;
    attachments: KnowledgeAttachmentMap;
    headingIds: WeakMap<KnowledgeNode, string>;
}) {
    return (
        <div className="knowledge-prose">
            {renderChildren(doc, { attachments, headingIds })}
        </div>
    );
}
