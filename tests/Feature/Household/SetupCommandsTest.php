<?php

namespace Tests\Feature\Household;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class SetupCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_household_is_created_with_default_categories(): void
    {
        $this->artisan('household:create', ['--name' => 'Casa Bruno & Letícia'])->assertSuccessful();

        $household = Household::query()->sole();
        $this->assertSame('Casa Bruno & Letícia', $household->name);
        $this->assertTrue($household->categories()->where('name', 'Parcela Casa')->exists());
        $this->assertSame(count(config('finance.default_categories')), $household->categories()->count());
    }

    public function test_members_are_created_with_hashed_passwords_and_verified_emails(): void
    {
        $this->artisan('household:create', ['--name' => 'Casa'])->assertSuccessful();
        $household = Household::query()->sole();

        $this->addMember($household, 'Bruno Brasolin', 'Bruno@Example.test', 'pelo', '8000.00')->assertSuccessful();
        $this->addMember($household, 'Letícia Souza', 'leticia@example.test', 'pela', '2000')->assertSuccessful();

        $bruno = User::query()->where('email', 'bruno@example.test')->sole();
        $this->assertNotSame('uma-senha-segura', $bruno->password);
        $this->assertTrue(Hash::check('uma-senha-segura', $bruno->password));
        $this->assertNotNull($bruno->email_verified_at);
        $this->assertSame(800_000, $bruno->membership?->monthly_salary_cents);
        $this->assertSame('Pago pelo Bruno', $bruno->membership->paymentLabel());

        $this->assertSame([$bruno->id => 800_000, User::query()->where('email', 'leticia@example.test')->value('id') => 200_000], $household->salaries()->all());
    }

    public function test_mismatched_passwords_do_not_create_an_account(): void
    {
        $this->artisan('household:create', ['--name' => 'Casa'])->assertSuccessful();

        $this->artisan('household:add-member', ['household' => Household::query()->value('id')])
            ->expectsQuestion('Nome completo', 'Bruno')
            ->expectsQuestion('E-mail (usado no login)', 'bruno@example.test')
            ->expectsQuestion('Senha', 'uma-senha-segura')
            ->expectsQuestion('Confirme a senha', 'outra-senha')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_an_unknown_household_is_rejected(): void
    {
        $this->artisan('household:add-member', ['household' => 999])->assertFailed();
    }

    private function addMember(Household $household, string $name, string $email, string $preposition, string $salary): PendingCommand
    {
        return $this->artisan('household:add-member', ['household' => $household->id])
            ->expectsQuestion('Nome completo', $name)
            ->expectsQuestion('E-mail (usado no login)', $email)
            ->expectsQuestion('Senha', 'uma-senha-segura')
            ->expectsQuestion('Confirme a senha', 'uma-senha-segura')
            ->expectsQuestion('Tratamento no botão de pagamento ("Pago pelo Bruno", "Pago pela Letícia")', $preposition)
            ->expectsQuestion('Salário mensal (ex.: 8000.00)', $salary);
    }
}
