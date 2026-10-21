import { Form, Head } from '@inertiajs/react';
import { Terminal } from 'lucide-react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import household from '@/routes/household';

type Props = {
    householdName: string;
    members: {
        id: number;
        user_id: number;
        name: string;
        email: string;
        payment_label_preposition: string;
        payment_label: string;
        joined_at: string | null;
    }[];
    prepositions: string[];
};

export default function Members({
    householdName,
    members,
    prepositions,
}: Props) {
    return (
        <>
            <Head title="Membros" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={`Membros de ${householdName}`}
                    description="Cada membro tem seu próprio login e sua área pessoal."
                />

                <div className="space-y-3">
                    {members.map((member) => (
                        <Card key={member.id} className="py-4">
                            <CardContent className="flex flex-col gap-4 px-4 sm:flex-row sm:items-end sm:justify-between">
                                <div>
                                    <div className="font-medium">
                                        {member.name}
                                    </div>
                                    <div className="text-sm text-muted-foreground">
                                        {member.email}
                                    </div>
                                    {member.joined_at && (
                                        <div className="text-xs text-muted-foreground">
                                            Membro desde{' '}
                                            {formatDateTime(member.joined_at)}
                                        </div>
                                    )}
                                    <div className="mt-1 text-sm">
                                        Botão de confirmação:{' '}
                                        <strong>
                                            “{member.payment_label}”
                                        </strong>
                                    </div>
                                </div>
                                <Form
                                    {...household.members.update.form(
                                        member.id,
                                    )}
                                    options={{ preserveScroll: true }}
                                    className="flex items-end gap-2"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <div className="grid gap-1.5">
                                                <Label
                                                    htmlFor={`preposition-${member.id}`}
                                                >
                                                    Tratamento
                                                </Label>
                                                <NativeSelect
                                                    id={`preposition-${member.id}`}
                                                    name="payment_label_preposition"
                                                    defaultValue={
                                                        member.payment_label_preposition
                                                    }
                                                    className="w-28"
                                                >
                                                    {prepositions.map(
                                                        (preposition) => (
                                                            <option
                                                                key={
                                                                    preposition
                                                                }
                                                                value={
                                                                    preposition
                                                                }
                                                            >
                                                                {preposition}
                                                            </option>
                                                        ),
                                                    )}
                                                </NativeSelect>
                                                <InputError
                                                    message={
                                                        errors.payment_label_preposition
                                                    }
                                                />
                                            </div>
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                {processing && <Spinner />}
                                                Salvar
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <Alert>
                    <Terminal />
                    <AlertTitle>Adicionar membros</AlertTitle>
                    <AlertDescription>
                        <p>
                            Por segurança, o cadastro público está desativado.
                            Novos membros são criados por quem administra o
                            servidor com o comando{' '}
                            <code className="rounded bg-muted px-1">
                                php artisan household:add-member
                            </code>
                            , que pede a senha sem exibi-la. Ao entrar um novo
                            membro, as despesas pendentes passam a ser divididas
                            também com ele.
                        </p>
                    </AlertDescription>
                </Alert>
            </div>
        </>
    );
}

Members.layout = {
    breadcrumbs: [
        {
            title: 'Configurações da casa',
            href: household.members.index(),
        },
    ],
};
