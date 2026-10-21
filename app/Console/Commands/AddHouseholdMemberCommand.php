<?php

namespace App\Console\Commands;

use App\Models\Household;
use App\Models\HouseholdMember;
use App\Services\HouseholdService;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Creates a user account inside a household. The password is only ever read
 * through a hidden prompt, so it never appears in shell history or process
 * lists.
 */
class AddHouseholdMemberCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'household:add-member {household : The household id}';

    /**
     * @var string
     */
    protected $description = 'Create a user account and add it to a household';

    public function handle(HouseholdService $households): int
    {
        $id = $this->argument('household');
        $household = is_numeric($id) ? Household::query()->find((int) $id) : null;

        if ($household === null) {
            $this->components->error('Residência não encontrada.');

            return self::FAILURE;
        }

        $name = text(label: 'Nome completo', required: true, validate: fn (string $value) => $this->check('name', $value, ['string', 'max:255']));

        $email = text(label: 'E-mail (usado no login)', required: true, validate: fn (string $value) => $this->check(
            'email', mb_strtolower($value), ['email', 'max:255', 'unique:users,email'],
        ));

        $password = password(label: 'Senha', required: true, validate: fn (string $value) => $this->check('password', $value, [Password::default()]));

        $confirmation = password(label: 'Confirme a senha', required: true);

        if ($password !== $confirmation) {
            $this->components->error('As senhas não conferem.');

            return self::FAILURE;
        }

        $preposition = select(
            label: 'Tratamento no botão de pagamento ("Pago pelo Bruno", "Pago pela Letícia")',
            options: ['pelo' => 'pelo', 'pela' => 'pela', 'por' => 'por'],
        );

        $salary = text(
            label: 'Salário mensal (ex.: 8000.00)',
            default: '0.00',
            validate: fn (string $value) => preg_match(Money::DECIMAL_PATTERN, $value) === 1 && Money::toCents($value) <= Money::MAX_SALARY_CENTS
                ? null
                : 'Informe um valor como 8000.00.',
        );

        $member = $households->addMember(
            $household,
            trim($name),
            mb_strtolower(trim($email)),
            $password,
            in_array($preposition, HouseholdMember::PREPOSITIONS, true) ? (string) $preposition : 'por',
            Money::toCents($salary),
        );

        $this->components->info("{$member->user->name} agora faz parte de \"{$household->name}\".");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, mixed>  $rules
     */
    private function check(string $attribute, string $value, array $rules): ?string
    {
        $validator = Validator::make([$attribute => $value], [$attribute => $rules]);

        return $validator->fails() ? $validator->errors()->first($attribute) : null;
    }
}
