# QRaft REST API

Manage dynamic QR codes programmatically. Create a token from **Dashboard →
API**, then send it as a bearer header on every request.

```
Authorization: Bearer YOUR_TOKEN
```

Base path: `/api/`. All responses are JSON.

## Endpoints

### List codes
```
GET /api/codes.php
```
Returns `{ "codes": [ { id, code, title, target_url, short_url, is_active, scan_count, created_at }, … ] }`.

### Get one code
```
GET /api/codes.php?id=123
```

### Create a code
```
POST /api/codes.php
Content-Type: application/json

{ "title": "Summer menu", "target_url": "https://example.com/menu",
  "slug": "summer-menu", "fg_color": "#000000", "bg_color": "#ffffff" }
```
`slug`, `fg_color`, `bg_color` are optional. Returns `201` with the new code.

### Update a code
```
PATCH /api/codes.php?id=123
Content-Type: application/json

{ "target_url": "https://example.com/new", "title": "New title", "is_active": false }
```
Any subset of `target_url`, `title`, `is_active`.

### Delete a code
```
DELETE /api/codes.php?id=123
```
Returns `{ "deleted": true }`.

## Errors

| Status | Meaning |
|--------|---------|
| 401 | Missing or invalid token |
| 403 | Account suspended |
| 404 | Code not found (or not yours) |
| 409 | Slug already taken |
| 422 | Validation error |

## Example

```bash
# Create
curl -X POST https://your-site/api/codes.php \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"Promo","target_url":"https://example.com/promo"}'

# Re-point it later (the printed QR never changes)
curl -X PATCH "https://your-site/api/codes.php?id=42" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"target_url":"https://example.com/promo-2"}'
```
