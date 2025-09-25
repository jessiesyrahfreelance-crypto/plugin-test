# Dependency Management & Compatibility

This plugin ships with an isolated, prefixed copy of its Composer dependencies to prevent version conflicts with other plugins/themes running on the same WordPress installation.

## Approach

- Namespace isolation via PHP‑Scoper:
  - During the build, all third‑party dependencies inside `vendor/` are processed with [humbug/php-scoper].
  - PHP‑Scoper adds a unique prefix (`WpmudevPluginTest\Vendor`) to all classes/functions/constants from third‑party libraries.
  - The prefixed code is written to `vendor-scoped/` along with its own autoloader.

- Safe autoloading:
  - The plugin bootstrap prefers `vendor-scoped/autoload.php` for production builds.
  - It falls back to `vendor/autoload.php` for local development (when scoping hasn't been executed).
  - This prevents leaking or colliding dependencies into the global runtime.

- Plugin code namespaces:
  - All first‑party code uses the `WPMUDEV\PluginTest\` namespace and is not scoped.
  - Only third‑party packages are prefixed, which keeps our public API stable.

- No interference with other installations:
  - Because third‑party libraries are prefixed and autoloaded privately, versions used here won't override or be overridden by other plugins/themes.
  - The plugin does not modify global composer loaders or constants.

## Build Instructions

1. Install Composer dependencies (local/dev):
   ```
   composer install
   ```

2. Produce a scoped vendor for distribution:
   ```
   composer run build-scoped
   ```
   This will:
   - Ensure a clean `vendor/` without dev dependencies
   - Run PHP‑Scoper to prefix dependencies into `vendor-scoped/`

3. Build distributable zip:
   ```
   npm run build
   ```
   The Grunt build includes `vendor-scoped/` and explicitly excludes `vendor/`.

## Runtime Behavior

- Production:
  - `wpmudev-plugin-test.php` loads `vendor-scoped/autoload.php` if present.
- Development:
  - If `vendor-scoped/` is absent, it falls back to `vendor/autoload.php`.

## Notes

- If you add or update Composer dependencies, re-run:
  ```
  composer install
  composer run build-scoped
  ```
- If you rely on global classes from WordPress or PHP core, do not add them to scoper.
- If a third‑party library requires being exposed globally, you can tweak `scoper.inc.php` to expose specific classes/functions, but prefer isolation.

[humbug/php-scoper]: https://github.com/humbug/php-scoper