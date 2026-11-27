# Project decisions

## Blueprint and rationale
- Blueprint: `laravel-inertia-vue`
- Rationale: Preserve the existing Greenroom Laravel + Vue/Inertia stack; the requested effects are client-side additions and need no architecture or dependency changes.

## Runtimes and versions
- vue: existing project version

## Data, storage and deploy
- Data: Camera effect choice and uploaded background persist in the presenter browser via existing localStorage; no server persistence is needed.
- Deploy: hosted-app

## UI kit, icons and tokens
- UI kit: shadcn-vue
- Icon family: lucide
- Tokens: follow the starter design tokens; adapt identity to the product.

## Capabilities and licenses
- MediaPipe Selfie Segmentation via CDN, lazy-loaded at runtime; graceful raw-camera fallback

## Contracts and exceptions
- (none)

## Validation and known limitations
- Validation: docker compose -f compose.yaml config; docker compose run --rm test php -d pcov.enabled=1 vendor/bin/pest --coverage --min=80 --coverage-text
- Limitations: Effects require browser support for canvas and external CDN access. Without segmentation availability the presenter camera remains raw.
- Guides in use:
- (none)

## Next delivery
Implement client-side virtual backgrounds with a blur mode, four built-in backgrounds and local upload in Studio.
