# Finanças da Casa

Aplicação de finanças da casa e pessoais para Bruno e Letícia, substituindo as planilhas de Excel. Interface em português (pt-BR), valores em Real (`R$ 1.234,56`) e fuso horário `America/Sao_Paulo`.

Construída sobre o **Laravel React Starter Kit** (Laravel 13, Inertia v3, React 19, TypeScript, Tailwind CSS 4, shadcn/ui, Fortify, Wayfinder).

## Funcionalidades

- **Painel** do mês: total da casa, parte de cada membro, renda combinada e proporções, pagas × pendentes, vencidas, próximos vencimentos, composição (fixas, extras temporárias, avulsas), resumo pessoal e próximos eventos da agenda.
- **Agenda** (`/dashboard/calendar`): eventos da casa em visão de calendário mensal ou lista, com título, data, horário opcional, descrição, cor e ícone; eventos recorrentes (diários, semanais, mensais, anuais ou personalizados); criação clicando no dia e impressão do mês em paisagem.
- **Despesas da casa**: tabela mensal com total, parte de cada membro, vencimento, criador, confirmação de pagamento e situação; filtros por mês, criador, situação e origem; aba de despesas pessoais.
- **Despesas recorrentes**: fixas (sem prazo ou até um mês final) e extras temporárias (valor mensal por N meses), com geração mensal idempotente.
- **Finanças pessoais** (privadas): receitas, despesas e investimentos; resumo mensal e anual com fluxo líquido e saldo disponível.
- **Relatórios** anuais da casa e pessoais.
- **Configurações da casa**: salários (com histórico) e membros.
- **Tarefas e tempo** (`/time`, privado de cada usuário): tarefas com metas de tempo e/ou de resultado, cronômetro (iniciar, pausar, retomar, parar), planilha manual de tempo por dia × tarefa (semana e mês), visão detalhada do dia, notas diárias em Markdown com salvamento automático e relatórios de tempo registrado.

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

Despesas fixas sem prazo são geradas até `FINANCE_GENERATION_MONTHS_AHEAD` meses à frente (padrão 2). O comando é agendado diariamente às 00:20:

```bash
php artisan expenses:generate                 # manual, idempotente
php artisan expenses:generate --through=2027-06
```

Configure o agendador no servidor para rodar a cada 20 minutos (economiza conexões no MySQL da hospedagem compartilhada). Os horários das tarefas precisam cair nessa grade (`:00`, `:20`, `:40`):

```cron
*/20 * * * * cd /caminho/do/projeto && php artisan schedule:run >> /dev/null 2>&1
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
5. Em hPanel › Avançado › Cron Jobs, agende a cada 20 minutos (`*/20 * * * *`):

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

### Agenda

- Os eventos pertencem à residência: todos os membros veem, editam e excluem qualquer evento; quem criou e quem editou por último ficam registrados.
- Eventos sem horário valem para o dia todo e aparecem antes dos com horário no mesmo dia.
- O painel lista os eventos de hoje até 30 dias à frente (no máximo 6).
- Cores e ícones são opções fixas (`App\Enums\EventColor` / `EventIcon`), espelhadas em `resources/js/lib/calendar.ts`.
- **Eventos recorrentes**: a série guarda só a regra (frequência diária, semanal, mensal ou anual, intervalo "a cada N", dias da semana quando semanal, data de início e término opcional). As ocorrências são calculadas ao abrir a agenda ou o painel (`App\Services\CalendarService`), sem gerar registros nem depender do agendador.
    - Semanal sem dias escolhidos repete no dia da semana da data de início; a data de início sempre faz parte da série.
    - Mensal e anual usam o dia da data de início; em meses mais curtos caem no último dia (31 → 30/04, 29/02 → 28/02).
    - Editar ou excluir **somente este evento** grava uma exceção (`calendar_event_exceptions`) pela data original da ocorrência; a ocorrência alterada pode mudar de data e deixa de acompanhar edições da série.
    - Editar **toda a série** mantém as exceções, a menos que mude a data de início, a frequência, o intervalo ou os dias da semana — aí elas são descartadas. Encurtar o término descarta só as exceções depois da nova data. Excluir a série exclui todas as ocorrências.

### Tarefas e tempo

- Tudo no módulo (tarefas, sessões do cronômetro, lançamentos e notas) pertence a **um usuário** e é invisível para os demais, inclusive para o outro membro da casa. Políticas respondem **404** para registros alheios, e IDs de tarefa enviados pelo navegador só são aceitos se forem do próprio usuário.
- **Planilha × cronômetro**: são registros separados. As **sessões do cronômetro** (`timer_sessions`) são medições; os **lançamentos manuais** (`time_entries`) são o registro de tempo trabalhado. Parar o cronômetro **nunca** cria nem altera lançamentos. A única ponte é o botão “Lançar na planilha” de uma sessão, que só pré-preenche um formulário editável que o usuário precisa salvar.
- Totais da planilha, metas de tempo, painel e relatórios usam **somente** os lançamentos manuais. Estatísticas do cronômetro aparecem à parte, rotuladas como “medido (cronômetro)”.
- **Tarefas**: título, descrição, meta (`sem meta`, `tempo`, `resultado` ou `tempo e resultado`), meta de tempo em minutos, resultado esperado e prazo opcionais. A situação deriva de `completed_at` e `archived_at` (arquivada prevalece). Meta de tempo = tempo lançado em toda a história da tarefa ÷ meta; meta de resultado é alcançada ao concluir a tarefa.
- Renomear, concluir ou arquivar não altera o histórico (lançamentos e sessões apontam para a tarefa pelo id). Tarefas arquivadas não recebem novos lançamentos nem cronômetro, mas lançamentos antigos podem ser corrigidos. Arquivar para o cronômetro aberto da tarefa. Só é possível **excluir** uma tarefa sem lançamentos nem sessões; caso contrário é preciso arquivar (as chaves estrangeiras também restringem a exclusão).
- **Cronômetro**: no máximo **um em andamento por usuário**, garantido por índice único (`user_id`, `running_lock`); pode haver várias sessões pausadas. Iniciar outro exige escolher pausar ou parar o atual; iniciar uma tarefa com sessão pausada a retoma. Ações repetidas (duplo clique, duas abas) não mudam nada. O tempo decorrido é `accumulated_seconds` + (agora − `last_resumed_at`) quando em andamento, sempre calculado no servidor; o navegador só soma o tempo passado desde que recebeu o valor. Atualizar a página, trocar de aba ou fechar o navegador não perde o cronômetro. Os instantes são gravados em **UTC** (`App\Casts\UtcDateTime`). Uma sessão que atravessa a meia-noite continua sendo uma única sessão, datada pelo início.
- **Lançamentos**: tarefa, data local (`Y-m-d`, sem conversão de fuso), duração em minutos inteiros (1 a 1440 por lançamento) e descrição opcional. Várias entradas da mesma tarefa no mesmo dia são somadas. Na planilha, digitar numa célula vazia cria um lançamento; numa célula com um lançamento, altera-o (0 o remove); células com vários lançamentos são editadas um a um. Datas passadas e futuras são permitidas (2000–2100). “Copiar do dia anterior” copia o último dia com lançamentos (até 60 dias antes) sem duplicar lançamentos idênticos já existentes.
- A semana vai de **segunda a domingo**; as colunas da planilha são as tarefas ativas mais as que têm tempo no período (assim tarefas concluídas e arquivadas continuam aparecendo no histórico).
- **Notas diárias**: uma por usuário e dia (índice único), Markdown guardado como texto (até 200.000 caracteres). O salvamento automático envia a revisão de origem; se a nota mudou em outra aba, o servidor responde 409 e o usuário escolhe qual versão manter. O rascunho fica no navegador até o servidor confirmar o salvamento. A renderização (`react-markdown` + GFM) descarta HTML bruto, neutraliza links `javascript:` e não carrega imagens externas. Nota em branco é removida.

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
- O módulo de tarefas e tempo fica na mesma área autenticada (exige participação em uma residência), mas os dados são individuais. O fuso de referência é o da aplicação (`America/Sao_Paulo`).
- Excluir uma sessão encerrada do cronômetro é permitido (medições erradas); isso não afeta a planilha.
