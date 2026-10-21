# Finanças da Casa

Aplicação de finanças da casa e pessoais para Bruno e Letícia, substituindo as planilhas de Excel. Interface em português (pt-BR), valores em Real (`R$ 1.234,56`) e fuso horário `America/Sao_Paulo`.

Construída sobre o **Laravel React Starter Kit** (Laravel 13, Inertia v3, React 19, TypeScript, Tailwind CSS 4, shadcn/ui, Fortify, Wayfinder).

## Funcionalidades

- **Painel** do mês: total da casa, parte de cada membro, renda combinada e proporções, pagas × pendentes, vencidas, próximos vencimentos, composição (fixas, extras temporárias, avulsas) e resumo pessoal.
- **Despesas da casa**: tabela mensal com total, parte de cada membro, vencimento, criador, confirmação de pagamento e situação; filtros por mês, criador, situação e origem; aba de despesas pessoais.
- **Despesas recorrentes**: fixas (sem prazo ou até um mês final) e extras temporárias (valor mensal por N meses), com geração mensal idempotente.
- **Finanças pessoais** (privadas): receitas, despesas e investimentos; resumo mensal e anual com fluxo líquido e saldo disponível.
- **Relatórios** anuais da casa e pessoais.
- **Configurações da casa**: salários (com histórico) e membros.

## Requisitos

| Componente     | Versão                                            |
| -------------- | ------------------------------------------------- |
| PHP            | 8.3+ com `pdo_mysql`, `pdo_sqlite` ou `pdo_pgsql` |
| Composer       | 2.x                                               |
| Node.js        | 22+ (testado com 24)                              |
| Banco de dados | MySQL 8, PostgreSQL ou SQLite                     |

Nenhuma extensão de precisão (`bcmath`, `intl`) é necessária: valores monetários são inteiros em centavos.

## Instalação

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

### Configuração do ambiente (`.env`)

```dotenv
APP_NAME="Finanças da Casa"
APP_LOCALE=pt_BR
APP_TIMEZONE=America/Sao_Paulo

# SQLite (padrão do starter kit; exige a extensão pdo_sqlite)
DB_CONNECTION=sqlite

# ou MySQL
# DB_CONNECTION=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=financas
# DB_USERNAME=...
# DB_PASSWORD=...
```

Em produção use `APP_ENV=production`, `APP_DEBUG=false` e HTTPS. Nesse ambiente as senhas exigem 12+ caracteres, maiúsculas, minúsculas, números e símbolos, e não podem constar em vazamentos conhecidos.

### Banco de dados

```bash
php artisan migrate
```

O `DatabaseSeeder` é propositalmente vazio: nenhum dado financeiro fictício ou senha é semeado.

## Criação da residência e das contas

O cadastro público está **desativado** (removido pelo `install:features` do starter kit). As contas são criadas no servidor com comandos Artisan; a senha é lida por um prompt oculto e nunca aparece no histórico do shell nem em argumentos de processo.

```bash
php artisan household:create --name="Casa Bruno & Letícia"
php artisan household:add-member 1   # Bruno: tratamento "pelo", salário 8000.00
php artisan household:add-member 1   # Letícia: tratamento "pela", salário 2000.00
```

O comando `household:add-member` pergunta nome, e-mail, senha (duas vezes), o tratamento usado no botão de pagamento (`pelo`/`pela`/`por`, para formar "Pago pelo Bruno" e "Pago pela Letícia") e o salário mensal. O e-mail já fica verificado. Novos membros podem ser adicionados depois da mesma forma.

Cada membro pode ativar autenticação em dois fatores e chaves de acesso (passkeys) em **Minha conta › Segurança**.

## Executando localmente

```bash
composer dev        # servidor, fila, logs e Vite
```

Ou, separadamente, `php artisan serve` e `npm run dev`. Acesse `http://localhost:8000`.

## Tarefas agendadas

Despesas fixas sem prazo são geradas até `FINANCE_GENERATION_MONTHS_AHEAD` meses à frente (padrão 2). O comando é agendado diariamente às 00:10:

```bash
php artisan expenses:generate                 # manual, idempotente
php artisan expenses:generate --through=2027-06
```

Configure o agendador no servidor com a entrada de cron padrão do Laravel:

```cron
* * * * * cd /caminho/do/projeto && php artisan schedule:run >> /dev/null 2>&1
```

Mesmo sem o agendador, abrir um mês no painel ou na lista de despesas gera os meses que faltarem (até 24 meses à frente), de forma idempotente.

## Testes e build

```bash
php artisan test        # PHPUnit
composer test           # Pint (verificação), Larastan e PHPUnit
npm run types:check     # TypeScript
npm run check           # lint e formatação (vite-plus)
npm run build           # build do frontend
```

Os testes de páginas precisam do build do frontend (`public/build/manifest.json`), como no CI. Por padrão os testes usam SQLite em memória (`phpunit.xml`); para rodar em outro banco, defina as variáveis antes do comando, por exemplo `DB_CONNECTION=mysql DB_DATABASE=testes php artisan test`.

## Deploy (Hostinger)

O workflow `.github/workflows/deploy.yml` roda a cada push na `main` (ou manualmente). Primeiro executa os testes (`tests.yml`); se passarem, gera o release no GitHub Actions (`composer install --no-dev` e `npm run build`), envia um tarball por SCP e o sincroniza no servidor com `rsync`. O servidor não precisa de acesso ao GitHub, Composer nem Node.

No servidor, o deploy coloca a aplicação em manutenção, sincroniza os arquivos e roda `migrate --force` e `optimize`. Ficam preservados `.env`, `storage/`, `public/storage`, bancos SQLite em `database/` e os arquivos `.htaccess` / `.user.ini` da raiz.

### Configuração no GitHub

Em **Settings › Secrets and variables › Actions**:

| Tipo     | Nome           | Exemplo                                 |
| -------- | -------------- | --------------------------------------- |
| Secret   | `SSH_HOST`     | IP do servidor (hPanel › SSH Access)    |
| Secret   | `SSH_PORT`     | `65002`                                 |
| Secret   | `SSH_USER`     | `u123456789`                            |
| Secret   | `SSH_PASSWORD` | senha do SSH                            |
| Variable | `DEPLOY_PATH`  | `domains/seudominio.com.br/public_html` |

`DEPLOY_PATH` é relativo à home do usuário SSH.

### Preparação do servidor (uma vez)

1. Ative o SSH no hPanel e selecione PHP 8.3 para o domínio. O workflow usa `/opt/alt/php83/usr/bin/php`.
2. Crie o banco MySQL no hPanel e o `.env` de produção em `$DEPLOY_PATH/.env` (`APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...`, `DB_CONNECTION=mysql` etc.). Gere a chave com `php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"` e preencha `APP_KEY`. O deploy falha se o `.env` não existir.
3. Como a aplicação fica dentro do `public_html`, crie `$DEPLOY_PATH/.htaccess` para servir apenas a pasta `public/` (assim `.env`, `vendor/` etc. ficam inacessíveis):

    ```apache
    <IfModule mod_rewrite.c>
        RewriteEngine On
        RewriteRule ^(.*)$ public/$1 [L]
    </IfModule>
    ```

4. Depois do primeiro deploy, crie a residência e as contas pelo SSH (veja [Criação da residência e das contas](#criação-da-residência-e-das-contas)), usando `/opt/alt/php83/usr/bin/php artisan ...`.
5. Em hPanel › Avançado › Cron Jobs, agende a cada minuto:

    ```cron
    /opt/alt/php83/usr/bin/php /home/<usuario>/<DEPLOY_PATH>/artisan schedule:run >> /dev/null 2>&1
    ```

## Regras de negócio

### Dinheiro

- Todos os valores são armazenados como **inteiros em centavos** (`BIGINT UNSIGNED`); não há aritmética de ponto flutuante em cálculos.
- Os formulários enviam valores decimais normalizados (`1234.56`), convertidos para centavos no servidor por manipulação de texto.
- Limites: R$ 9.999.999,99 por lançamento e R$ 999.999,99 de salário mensal.

### Divisão proporcional

- `parte do membro = valor × salário do membro / soma dos salários`, sempre a partir dos salários configurados (não há percentuais digitados).
- Cada membro recebe o valor arredondado para baixo; os centavos que sobram (menos que o número de membros) vão um a um para quem tem a **maior fração** (desempate: maior salário, depois ordem de entrada na residência). A soma das partes é sempre exatamente o valor da conta.
- **Renda combinada zero**: a conta é dividida igualmente e o painel avisa para configurar os salários.
- As partes ficam gravadas por despesa (`expense_shares`), junto com os salários usados no cálculo.

### Alteração de salário

- Em uma única transação: os salários são atualizados, **todas as despesas da casa pendentes** são recalculadas e gravadas, e a alteração é registrada na auditoria (antes, depois e quantidade recalculada).
- Despesas **pagas** mantêm as partes do momento do pagamento; valor, data e responsável pelo pagamento não mudam.
- Ao entrar um novo membro, as despesas pendentes passam a incluí-lo.

### Despesas recorrentes e ocorrências

- Modelos (`recurring_expenses`) são separados das ocorrências mensais (`expense_occurrences`). Os totais somam **apenas ocorrências**; modelos nunca são somados, evitando contagem dupla.
- Um índice único `(recurring_expense_id, billing_month)` impede meses duplicados; a geração ignora meses existentes e trata corridas concorrentes.
- Editar um modelo pede o mês **"aplicar a partir de"** (padrão: mês atual). Só mudam ocorrências **pendentes**, **não ajustadas individualmente** e a partir desse mês. Meses pagos e anteriores ficam intactos.
- Editar um mês individualmente o marca como **ajustado**; edições futuras do modelo não o sobrescrevem.
- Remover um mês de uma recorrência é uma exclusão lógica (soft delete) que reserva a vaga, para que o mês não seja gerado de novo.
- **Encerrar** define o último mês: meses pendentes posteriores são removidos e os pagos permanecem. Não é possível encerrar antes de um mês já pago (reabra-o primeiro) nem excluir um modelo com meses pagos.
- **Extras temporárias**: valor mensal por N meses; o mês final é calculado e exibido, e nada é gerado depois dele.
- Dia de vencimento 29–31 cai no último dia de meses mais curtos.

### Despesas avulsas e parcelas

- O **mês de referência é sempre o mês do vencimento**: vencendo em 02/10/2026, a despesa entra em outubro/2026. Editar o vencimento de uma despesa avulsa a move para o mês da nova data; o mês de uma recorrência é fixo, então o vencimento editado precisa ficar dentro dele.
- Uma despesa avulsa pode ser cadastrada em **N parcelas** (até 120): informa-se o valor de cada parcela e o formulário mostra o valor total (parcela × N).
- Cada parcela é uma ocorrência própria (`Parcela 2/3`), com o mesmo valor, vencimento no mesmo dia dos meses seguintes (29–31 cai no último dia de meses mais curtos) e pagamento, edição e exclusão independentes.

### Confirmação de pagamento

- Uma despesa da casa é confirmada pelo **outro membro**: se Letícia cadastrou, o botão é "Pago pelo Bruno" e só Bruno pode usá-lo, e vice-versa. O servidor impõe a regra (política `confirmPayment`); o botão apenas a reflete.
- Ocorrências geradas por recorrência herdam como criador quem cadastrou o modelo; parcelas, quem cadastrou a despesa.
- A confirmação grava situação, data/hora e quem confirmou, além de um evento no histórico de pagamentos e na auditoria.
- Uma despesa paga não pode ser editada nem excluída. Ela só pode ser **reaberta** por quem confirmou o pagamento, com motivo obrigatório; o histórico anterior é mantido e a despesa volta a seguir os salários atuais.
- Despesas pessoais são marcadas como pagas ou reabertas pelo próprio dono.
- Uma despesa pendente só fica **vencida** depois que termina o mês do vencimento: vencendo em 10/10, passa a constar como vencida a partir de 01/11.

### Finanças pessoais

- Lançamentos pessoais (receitas, despesas e investimentos) e despesas pessoais são visíveis e editáveis **somente pelo dono**.
- Modelo de cálculo (regime de competência, pelo mês de referência, pago ou não):
    - **Despesas** = despesas lançadas + contas pessoais + **sua parte nas despesas da casa** (calculada automaticamente).
    - **Investimentos** são uma saída separada e nunca contam como despesa.
    - **Fluxo líquido** = receitas − despesas − investimentos.
    - **Saldo disponível** = fluxo líquido acumulado de todos os meses até o fim do período.
- Como a parte da casa entra automaticamente, a contribuição para a casa **não deve** ser lançada manualmente como despesa pessoal (a interface avisa isso).

### Segurança e integridade

- Autenticação do starter kit (Fortify) com limite de tentativas de login, 2FA e passkeys; proteção CSRF do framework.
- Toda rota financeira exige login, e-mail verificado e participação em uma residência.
- Políticas verificam residência e dono em toda leitura e escrita. Registros de outra residência ou de outro dono respondem **404**, e a autorização acontece antes da validação.
- IDs enviados pelo navegador (membro) só são aceitos se pertencerem à residência do usuário.
- Escritas financeiras usam transações, bloqueio de linha (`lockForUpdate`), chaves estrangeiras e limite de 60 requisições/minuto por usuário.
- Auditoria (`audit_logs`) de salários, pagamentos, reaberturas, criação, edição e exclusão de despesas e recorrências.
- Membros de uma residência não podem excluir a própria conta, para preservar o histórico.

## Premissas e limitações

- Cada usuário pertence a uma única residência. O modelo suporta mais de dois membros: a divisão é proporcional entre todos e qualquer membro diferente do criador pode confirmar o pagamento.
- Não há cadastro de cartões de crédito: compras parceladas são despesas avulsas com número de parcelas. Parcelas de cartão cadastradas antes dessa mudança foram convertidas em despesas avulsas parceladas, no mês do respectivo vencimento.
- Despesas e lançamentos não têm categoria: apenas título e observações.
- Não há importação automática das planilhas existentes.
