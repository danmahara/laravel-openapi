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

Add attributes to controller methods:

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

`tests/Fixtures/` has a sample controller and FormRequest exercising the
main code paths; `OpenApiGeneratorTest` asserts the generated paths, the
schema inferred from the sample FormRequest's rules, and that `include`/
`exclude` patterns are respected.

## Known limitations / roadmap

- Closures aren't documented (no attributes to reflect on) — only
  controller-class routes are picked up.
- No support yet for `oneOf`/`allOf`/polymorphic resources.
- No caching layer — regeneration walks the full route table each call.
  Fine for `artisan openapi:generate` in CI; for the live `/api/documentation.json`
  route on a large app, consider caching the array behind `Cache::remember`.
- Swagger UI is loaded from a CDN (`unpkg.com`) in the bundled view. For
  offline/air-gapped environments, publish the view
  (`--tag=openapi-views`) and swap in a locally vendored copy of
  `swagger-ui-dist`.
