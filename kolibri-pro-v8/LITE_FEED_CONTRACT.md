# Kolibri Lite Feed Contract

Use this contract for an external scraper service that publishes listings as JSON.

## Endpoint

- Method: `GET`
- URL example: `https://your-service.example.com/v1/vici-pararius-feed`
- Auth (optional): `Authorization: Bearer <token>`
- Content-Type: `application/json`

## Response format

```json
{
  "cards": [
    {
      "title": "Aweg 11 Ak7, Groningen",
      "url": "https://www.pararius.nl/kamer-te-huur/groningen/9b9f513f/aweg",
      "price": "1200",
      "image": "https://cdn.example.com/images/aweg-11-ak7.jpg"
    }
  ]
}
```

Alternative top-level arrays are also supported:

```json
[
  {
    "title": "Aweg 11 Ak7, Groningen",
    "url": "https://www.pararius.nl/kamer-te-huur/groningen/9b9f513f/aweg",
    "price": "1200",
    "image": "https://cdn.example.com/images/aweg-11-ak7.jpg"
  }
]
```

## Required fields per card

- `title`: string
- `url`: absolute HTTP/HTTPS URL to external detail page

## Optional fields per card

- `price`: string or number (plugin formats as `€ <bedrag> p/m`)
- `image`: absolute image URL

## Notes

- Keep URLs stable to avoid card flickering.
- Sort cards in desired display order.
- Keep image URLs absolute and publicly reachable.
- Return max 30 cards for best performance.
