import { Head, Link } from '@inertiajs/react';
import { Home } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { logout } from '@/routes';

export default function NoHousehold() {
    return (
        <>
            <Head title="Sem residência" />
            <main className="flex min-h-svh items-center justify-center p-6">
                <div className="max-w-md space-y-4 text-center">
                    <Home className="mx-auto size-10 text-muted-foreground" />
                    <h1 className="text-xl font-semibold">
                        Sua conta não está vinculada a uma residência
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Peça a quem administra o sistema para adicionar você a
                        uma residência com o comando{' '}
                        <code className="rounded bg-muted px-1">
                            php artisan household:add-member
                        </code>
                        .
                    </p>
                    <Button asChild variant="outline">
                        <Link href={logout()} as="button">
                            Sair
                        </Link>
                    </Button>
                </div>
            </main>
        </>
    );
}
