# Agent notes — recruit-app

Read `docs/PLAN.md` and `docs/DECISIONS.md` first; the specification lives in
`I:\factory\specs\recruitment\spec\recruitment-tool-spec-EN-v0.2.md`.

- Use PHP 8.4 from `I:\factory\tools\php84` and `I:\factory\tools\composer.phar`.
- Do not add packages that are not listed in `docs/DECISIONS.md` without logging the reason there.
- Business logic goes in `app/Domain`, `app/Actions`, `app/Services`; Filament classes stay thin.
- Every user-facing string goes through `lang/ar` and `lang/en`; no Arabic literals in PHP/Blade.
- Synthetic data only. Never commit, push or deploy without Omar's explicit go-ahead.
- Gates: `composer lint`, `composer stan`, `composer test`.
