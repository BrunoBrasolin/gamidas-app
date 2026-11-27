import { router } from '@inertiajs/react';
import { Check, Copy, ExternalLink, Share2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import tierLists from '@/routes/tier-lists';

/**
 * The button that makes a tier list public: creates the link, copies it and
 * takes the page down again.
 */
export function ShareTierList({
    kind,
    url,
    visible,
}: {
    kind: 'games' | 'shows';
    /** The public address, or null while the list is private. */
    url: string | null;
    /** What other people see on the page. */
    visible: string;
}) {
    const [processing, setProcessing] = useState(false);
    const [copied, copy] = useClipboard();

    const options = {
        preserveScroll: true,
        preserveState: true,
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
    };

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="outline">
                    <Share2 />
                    Compartilhar
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>Compartilhar a tier list</DialogTitle>
                <DialogDescription>
                    Quem tiver o link vê a lista, sem precisar de conta e sem
                    poder mudar nada. Aparecem seu primeiro nome e {visible}.
                </DialogDescription>

                {url === null ? (
                    <DialogFooter>
                        <Button
                            disabled={processing}
                            onClick={() =>
                                router.post(
                                    tierLists.share.store.url(kind),
                                    {},
                                    options,
                                )
                            }
                        >
                            {processing && <Spinner />}
                            Criar link público
                        </Button>
                    </DialogFooter>
                ) : (
                    <>
                        <div className="flex gap-2">
                            <Input
                                readOnly
                                value={url}
                                aria-label="Link público"
                                onFocus={(event) => event.target.select()}
                            />
                            <Button
                                size="icon"
                                variant="outline"
                                aria-label="Copiar o link"
                                onClick={() => void copy(url)}
                            >
                                {copied === url ? <Check /> : <Copy />}
                            </Button>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            O link mostra sempre a versão atual da lista.
                            Desativando, ele para de funcionar; um novo link
                            será diferente.
                        </p>
                        <DialogFooter>
                            <Button
                                variant="ghost"
                                className="text-destructive"
                                disabled={processing}
                                onClick={() =>
                                    router.delete(
                                        tierLists.share.destroy.url(kind),
                                        options,
                                    )
                                }
                            >
                                {processing && <Spinner />}
                                Desativar link
                            </Button>
                            <Button asChild>
                                <a href={url} target="_blank" rel="noreferrer">
                                    <ExternalLink />
                                    Abrir página
                                </a>
                            </Button>
                        </DialogFooter>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
