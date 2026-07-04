# OpenAPI dev tools

Maintenance tooling for the generated OpenAPI document (`/api/v1/openapi.json`).
All are **CLI-only** (they refuse to run over the web) and need nothing installed
beyond PHP — no Node, Python or Composer.

The OpenAPI document is generated at request time by `../lib/openapi.php` from
the route table (`../lib/routes.php`), the docs catalogue (`../spec.json`) and
the typed schemas (`../lib/openapi_schemas.php`). These tools keep those in step
and prove the result is valid and accurate.

## When you add or change an API module

1. Update the resource + routes + `spec.json` + `spec.json` examples as usual.
2. Create a temporary read key (System → API) with the permissions you need.
3. Bring the typed schemas back in line with the live serializers:
   ```
   php openapi_fix.php <read_key>
   ```
   Re-run until it reports `0 patches`.
4. Confirm every schema matches a real response:
   ```
   php openapi_verify.php <read_key>
   ```
   Aim for `0 failed`. (A few endpoints that need a specific sub-id may `SKIP`.)
5. Confirm the invariants (drift, refs, operationIds, responses, shape):
   ```
   php ../lib/openapi_check.php
   ```
6. Confirm conformance to the official OpenAPI 3.0 meta-schema:
   ```
   curl -s http://localhost/freeitsm-app/api/v1/openapi.json > /tmp/o.json
   php jsonschema4.php /tmp/o.json oas-3.0-schema.json
   ```
7. For the authoritative linter pass (optional, needs Node):
   ```
   npx @stoplight/spectral-cli lint http://localhost/freeitsm-app/api/v1/openapi.json
   npx swagger-cli validate http://localhost/freeitsm-app/api/v1/openapi.json
   ```

## Files

| File | What it does |
|---|---|
| `openapi_verify.php` | Fetches every GET endpoint live and validates `data` against its bound schema (OpenAPI-aware: `$ref`, `allOf`, `nullable`). Reports type violations and undocumented response fields. |
| `openapi_fix.php` | Auto-patches the typed schemas from live responses — adds missing fields (type inferred from the live value), marks `nullable`, relaxes over-strict `required`. Idempotent. |
| `jsonschema4.php` | Minimal draft-04 JSON-Schema validator; checks the generated document against `oas-3.0-schema.json`. Proven to reject malformed documents. |
| `oas-3.0-schema.json` | The official OpenAPI 3.0 meta-schema (from spec.openapis.org), for offline validation. |

The permanent, dependency-free self-check that gates the invariants lives one
level up at `../lib/openapi_check.php`.
