# danmahara/laravel-openapi

Attribute-based OpenAPI 3.1 documentation for Laravel, with automatic schema
inference from FormRequests and API Resources.

Built as an alternative to `darkaonline/l5-swagger`. The main difference:
request/response schemas are inferred from code that already exists
(`FormRequest::rules()`, `JsonResource::toArray()`) instead of being written
out by hand in annotations.

## Install

```bash
composer require danmahara/laravel-openapi
php artisan vendor:publish --tag=openapi-config
```

## Usage

Routes registered with Laravel's `Route` facade (or router) are discovered
at generation time. No attributes are required:

```php
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->post('api/users', [UserController::class, 'store']);

class UserController extends Controller
{
    public function store(StoreUserRequest $request): UserResource
    {
        return new UserResource(User::create($request->validated()));
    }
}
```

This generates a POST operation at `/api/users`, infers the request schema
from `StoreUserRequest`, and a default 200 response schema from `UserResource`.
GET, POST, PUT, PATCH, DELETE and OPTIONS are supported; HEAD is omitted.
Controller methods, invokable controllers, and closures are supported.
Route groups and resource routes work through Laravel's registered route table.
URI parameters are included automatically; optional `{id?}` becomes `{id}`
with `required: true`, as OpenAPI requires for path parameters.
Route/group and controller middleware names are exposed as
`x-laravel-middleware`; recognized authentication middleware also adds OpenAPI security.
Middleware aliases/groups remain unexpanded. If the container cannot resolve a
controller dependency, only route/group middleware is available.

A single FormRequest type hint and a single JsonResource return type (including
nullable types) enable inference. Ambiguous types require explicit attributes.
Actions are never executed. Resource inference uses the existing heuristic and
does not infer collection item types, response wrapping, or HTTP status codes.
Use explicit attributes for these details, including a 201 creation response.

Attributes customize the discovered operation. Explicit request bodies and
responses take precedence over type hints; an explicit parameter replaces the
inferred parameter with the same name and location. `ApiExclude` and configured
include/exclude patterns still apply.

For example, add attributes to controller methods:

```php
use Danmahara\LaravelOpenApi\Attributes\{ApiOperation, ApiParameter, ApiRequestBody, ApiResponse, ApiTag};

#[ApiTag(name: 'Users')]
class UserController extends Controller
{
    #[ApiOperation(summary: 'List users')]
    #[ApiParameter(name: 'page', in: 'query', type: 'integer')]
    #[ApiResponse(status: 200, description: 'Paginated users', resource: UserResource::class, isCollection: true)]
    public function index() { /* ... */ }

    #[ApiOperation(summary: 'Create a user')]
    #[ApiRequestBody(formRequest: StoreUserRequest::class)]
    #[ApiResponse(status: 201, resource: UserResource::class)]
    public function store(StoreUserRequest $request) { /* ... */ }
}
```

Then either:

- Visit `/api/documentation` for a live Swagger UI, backed by `/api/documentation.json`
- Run `php artisan openapi:generate` (or `--format=yaml`) to write a static spec file

Both routes and the output path are configurable in `config/openapi.php`,
including which route URI patterns to include/exclude (`include`/`exclude`,
matched with Laravel's `Str::is()` wildcard patterns).

## Automatic authentication detection

Authentication middleware on discovered routes, route groups, and controllers
adds operation security and a reusable component automatically:

```php
Route::middleware('auth:sanctum')
    ->get('api/users', [UserController::class, 'index']);
```

```yaml
paths:
  /api/users:
    get:
      security:
        - sanctum: []
components:
  securitySchemes:
    sanctum:
      type: http
      scheme: bearer
```

The built-in mappings are `auth:sanctum` → `sanctum`, `auth:api` → `api`,
and `auth` → `auth`, all using HTTP bearer authentication. These are documentation
defaults: Laravel guards can use other mechanisms, and Sanctum can also use
session cookies. The package does not inspect guard drivers or execute auth.
Public routes receive no security requirement. Repeated schemes and identical
requirements are deduplicated. Separate recognized middleware are required
together (OpenAPI AND semantics).

Class or method `#[ApiSecurity(name: 'customScheme')]` attributes replace automatic
detection for that operation; define those schemes in `security_schemes`.
Existing class and method attributes remain combined. Configured
`security_schemes` definitions take precedence over inferred definitions.

Extend or override exact middleware mappings in `config/openapi.php`, without
changing route discovery. For example, to document bare `auth` as session auth:

```php
'middleware_security' => [
    'auth' => [
        'session' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => 'laravel_session'],
    ],
    'custom-token' => [
        'token' => ['type' => 'http', 'scheme' => 'bearer'],
    ],
    'auth:api' => [], // Disable automatic detection for this middleware.
],
```

Matching uses the middleware names returned by route discovery. Custom aliases,
nested middleware groups, class names, and multi-guard strings such as
`auth:sanctum,api` need an explicit mapping or security attributes. Excluded
middleware exclusions use Laravel’s alias and class resolution, while named
middleware groups remain unexpanded. Controller middleware is unavailable if controller dependencies
cannot be resolved.

## Attributes

| Attribute | Target | Purpose |
|---|---|---|
| `ApiOperation` | method | summary, description, tags, deprecated flag, operationId |
| `ApiParameter` | method (repeatable) | query/path/header/cookie parameter |
| `ApiRequestBody` | method | ties a `FormRequest` class (or explicit `schema`) to the body |
| `ApiResponse` | method (repeatable) | one response per status code; ties a `JsonResource` (or explicit `schema`) |
| `ApiSecurity` | class or method (repeatable) | references a security scheme from config |
| `ApiTag` | class | default tag for every method in the controller |
| `ApiExclude` | method | skip this action entirely |

Path parameters (`{id}` in the route URI) are picked up automatically and
don't need an `ApiParameter` attribute unless you want to add a description
or a non-string type.

## How schema inference works — and its limits

**FormRequest → schema** (`FormRequestSchemaBuilder`): reflects the class and
calls `rules()` directly (via `newInstanceWithoutConstructor`, without
booting the request). This works for rules that don't depend on the
container, the current route, or auth state. Rules that do depend on those
things throw during reflection and are caught — those fields are silently
omitted rather than guessed. Nested/wildcard keys (`items.*.name`) are
skipped for the same reason: flattening them correctly needs more context
than a rule string alone gives.

Supported rule → schema mappings: `required`, `nullable`, `integer`,
`numeric`, `boolean`, `array`, `date`/`date_format`, `email`, `uuid`, `in:`,
`min:`, `max:`.

**JsonResource → schema** (`ResourceSchemaBuilder`): this one is a heuristic,
not an evaluator. It never runs `toArray()` — it statically scans the
method's source for `'key' => expression` pairs and guesses a type from the
key name and the shape of the expression (`is_`/`has_` prefixes → boolean,
`id`/`*_id` → integer, `*_at`/`date`/`created`/`updated` → date-time string,
`::collection` → array). Anything it can't classify defaults to `string`.
**Review generated response schemas** — this is the part most likely to need
manual correction, especially for computed fields or unusual naming.

Both builders accept an explicit `schema` array on the attribute instead
(`#[ApiResponse(status: 200, schema: [...])]`), which skips inference
entirely for cases the heuristics get wrong.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The suite includes discovery unit tests and Laravel integration tests for
automatic inference, middleware, closures, route filters, and manual metadata
precedence. The CI matrix runs the suite on Laravel 10, 11, 12, and 13 with
the corresponding Orchestra Testbench versions.

## Known limitations / roadmap

- No support yet for `oneOf`/`allOf`/polymorphic resources.
- No caching layer — regeneration walks the full route table each call.
  Fine for `artisan openapi:generate` in CI; for the live `/api/documentation.json`
  route on a large app, consider caching the array behind `Cache::remember`.
- Swagger UI is loaded from a CDN (`unpkg.com`) in the bundled view. For
  offline/air-gapped environments, publish the view
  (`--tag=openapi-views`) and swap in a locally vendored copy of
  `swagger-ui-dist`.
