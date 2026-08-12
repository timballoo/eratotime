<?php

/**
 * tenant_lib.php — tenant resolution (spec 1.4 / 6)
 *
 * Resolves the tenant from the URL path (/t/{tenant-slug}/...) and loads that
 * tenant's global_settings. Every other module receives tenant_id from here
 * rather than re-deriving it, and every query in the codebase is scoped by it.
 *
 * Path parsing is pure (no DB); the DB load needs a PDO connection, which the
 * tests supply directly.
 */

if (!function_exists('tenant_parse_from_path')) {

    /**
     * Extract the tenant slug from a request URI of the form /t/{slug}/...
     * Returns the slug, or null if the path doesn't start with /t/.
     */
    function tenant_parse_from_path(string $uri): ?string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if ($path === false || $path === null) {
            return null;
        }
        $path = '/' . ltrim($path, '/');
        if (!preg_match('#^/t/([^/]+)(?:/|$)#', $path, $m)) {
            return null;
        }
        $slug = urldecode($m[1]);
        if (!preg_match('/^[a-z0-9\-]{1,64}$/', $slug)) {
            return null;
        }
        return $slug;
    }

    /**
     * Load the tenant row and its global_settings by slug.
     * Returns a combined array with 'tenant' and 'settings', or null if absent
     * or inactive.
     */
    function tenant_load(PDO $pdo, string $slug): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT t.id, t.slug, t.display_name, t.active, t.created_at
               FROM tenants t
              WHERE t.slug = :slug
                AND t.active = 1
              LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($tenant === false) {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT g.*
               FROM global_settings g
              WHERE g.tenant_id = :tenant_id
              LIMIT 1'
        );
        $stmt->execute(['tenant_id' => (int) $tenant['id']]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return ['tenant' => $tenant, 'settings' => $settings];
    }
}
