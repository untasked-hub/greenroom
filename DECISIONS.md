# Project decisions

## Blueprint and rationale
- Blueprint: `laravel-inertia-vue`
- Rationale: Greenroom is a webinar studio SaaS: presenters prepare in a backstage room, guests join a public room with chat moderated by presenters, presenters upload PDF/PPTX slides and share screen. Needs accounts-free rooms with persistent chat and uploads, so Laravel + Inertia + Vue with Postgres and storage.

## Runtimes and versions
- laravel: 13.0.0
- php: 8.4.0
- vue: 3.5.0
- inertia: 3.0.0
- tailwind: 4.0.0

## Data, storage and deploy
- Data: Postgres for rooms, messages, slide decks, presenter tokens. Uploads (slides) via Untasked storage (UNTASKED_UPLOADS_URL).
- Deploy: hosted-app

## UI kit, icons and tokens
- UI kit: shadcn-vue
- Icon family: lucide
- Tokens: follow the starter design tokens; adapt identity to the product.

## Capabilities and licenses
- Open-source core: MIT-licensed Laravel/Vue starter, no paid dependencies

## Contracts and exceptions
- (none)

## Validation and known limitations
- Validation: docker compose -f compose.yaml config; docker compose run --rm test php -d pcov.enabled=1 vendor/bin/pest --coverage --min=80 --coverage-text
- Limitations: MVP: chat polling not websockets; screen share uses browser getDisplayMedia locally; slides rendered as uploaded files (no server-side conversion).
- Guides in use:
- laravel-core
- laravel-validation
- laravel-auth
- laravel-eloquent
- laravel-migrations
- laravel-jobs
- laravel-events
- laravel-storage
- laravel-http
- laravel-frontend
- laravel-caching
- laravel-testing
- laravel-deployment
- vue-core
- inertia-core
- tailwind-core
- shadcn-vue-core
- lucide-core
- playwright-core
- untasked-app-create
- untasked-app-up
- untasked-repo-release
- untasked-deploy-contract
- untasked-domains
- untasked-storage
- untasked-secrets

## Next delivery
Ship the first useful vertical slice and verify the full user flow.
