# Greenroom

Open-source-core webinar studio. Presenters get a private studio with a backstage chat; guests get a room with a shared chat that presenters moderate.

## What the core does

- **Rooms** – create a webinar and get two links: a private studio link (carries the presenter key) and a guest link.
- **Backstage chat** – presenter-only channel for the team to prepare and coordinate while live.
- **Room chat** – shared by presenters and guests. Presenters can hide, restore, and pin messages.
- **Slides** – presenters upload PDF or PPTX decks and choose which one is live for guests.
- **Screen share** – presenters start a browser screen capture from the studio.

## Known limits

- Screen share is a local preview in the presenter's browser; it is not yet relayed to guests (no media server in the core).
- PPTX decks are offered to guests as a download; PDFs render inline.
- Chat and the live slide refresh by polling.
- Anyone holding the studio link is a presenter. There are no accounts yet.

## Stack

Laravel, Inertia, Vue 3, Postgres, object storage for decks.

## Run

```
docker compose up --build
docker compose --profile test run --rm test
```

## License

MIT, see `LICENSE`.
