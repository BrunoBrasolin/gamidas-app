# Maggie Castle

Aplicação de Maggie Castle e pessoais para Bruno e Letícia, substituindo as planilhas de Excel. Interface em português (pt-BR), valores em Real (`R$ 1.234,56`) e fuso horário `America/Sao_Paulo`.

Construída sobre o **Laravel React Starter Kit** (Laravel 13, Inertia v3, React 19, TypeScript, Tailwind CSS 4, shadcn/ui, Fortify, Wayfinder).

## Funcionalidades

- **Painel** do mês: total da casa, parte de cada membro, renda combinada e proporções, pagas × pendentes, vencidas, próximos vencimentos, composição (fixas, extras temporárias, avulsas), resumo pessoal e próximos eventos da agenda.
- **Agenda** (`/dashboard/calendar`): eventos da casa em visão de calendário mensal ou lista, com título, data, horário opcional, descrição, cor e ícone; eventos recorrentes (diários, semanais, mensais, anuais ou personalizados); criação clicando no dia e impressão do mês em paisagem.
- **Despesas da casa**: tabela mensal com total, parte de cada membro, vencimento, criador, confirmação de pagamento e situação; filtros por mês, criador, situação e origem; aba de despesas pessoais.
- **Despesas recorrentes**: fixas (sem prazo ou até um mês final) e extras temporárias (valor mensal por N meses), com geração mensal idempotente.
- **Finanças pessoais** (privadas): receitas, despesas e investimentos; resumo mensal e anual com fluxo líquido e saldo disponível.
- **Relatórios** anuais da casa e pessoais.
- **Configurações da casa**: salários (com histórico) e membros.
- **Tempo** (`/time`, privado de cada usuário): categorias (um trabalho ou cliente por categoria), planilha de horas por dia × categoria (semana e mês) com tempo em h:mm:ss e nota em Markdown em cada célula, e relatórios de tempo registrado.
- **Base de Conhecimento** (`/knowledge`, privado de cada usuário): matérias em forma de livros (capa, cor, fixar, arquivar, ordem manual), categorias opcionais com subcategorias, páginas escritas num editor rico (atalhos de Markdown e barra de formatação, tabelas, imagens entre parágrafos e arquivos anexados — PDF, PowerPoint, Word, Excel), anexos da própria matéria (slides de aula, apostilas) e uma lista de todos os arquivos com a matéria de cada um (`/knowledge/files`), leitor embutido de PDF, apresentações e documentos do Word que retoma na página onde você parou, salvamento automático, modo de leitura com sumário, tamanho de letra e largura ajustáveis, marcadores, status de estudo e busca no texto das páginas.
- **Horas complementares** (`/complementary-hours`, privado de cada usuário): meta de horas exigida pelo curso, atividades com título, data, horas, certificado (PDF ou imagem) e relatório opcional em Markdown, exportável em PDF; progresso, horas que faltam e quais atividades ainda não têm relatório.
- **Shows** (`/shows`, compartilhado pela casa): shows que vocês foram, com banda, nome da tour, local, data, setlist, shows de abertura (cada um com seu setlist) link do álbum do Google Fotos e link da playlist do Spotify; lista por ano com busca e totais de shows, bandas, locais e músicas.
- **Jogo da Atenção** (`/attention`, compartilhado pela casa; jogo público em `/jogo/{código}`): quiz prático para o fim de uma apresentação sobre atenção. Os colegas entram pelo celular (link ou QR Code, só com apelido) e jogam 5 desafios — seletiva, sustentada, dividida, alternada e voluntária —, dizendo em cada um qual tipo de atenção usaram. Telão com QR Code e ranking ao vivo; relatório com acertos, erros, omissões, tempos, reação, reconhecimento de cada conceito, confusões entre conceitos, ranking e exportação em planilha.
- **Assistidos** (`/watched`, compartilhado pela casa): filmes, episódios e vídeos terminados no Jellyfin por qualquer pessoa da casa, registrados automaticamente pelo plugin Webhook, com quem assistiu; "Continuar assistindo" (onde cada pessoa parou e o que está passando agora), séries em andamento, página de cada item com pôster, sinopse, gêneros e elenco do TMDB, e lista por ano e mês com busca e totais de filmes, episódios, séries e tempo assistido.

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
APP_NAME="Maggie Castle"
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

Anexos da Base de Conhecimento removidos do texto de uma página ficam guardados por `KNOWLEDGE_PRUNE_AFTER_HOURS` horas (padrão 24, para o "desfazer" do editor) e depois são apagados por `knowledge:prune-attachments`, agendado diariamente às 03:20. O mesmo comando apaga arquivos da pasta `knowledge/` sem registro no banco.

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
5. Em hPanel › Avançado › Configuração do PHP, deixe `upload_max_filesize` e `post_max_size` pelo menos do tamanho do maior anexo da Base de Conhecimento (`KNOWLEDGE_MAX_FILE_KB`, padrão 30 MB).
6. Em hPanel › Avançado › Cron Jobs, agende a cada 20 minutos (`*/20 * * * *`):

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
- **Valor variável** (ex.: conta de luz): o modelo não tem valor; cada mês é gerado com R$ 0,00 e aparece em Despesas como **"Valor a definir"** (filtro próprio e aviso no painel) até alguém informar o valor do mês — o que o marca como ajustado. Um mês sem valor não pode ser confirmado como pago. Editar o modelo para variável (ou de volta para valor fixo) segue a regra do "aplicar a partir de".

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
- **Pagar tudo** (no total do mês em Despesas) confirma de uma vez todas as despesas pendentes do mês, respeitando os filtros da tela, que o usuário pode confirmar: as da casa cadastradas por ele continuam aguardando o outro membro, e meses sem valor ficam de fora. Cada despesa ganha seu próprio evento no histórico e na auditoria, e a operação é tudo ou nada.
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

### Tempo

- Categorias e lançamentos pertencem a **um usuário** e são invisíveis para os demais, inclusive para o outro membro da casa. Políticas respondem **404** para categorias alheias, e IDs de categoria enviados pelo navegador só são aceitos se forem do próprio usuário.
- **Categorias** (`time_categories`): um nome por trabalho ou cliente (ex.: MegaZap, Find Motors), único por usuário. Renomear não altera o histórico (os lançamentos apontam pelo id). Arquivar esconde a coluna da planilha sem apagar o histórico; categorias arquivadas só aceitam edição de células já existentes. Só é possível **excluir** uma categoria sem nenhum lançamento. Cada categoria pode ter uma **meta diária** opcional (`daily_goal_seconds`, 1 a 86.400 s): na planilha, a célula do dia fica **verde** se o tempo atinge a meta e **vermelha** se fica abaixo; dias futuros não são coloridos.
- **Células** (`time_logs`): uma por categoria e dia (índice único), com duração em **segundos** (0 a 86.400) e uma nota em Markdown opcional (até 200.000 caracteres), editáveis quando quiser. Digita-se `7:42:14`, `1:30` (horas e minutos), `1h30`, `45m` ou `1,5h`. Uma célula sem tempo e sem nota é removida; salvar só o tempo mantém a nota. A data é local (`Y-m-d`, sem conversão de fuso), de 2000 a 2100.
- **Cronômetros** (`time_timers`): cada categoria ativa tem no máximo um cronômetro (índice único por categoria), com iniciar, pausar, zerar e "lançar na planilha de hoje" (soma o tempo decorrido à célula de hoje, preservando a nota, e zera). O relógio é do servidor (`started_at` + segundos acumulados), então o tempo segue contando ao recarregar a página ou em outro aparelho. Iniciar um cronômetro já ativo não faz nada; categorias arquivadas não iniciam cronômetro.
- A planilha mostra semana (segunda a domingo) ou mês, uma coluna por categoria ativa mais as arquivadas com lançamentos no período, totais por dia e por categoria. Os relatórios somam as mesmas células (total, médias, comparação com o período anterior, tempo por dia e por categoria, mapa de calor).
- **Meta nos relatórios**: a meta é avaliada só em **dias úteis** (segunda a sexta, exceto feriados nacionais: fixos, Sexta-feira Santa e Consciência Negra a partir de 2024; Carnaval e Corpus Christi são ponto facultativo e contam como úteis). Um dia útil atinge a meta quando **cada** categoria com meta chega à sua; a meta de uma categoria vale do dia em que foi criada (ou de um dia anterior com tempo lançado nela) até a véspera do arquivamento, usando a meta atual. Hoje fica "em andamento" até atingir, sem contar como falha; dias futuros não têm veredito. O relatório mostra dias atingidos × abaixo da meta (taxa, quanto faltou, sequência atual e melhor sequência), um calendário colorido e as listas de dias abaixo da meta e de **fins de semana e feriados trabalhados**.
- A renderização do Markdown (`react-markdown` + GFM) descarta HTML bruto, neutraliza links `javascript:` e não carrega imagens externas.

### Shows

- Shows (`shows`) e aberturas (`show_opening_acts`) pertencem à **residência**: todos os membros veem, editam e excluem qualquer show; shows de outra residência respondem **404**. Fica registrado quem cadastrou (`created_by_user_id`, vazio nos importados).
- Obrigatórios: banda, local e data (`Y-m-d`, de 2000 a 2100). Nome da tour, setlist, aberturas e álbum são opcionais.
- O **setlist** é digitado com uma música por linha, na ordem tocada, e guardado como lista JSON; linhas em branco e numeração no início (`1.`, `2)`, `3 -`) são descartadas. Até 200 músicas por setlist e 10 aberturas por show, na ordem em que tocaram. Editar um show substitui todas as aberturas.
- Os links do álbum e da playlist precisam ser `https` (ex.: o link de compartilhamento do Google Fotos / do Spotify) e abrem em nova aba.
- Nos totais, bandas contam atrações principais e aberturas uma vez cada, ignorando maiúsculas e acentos; locais idem.
- **Importação do site antigo**: `php artisan shows:import [residência] [--from=url-ou-arquivo] [--dry-run]` lê a página do GitHub Pages (padrão `https://brunobrasolin.github.io/shows/`) e importa para a residência informada (sem id: a única que existe, ou escolhida numa lista): cada `h1` "Banda | Tour" é um show, com `Data: dd/mm/aaaa`, `Local:` e o setlist; cada título menor seguinte ("Banda | Show Abertura") é uma abertura. Shows que a residência já tem (mesma banda e data) são pulados, então dá para rodar de novo. Se o servidor não alcançar o site, passe um arquivo HTML salvo em `--from`.

### Horas complementares

- A meta (`complementary_hours_goals`, uma por usuário) e as atividades (`complementary_activities`) pertencem a **um usuário**: cada pessoa vê só as próprias; atividades, certificados e PDFs alheios respondem **404**.
- Durações são guardadas em **minutos inteiros** (`App\Support\Hours`) e digitadas como `4`, `1,5`, `1.5`, `1:30` ou `1h30`; mínimo 1 minuto, máximo 9999 h. Feitas = soma das atividades; faltam = meta − feitas (nunca negativo). Sem meta, as horas feitas aparecem mesmo assim.
- Obrigatórios: título (até 160 caracteres), horas e certificado. A data é opcional; a lista vem da mais recente para a mais antiga, sem data por último.
- **Certificado**: PDF ou imagem (JPEG, PNG, WebP, GIF), até `COMPLEMENTARY_HOURS_MAX_CERTIFICATE_KB` (padrão 10 MB), com o tipo detectado pelo conteúdo como na Base de Conhecimento. Fica no disco privado (`complementary-hours/<usuário>/`, nome aleatório) e só é servido pela rota autenticada, com `nosniff`. Na edição o certificado é opcional e, se enviado, substitui o anterior (o arquivo antigo é apagado). Excluir a atividade apaga o arquivo.
- **Relatório**: Markdown opcional (até 100 mil caracteres); em branco conta como "sem relatório", e a lista mostra quais atividades estão assim. O PDF (`/complementary-hours/{id}/report.pdf`, só para atividades com relatório) é gerado no servidor com Dompdf: HTML digitado no Markdown é descartado, links inseguros são removidos e nada externo é carregado (imagens remotas não aparecem).

### Jogo da Atenção

- Salas (`attention_games`) pertencem à **casa**: todo membro vê, encerra, reabre, exporta e exclui; salas de outra casa respondem **404**. Cada sala tem um código de 5 caracteres sem letras ambíguas (sem I, O, 0, 1); o link aceita o código em minúsculas.
- O jogo (`/jogo/{código}`) é **público**, sem conta. O jogador entra com um apelido (2–24 caracteres, único na sala ignorando maiúsculas) e recebe um token aleatório de 40 caracteres guardado no celular (só o SHA-256 fica no banco), que permite continuar depois de recarregar a página. Nada além do apelido é registrado. Uma sala aceita até 300 jogadores; os limites de requisição são por IP e altos, porque a turma toda costuma estar no mesmo Wi-Fi.
- Os 5 desafios são jogados nesta ordem e cada um conta **uma única vez** por jogador; o jogador termina ao responder o último. Sala encerrada não aceita novos jogadores nem respostas.
- Pontos por desafio (máximo 1000, total 5000), calculados no servidor a partir das medidas enviadas pelo celular: **desempenho** = precisão × 5 (até 500); **rapidez** = até 300 só se o desafio foi feito certo, caindo linearmente até zero no limite do desafio (20 s na seletiva, 10 s para responder a sustentada, 15 s a dividida, 30 s a voluntária; na alternada vale o tempo médio de reação aos alvos, limite 800 ms); **conceito** = 200 por acertar qual tipo de atenção o desafio usou.
- “Feito certo”: seletiva = achou os 5 Q sem tocar em O; sustentada = contagem exata; dividida = as duas contagens exatas; alternada = ao menos 90% de respostas certas (toques em alvos e não-toques nos demais); voluntária = seguiu as 6 instruções. Na alternada, tocar no círculo depois da troca de regra conta como erro e como **perseveração**.
- Ranking: maior pontuação; empate → quem terminou, e quem terminou mais rápido. As medidas vêm do navegador, então o jogo é uma demonstração didática, não um teste psicológico validado nem à prova de trapaça.

### Assistidos (Jellyfin)

- Itens assistidos (`watched_items`) e a conexão com o Jellyfin (`jellyfin_connections`, uma por casa) pertencem à **residência**: todos os membros veem o histórico, removem itens e geram ou revogam o token; itens de outra residência respondem **404**. Cada item guarda quem assistiu (`viewer`, o usuário do Jellyfin em `NotificationUsername`).
- O Jellyfin roda na rede de casa e **envia** os eventos para o Gamidas (o servidor nunca acessa o Jellyfin). Em `/watched`, "Gerar token" cria um token de 48 caracteres mostrado **uma única vez**; só o SHA-256 dele fica no banco. Gerar outro invalida o anterior; "Desconectar" apaga a conexão, mas não o histórico.
- No Jellyfin: instalar o plugin **Webhook**, criar um **Generic Destination** com a URL `https://<domínio>/api/webhooks/jellyfin`, marcar só **Playback Start** e **Playback Stop**, deixar o **User Filter** vazio (um único destino conta todos os usuários do Jellyfin), tipos Movies, Episodes e Videos, marcar **Send All Properties** e adicionar o header `X-Gamidas-Token: <token>` (também aceita `Authorization: Bearer <token>`).
- O endpoint (`routes/api.php`) não usa sessão nem CSRF, é limitado a 120 requisições por minuto por IP e responde: **401** para token ausente ou inválido; **422** quando o corpo não é um evento do Jellyfin (sem `NotificationType`, por exemplo com "Send All Properties" desligado) ou falta `ItemId`/`Name`; **200** com `recorded`, `duplicate`, `tracked` (andamento atualizado) ou `ignored`. Qualquer evento autenticado atualiza "último evento recebido".
- Só contam filmes, episódios e vídeos (`Video`, `MusicVideo`); música, audiolivros e outros eventos são ignorados. Valores podem vir como JSON ou texto (template próprio).
- **Histórico**: `PlaybackStop` com `PlayedToCompletion` (o Jellyfin marca ao passar do ponto de "assistido") registra o item e apaga o andamento dele daquela pessoa.
- **Continuar assistindo** (`watch_progress`, uma linha por residência + pessoa + item): `PlaybackStart` marca o item como "assistindo agora" (com a posição de `PlaybackPositionTicks`); `PlaybackStop` antes do fim guarda onde parou e quando. Parar antes de **1 minuto** não conta: some da lista, a menos que uma sessão anterior tenha ido além (aí só deixa de estar "assistindo agora"). Se o evento de parada se perder, o item deixa de aparecer como "assistindo agora" depois da duração dele + 30 min (4 h quando a duração é desconhecida). A lista mostra os 12 mais recentes; qualquer membro pode tirar um item dela, e ele volta se alguém retomar.
- **Séries em andamento**: o último episódio assistido de cada série nos últimos 6 meses (até 12 séries), comparando nomes sem maiúsculas e acentos.
- O horário vem de `UtcTimestamp` convertido para `America/Sao_Paulo` (ausente, ilegível ou no futuro: agora). Um item conta **uma vez por dia local para a casa** (índice único residência + item + dia), então eventos repetidos — ou duas pessoas assistindo o mesmo item no mesmo dia — não duplicam (fica quem terminou primeiro); assistir de novo em outro dia registra outra vez.
- A duração vem de `RunTimeTicks` (ou `RunTime` "hh:mm:ss"); o tempo assistido nos totais soma a duração dos itens, não o tempo real de tela. Séries contam uma vez cada, ignorando maiúsculas e acentos.
- **Detalhes do TMDB** (`/watched/{id}` e `/watched/progress/{id}`): com `TMDB_TOKEN` (o "API Read Access Token" de uma conta do themoviedb.org) no `.env` do servidor, a página mostra pôster, fundo, sinopse, slogan, gêneros, nota, direção (filmes) ou criação e temporadas (séries), elenco principal (12) e, em episódios, o episódio (imagem, sinopse, data). Os ids externos do item vêm do webhook (`Provider_tmdb`, `Provider_imdb`, `Provider_tvdb`… guardados em `provider_ids`): filmes pelo id do TMDB ou do IMDb; episódios pelo IMDb/TVDB do episódio (o TMDB devolve a série, temporada e número); sem ids, busca pelo título (filmes) ou nome da série e ano, tentando de novo sem o ano. Textos em `TMDB_LANGUAGE` (padrão `pt-BR`). A busca do item fica em cache por 30 dias e os detalhes por 7; falhas do TMDB não são guardadas, a página mostra só o que veio do Jellyfin e tenta de novo na próxima visita. Sem token, nada é consultado. Imagens são carregadas direto de `image.tmdb.org`; a página traz o aviso de atribuição exigido pelo TMDB.
- Eventos enviados com o site fora do ar (por exemplo durante o deploy) se perdem: o plugin não reenvia. Um item pode ser removido do histórico manualmente.

### Base de Conhecimento

- Matérias (`knowledge_subjects`), categorias (`knowledge_categories`), páginas (`knowledge_pages`) e anexos (`knowledge_attachments`) pertencem a **um usuário**: cada pessoa monta a própria base e não vê a do outro membro. Políticas respondem **404** para registros alheios, e IDs de matéria ou categoria enviados pelo navegador só são aceitos se forem do próprio usuário.
- Categorias são opcionais e cada matéria tem as suas. Uma categoria pode ficar dentro de outra da mesma matéria (`parent_id`), até 5 níveis; nunca dentro dela mesma ou de uma subcategoria dela. Cada página fica em no máximo uma categoria. Excluir uma categoria **não** apaga nada dentro dela: as páginas e subcategorias vão para o fim da categoria de cima (no primeiro nível, as páginas vão para "Sem categoria" e as subcategorias sobem para o primeiro nível). No sumário e na navegação entre páginas, cada categoria vem com as páginas dela e depois as subcategorias, em ordem; por último, as páginas sem categoria; páginas arquivadas ficam de fora.
- Até out/2026 a seção se chamava "Estudos" (tabelas `study_*`, capítulos em vez de categorias, rota `/study`). A migração `2026_10_25_000001_move_study_to_knowledge_base` copia os dados para as tabelas `knowledge_*` mantendo os ids, transforma capítulos em categorias de primeiro nível, renomeia os blocos de anexo dentro das páginas e move os arquivos de `study/` para `knowledge/`. Links antigos em `/study/...` redirecionam.
- O conteúdo da página é o documento do editor (Tiptap/ProseMirror, JSON). No servidor ele é reconstruído com uma lista fechada de blocos, marcas e atributos: links só `http(s)` e `mailto`, blocos de anexo só com anexos da própria página. O modo de leitura monta o documento como componentes React, sem injetar HTML. O texto puro (`content_text`) alimenta a busca.
- **Salvamento automático**: cada salvamento leva a revisão em que o editor se baseou. Se a página foi salva em outra aba ou aparelho depois disso, o servidor responde **409** e nada é sobrescrito até a pessoa escolher entre a versão salva e a dela. Falhas de rede tentam de novo sem perder o texto; ao sair do editor ou mandar o app para segundo plano, salva na hora.
- **Anexos**: imagens JPEG, PNG, WebP ou GIF (até `KNOWLEDGE_MAX_IMAGE_KB`, padrão 10 MB), PDFs e arquivos do Office — PowerPoint `.pptx`, Word `.docx`, Excel `.xlsx` (até `KNOWLEDGE_MAX_FILE_KB`, padrão 30 MB; o nome antigo `KNOWLEDGE_MAX_PDF_KB` ainda vale). O tipo é detectado pelo conteúdo do arquivo, nunca pelo nome ou pelo tipo enviado pelo navegador (SVG não é aceito; arquivos do Office são reconhecidos pelas partes internas do ZIP, como `ppt/presentation.xml`). Formatos antigos (`.ppt`, `.doc`, `.xls`) não são aceitos. Os arquivos ficam no disco privado (`storage/app/private/knowledge/<usuário>/`) com nome aleatório e só são servidos pela rota autenticada `/knowledge/files/{id}`, com `X-Content-Type-Options: nosniff`.
- Um anexo novo fica "solto" até o texto que o posiciona ser salvo; anexos tirados do texto também ficam soltos e são apagados depois do prazo (veja [Tarefas agendadas](#tarefas-agendadas)). Excluir uma página ou matéria apaga na hora os arquivos dela; duplicar uma página copia os arquivos; mover uma página para outra matéria leva os anexos junto.
- **Leitor embutido**: PDFs (pdf.js), apresentações (pptx-preview) e documentos do Word (docx-preview) abrem dentro do app, no computador e no celular, renderizados no navegador — o arquivo não sai do servidor para nenhum serviço externo. As bibliotecas só são baixadas quando um arquivo é aberto. Planilhas são só baixadas. O leitor guarda a página (ou slide) em que você está em `reading_page` / `page_count` do anexo e abre ali da próxima vez; links dentro do arquivo só funcionam se forem `http(s)` ou `mailto`.
- **Anexos da matéria** ("Adicionar anexo", ao lado de "Nova página" e "Nova categoria"): arquivos que pertencem à matéria, sem estar em nenhuma página (`is_subject_file`). Aceitam os mesmos tipos e limites, podem ser renomeados (a extensão é mantida) e excluídos, e nunca são apagados pela limpeza de anexos soltos. Arquivos dentro de páginas só saem pelo editor da página (excluí-los à parte responde 403).
- **Arquivos** (menu, abaixo de Matérias): todos os anexos das matérias e os arquivos colocados em páginas (sem as imagens do texto nem as capas), do mais novo ao mais antigo, com a matéria — e a página, quando houver — de cada um, filtro por matéria, tipo e nome.
- Status de estudo por página (não lida, estudando, revisada) é manual e opcional; a matéria mostra o progresso. Marcar, mudar status ou abrir uma página não altera a data de edição.

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
- O módulo de tempo fica na mesma área autenticada (exige participação em uma residência), mas os dados são individuais. O fuso de referência é o da aplicação (`America/Sao_Paulo`).
