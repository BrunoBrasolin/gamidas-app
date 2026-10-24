import type { Editor } from '@tiptap/react';
import { useEditorState } from '@tiptap/react';
import {
    Bold,
    Code,
    FileText,
    ImagePlus,
    Italic,
    Link2,
    List,
    ListOrdered,
    Minus,
    Quote,
    Redo2,
    SquareCode,
    Strikethrough,
    Table,
    Underline,
    Undo2,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { FormEvent } from 'react';
import { useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { cn } from '@/lib/utils';
import { IMAGE_ACCEPT, PDF_ACCEPT } from './uploads';

function ToolButton({
    icon: Icon,
    label,
    shortcut,
    active = false,
    disabled = false,
    onClick,
}: {
    icon: LucideIcon;
    label: string;
    shortcut?: string;
    active?: boolean;
    disabled?: boolean;
    onClick: () => void;
}) {
    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            className={cn(
                'size-10 shrink-0',
                active && 'bg-accent text-accent-foreground',
            )}
            aria-label={label}
            aria-pressed={active}
            title={shortcut ? `${label} (${shortcut})` : label}
            disabled={disabled}
            // Keep the text selection when tapping the toolbar.
            onMouseDown={(event) => event.preventDefault()}
            onClick={onClick}
        >
            <Icon />
        </Button>
    );
}

function Divider() {
    return <span className="mx-1 h-6 w-px shrink-0 bg-border" aria-hidden />;
}

/** Turn "exemplo.com" into "https://exemplo.com"; null when not allowed. */
function normalizeUrl(value: string): string | null {
    const url = value.trim();

    if (url === '') {
        return null;
    }

    if (/^(https?:\/\/|mailto:)/i.test(url)) {
        return url;
    }

    if (/^[\w.+-]+@[\w-]+\.[\w.-]+$/.test(url)) {
        return `mailto:${url}`;
    }

    if (/^[\w-]+(\.[\w-]+)+([/?#].*)?$/.test(url)) {
        return `https://${url}`;
    }

    return null;
}

function LinkDialog({
    editor,
    open,
    onOpenChange,
}: {
    editor: Editor;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [value, setValue] = useState('');
    const [error, setError] = useState<string | null>(null);

    function submit(event: FormEvent) {
        event.preventDefault();
        const href = normalizeUrl(value);

        if (!href) {
            setError('Use um endereço que comece com https:// ou um e-mail.');

            return;
        }

        if (editor.state.selection.empty && !editor.isActive('link')) {
            editor
                .chain()
                .focus()
                .insertContent({
                    type: 'text',
                    text: value.trim(),
                    marks: [{ type: 'link', attrs: { href } }],
                })
                .run();
        } else {
            editor
                .chain()
                .focus()
                .extendMarkRange('link')
                .setLink({ href })
                .run();
        }

        onOpenChange(false);
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (next) {
                    setValue(
                        (editor.getAttributes('link').href as
                            | string
                            | undefined) ?? '',
                    );
                    setError(null);
                }

                onOpenChange(next);
            }}
        >
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogTitle>Link</DialogTitle>
                    <DialogDescription>
                        Selecione um trecho antes para transformá-lo em link.
                    </DialogDescription>
                    <div className="grid gap-1.5">
                        <Label htmlFor="link-url">Endereço</Label>
                        <Input
                            id="link-url"
                            value={value}
                            inputMode="url"
                            autoCapitalize="off"
                            autoCorrect="off"
                            placeholder="https://"
                            className="h-11 text-base"
                            autoFocus
                            onChange={(event) => setValue(event.target.value)}
                        />
                        {error && (
                            <p className="text-sm text-destructive">{error}</p>
                        )}
                    </div>
                    <DialogFooter className="gap-2">
                        {editor.isActive('link') && (
                            <Button
                                type="button"
                                variant="ghost"
                                className="h-11 sm:mr-auto"
                                onClick={() => {
                                    editor
                                        .chain()
                                        .focus()
                                        .extendMarkRange('link')
                                        .unsetLink()
                                        .run();
                                    onOpenChange(false);
                                }}
                            >
                                Remover link
                            </Button>
                        )}
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="secondary"
                                className="h-11"
                            >
                                Cancelar
                            </Button>
                        </DialogClose>
                        <Button type="submit" className="h-11">
                            Aplicar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Formatting toolbar: everything Markdown can do, without typing syntax.
 * Scrolls sideways on narrow screens; every target is 40px for touch.
 */
export function EditorToolbar({
    editor,
    onPickFiles,
}: {
    editor: Editor;
    onPickFiles: (files: File[]) => void;
}) {
    const [linkOpen, setLinkOpen] = useState(false);
    const images = useRef<HTMLInputElement>(null);
    const pdfs = useRef<HTMLInputElement>(null);

    const state = useEditorState({
        editor,
        selector: ({ editor: e }) => ({
            block: e.isActive('heading', { level: 1 })
                ? 'h1'
                : e.isActive('heading', { level: 2 })
                  ? 'h2'
                  : e.isActive('heading', { level: 3 })
                    ? 'h3'
                    : 'p',
            bold: e.isActive('bold'),
            italic: e.isActive('italic'),
            underline: e.isActive('underline'),
            strike: e.isActive('strike'),
            code: e.isActive('code'),
            bulletList: e.isActive('bulletList'),
            orderedList: e.isActive('orderedList'),
            blockquote: e.isActive('blockquote'),
            codeBlock: e.isActive('codeBlock'),
            link: e.isActive('link'),
            table: e.isActive('table'),
            canUndo: e.can().undo(),
            canRedo: e.can().redo(),
        }),
    });

    const chain = () => editor.chain().focus();

    function pick(event: React.ChangeEvent<HTMLInputElement>) {
        const files = Array.from(event.target.files ?? []);
        event.target.value = '';

        if (files.length > 0) {
            onPickFiles(files);
        }
    }

    return (
        <div
            role="toolbar"
            aria-label="Formatação"
            className="flex items-center gap-0.5 overflow-x-auto px-2 py-1.5 [scrollbar-width:none]"
        >
            <ToolButton
                icon={Undo2}
                label="Desfazer"
                shortcut="⌘Z"
                disabled={!state.canUndo}
                onClick={() => chain().undo().run()}
            />
            <ToolButton
                icon={Redo2}
                label="Refazer"
                shortcut="⇧⌘Z"
                disabled={!state.canRedo}
                onClick={() => chain().redo().run()}
            />
            <Divider />
            <NativeSelect
                aria-label="Estilo do texto"
                className="h-10 w-36 shrink-0"
                value={state.block}
                onChange={(event) => {
                    const value = event.target.value;

                    if (value === 'p') {
                        chain().setParagraph().run();
                    } else {
                        chain()
                            .setHeading({
                                level: Number(value.slice(1)) as 1 | 2 | 3,
                            })
                            .run();
                    }
                }}
            >
                <option value="p">Texto</option>
                <option value="h1">Título</option>
                <option value="h2">Subtítulo</option>
                <option value="h3">Seção</option>
            </NativeSelect>
            <Divider />
            <ToolButton
                icon={Bold}
                label="Negrito"
                shortcut="⌘B"
                active={state.bold}
                onClick={() => chain().toggleBold().run()}
            />
            <ToolButton
                icon={Italic}
                label="Itálico"
                shortcut="⌘I"
                active={state.italic}
                onClick={() => chain().toggleItalic().run()}
            />
            <ToolButton
                icon={Underline}
                label="Sublinhado"
                shortcut="⌘U"
                active={state.underline}
                onClick={() => chain().toggleUnderline().run()}
            />
            <ToolButton
                icon={Strikethrough}
                label="Riscado"
                active={state.strike}
                onClick={() => chain().toggleStrike().run()}
            />
            <ToolButton
                icon={Code}
                label="Código no texto"
                active={state.code}
                onClick={() => chain().toggleCode().run()}
            />
            <ToolButton
                icon={Link2}
                label="Link"
                shortcut="⌘K"
                active={state.link}
                onClick={() => setLinkOpen(true)}
            />
            <Divider />
            <ToolButton
                icon={List}
                label="Lista com marcadores"
                active={state.bulletList}
                onClick={() => chain().toggleBulletList().run()}
            />
            <ToolButton
                icon={ListOrdered}
                label="Lista numerada"
                active={state.orderedList}
                onClick={() => chain().toggleOrderedList().run()}
            />
            <ToolButton
                icon={Quote}
                label="Citação"
                active={state.blockquote}
                onClick={() => chain().toggleBlockquote().run()}
            />
            <ToolButton
                icon={SquareCode}
                label="Bloco de código"
                active={state.codeBlock}
                onClick={() => chain().toggleCodeBlock().run()}
            />
            <ToolButton
                icon={Minus}
                label="Linha divisória"
                onClick={() => chain().setHorizontalRule().run()}
            />
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className={cn(
                            'size-10 shrink-0',
                            state.table && 'bg-accent',
                        )}
                        aria-label="Tabela"
                        title="Tabela"
                        onMouseDown={(event) => event.preventDefault()}
                    >
                        <Table />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="start"
                    className="w-56"
                    onCloseAutoFocus={(event) => event.preventDefault()}
                >
                    <DropdownMenuItem
                        className="min-h-10"
                        onSelect={() =>
                            chain()
                                .insertTable({
                                    rows: 3,
                                    cols: 3,
                                    withHeaderRow: true,
                                })
                                .run()
                        }
                    >
                        Inserir tabela 3 × 3
                    </DropdownMenuItem>
                    {state.table && (
                        <>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                className="min-h-10"
                                onSelect={() => chain().addRowAfter().run()}
                            >
                                Adicionar linha abaixo
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                className="min-h-10"
                                onSelect={() => chain().addColumnAfter().run()}
                            >
                                Adicionar coluna à direita
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                className="min-h-10"
                                onSelect={() => chain().deleteRow().run()}
                            >
                                Excluir linha
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                className="min-h-10"
                                onSelect={() => chain().deleteColumn().run()}
                            >
                                Excluir coluna
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                className="min-h-10"
                                onSelect={() => chain().toggleHeaderRow().run()}
                            >
                                Alternar linha de cabeçalho
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                className="min-h-10"
                                variant="destructive"
                                onSelect={() => chain().deleteTable().run()}
                            >
                                Excluir tabela
                            </DropdownMenuItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
            <Divider />
            <Button
                type="button"
                variant="ghost"
                className="h-10 shrink-0 px-3"
                onMouseDown={(event) => event.preventDefault()}
                onClick={() => images.current?.click()}
            >
                <ImagePlus />
                Imagem
            </Button>
            <Button
                type="button"
                variant="ghost"
                className="h-10 shrink-0 px-3"
                onMouseDown={(event) => event.preventDefault()}
                onClick={() => pdfs.current?.click()}
            >
                <FileText />
                PDF
            </Button>
            <input
                ref={images}
                type="file"
                multiple
                accept={IMAGE_ACCEPT}
                className="sr-only"
                tabIndex={-1}
                onChange={pick}
            />
            <input
                ref={pdfs}
                type="file"
                multiple
                accept={PDF_ACCEPT}
                className="sr-only"
                tabIndex={-1}
                onChange={pick}
            />
            <LinkDialog
                editor={editor}
                open={linkOpen}
                onOpenChange={setLinkOpen}
            />
        </div>
    );
}
