# CLAUDE.md

## Project Overview

Flourish WooCommerce Plugin — a WordPress/WooCommerce plugin that integrates the Flourish seed-to-sale platform (cannabis industry) with WooCommerce storefronts for B2B and B2C sales. It handles product/inventory synchronization, order management, webhooks, and custom checkout fields.

- **Plugin version:** 1.4.0
- **License:** GPLv3
- **Entry point:** `flourish-woocommerce-plugin.php`
- **Main class:** `src/FlourishWooCommercePlugin.php` (singleton pattern via `get_instance()`)

## Tech Stack

- **PHP** >=7.0 (WordPress plugin)
- **WordPress** + **WooCommerce** (requires WC 2.2+)
- **Composer** for autoloading and dependencies
- **Vanilla JavaScript** (no build tools, no bundlers)
- **Plain CSS** (no preprocessors)
- **Docker Compose** for local development (`docker-compose.yml` — WordPress + MySQL 5.7)

## Project Structure

```
src/                          # Main plugin source (PSR-4 autoloaded)
├── FlourishWooCommercePlugin.php   # Singleton main plugin class
├── API/                      # Flourish API client and webhook handler
├── Admin/                    # WP admin pages and settings
├── Handlers/                 # Order/cart processing logic
├── CustomFields/             # Custom checkout/order fields
├── Helpers/                  # Utility classes
├── Importer/                 # Product import from Flourish
└── Services/                 # DI container (ServiceProvider)
assets/
├── css/                      # Stylesheets
└── js/                       # Frontend scripts (vanilla JS)
templates/emails/             # WooCommerce email templates
classes/emails/               # Legacy WC email class
vendor/                       # Composer dependencies (do not edit)
```

## Namespace & Autoloading

- Root namespace: `FlourishWooCommercePlugin\`
- PSR-4 mapping: `FlourishWooCommercePlugin\` → `src/`
- Sub-namespaces mirror directory structure: `API`, `Admin`, `Handlers`, `CustomFields`, `Helpers`, `Importer`, `Services`

## Coding Conventions

- Every PHP file starts with `defined( 'ABSPATH' ) || exit;`
- Mix of camelCase and snake_case (follows WordPress conventions)
- Handler classes: `Handler[Feature]` (e.g., `HandlerOrdersSyncNow`)
- All classes register WordPress hooks via `register_hooks()` methods
- WordPress hook callbacks: `add_action()` / `add_filter()` with `[$this, 'method_name']`
- Error handling: `try/catch` with `error_log()` for logging
- Input sanitization: use `sanitize_text_field()` and WordPress nonces for security
- Settings stored via WordPress Options API (`get_option()` / `update_option()`)

## Key Patterns

- **Singleton:** `FlourishWooCommercePlugin::get_instance()` — one instance per request
- **Service Provider:** `Services\ServiceProvider` handles dependency injection and class registration
- **Handler Pattern:** Separate handler classes for Retail vs Outbound order flows
- **Webhook Auth:** HMAC SHA-256 signature verification on incoming webhooks (`FlourishWebhook`)
- **REST API:** Webhook endpoint at `/wp-json/flourish-woocommerce-plugin/v1/webhook`

## Development Environment

```bash
# Start local environment
docker-compose up -d
# WordPress available at http://localhost:8080

# Install/update PHP dependencies
composer install
```

## Dependencies

- **Production:** `yahnis-elsts/plugin-update-checker` v5.3 (auto-updates from GitHub)
- **No npm/node dependencies**

## Testing & Linting

- No automated test suite configured (no PHPUnit, no tests/ directory)
- No linting tools configured (no PHPCS, PHPStan, ESLint)
- No CI/CD pipeline
- No git hooks enabled

## Common Tasks

- **Adding a new handler:** Create a class in `src/Handlers/`, namespace it under `FlourishWooCommercePlugin\Handlers`, register hooks in `register_hooks()`, and wire it up in `ServiceProvider`
- **Adding admin settings:** Modify classes in `src/Admin/`, follow existing `SettingsPage.php` patterns
- **Adding custom checkout fields:** Add a class in `src/CustomFields/`, register via hooks
- **Modifying webhook handling:** Edit `src/API/FlourishWebhook.php`

## Important Notes

- `vendor/` is committed — run `composer install` after pulling to ensure autoloader is current
- No `.gitignore` file exists — be careful not to commit sensitive files
- `src/CustomFields/License copy.php` is a legacy/backup file — not actively used
- Plugin updates are distributed via GitHub releases on the `main` branch
