# AGENTS.md

Household + personal finance app (Maggie Castle). Laravel 13 + Inertia v3 + React 19 + TypeScript + Tailwind 4 + shadcn/ui, Fortify auth, Wayfinder routes. UI is pt-BR, currency BRL, timezone `America/Sao_Paulo`. `README.md` is the source of truth for business rules — read its "Regras de negócio" section before touching money, splits, recurrences, cards or payments.

## Security — this repository is PUBLIC

- **NEVER commit secrets**: no tokens, API keys, passwords, `APP_KEY`, DB credentials, SSH hosts/users, Hostinger account ids (`u123…`), real e-mails or real financial data. Not in code, tests, fixtures, seeders, docs, commit messages or PR text.
- `.env`, `.env.production`, `.env.backup`, `auth.json` are gitignored — keep it that way. Only `.env.example` is committed, with empty/placeholder values.
- Deploy credentials live only in GitHub Actions secrets (`SSH_HOST`, `SSH_PORT`, `SSH_USER`, `SSH_PASSWORD`) and variable `DEPLOY_PATH`. Reference them as `${{ secrets.X }}`; never inline values.
- Before every commit run `git diff --cached` and check for anything credential-shaped. If a secret was ever committed, tell the user to rotate it — rewriting history is not enough on a public repo.
- `DatabaseSeeder` is intentionally empty; don't seed users or passwords. Accounts are created on the server via `household:create` / `household:add-member` (hidden password prompt).

## Hosting (Hostinger shared hosting: Apache + MySQL)

- Production is MySQL 8 behind Apache; app lives inside `public_html` and a hand-kept root `.htaccess` rewrites to `public/`. Don't add anything relying on root-level files being served.
- PHP binary on the server: `/opt/alt/php83/usr/bin/php` (PHP 8.3). No Node, Composer or GitHub access on the server — everything is built in CI (`.github/workflows/deploy.yml`) and shipped as a tarball, then `rsync`ed.
- Deploy runs on every push to `main` (after `tests.yml`): `artisan down`, rsync, `migrate --force`, `optimize`. So pushing to `main` = deploying to production. Migrations must be safe on MySQL and on existing data.
- Server-only files preserved by deploy: `.env`, `storage/`, `public/storage`, `database/*.sqlite`, root `.htaccess` / `.user.ini`.
- Shared hosting constraints: no long-running workers/daemons, no Redis; queue/cache/session use the database/file drivers. Scheduler runs via hPanel cron (`schedule:run` every minute).
- Tests run on SQLite in memory; keep queries portable between SQLite and MySQL (no MySQL-only SQL without a fallback).

## Commands

```bash
composer setup          # first-time install
composer dev            # server, queue, logs, Vite
composer ci:check       # what CI runs: npm check, tsc, Pint, Larastan, PHPUnit
composer lint           # Pint fix
npm run check:fix       # frontend lint/format
php artisan test        # PHPUnit (page tests need `npm run build` first)
```

## Conventions

- Money is always integer cents (`BIGINT UNSIGNED`); use `App\Support\Money`. Never use floats for amounts. Months via `App\Support\Month`.
- Business logic goes in `app/Services/*`; controllers stay thin, validation in Form Requests, authorization in Policies. Household scoping via `InteractsWithHousehold` / `EnsureUserBelongsToHousehold` — every query must be scoped to the user's household; personal finances are private to their owner.
- Payment confirmation must be done by the _other_ member (`confirmPayment` policy) — enforce server-side.
- Generated frontend dirs `resources/js/{actions,routes,wayfinder}` are gitignored (Wayfinder output); don't edit or commit them.
- User-facing text in pt-BR. Add/adjust tests in `tests/Feature` or `tests/Unit` with every behavior change.
