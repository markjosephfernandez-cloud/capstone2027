# Greyhound API examples

Base URL while using `php spark serve`: `http://localhost:8080/api/v1`.

## Login

```http
POST /auth/login
Content-Type: application/json

{"employee_id":20141346,"password":"your-password"}
```

The response contains a 64-byte random bearer token only once:

```json
{
  "status": 200,
  "data": {
    "access_token": "…",
    "token_type": "Bearer",
    "expires_at": "2026-07-31 22:00:00",
    "user": {"employee_id": 20141346, "role": "admin"}
  }
}
```

## Media

```http
GET /files?search=graduation&type=jpg&page=1&per_page=25
Authorization: Bearer <token>
```

`POST /files` is a multipart request with `file` required and optional
`directory` (a relative path beneath the configured storage root) and
`description` fields. Server paths are never accepted from a client or exposed
in the response.

```http
PATCH /files/123
Authorization: Bearer <token>
Content-Type: application/json

{"description":"Opening ceremony","featured":true,"downloadable":true}
```

```http
POST /files/123/move
Authorization: Bearer <token>
Content-Type: application/json

{"directory":"2026/Graduation"}
```

Soft deletion is explicit and reversible:

```http
POST /files/123/trash
POST /files/123/restore
```

```http
PUT /files/123/tags
Content-Type: application/json

{"tags":["graduation", "2026", "featured"]}
```

## Folders and audit log

```http
POST /folders
Content-Type: application/json

{"name":"Graduation","parent_path":"2026","description":"2026 media"}
```

`GET /folders?path=2026` returns one level of physical folders within the
configured root. `GET /audit-logs` accepts `module`, `action`, `user_id`,
`from`, `to`, `page`, and `per_page`; it requires an admin token.

Errors have a consistent shape:

```json
{"status":422,"error":"validation_error","message":"…"}
```
