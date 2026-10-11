<?php

/**
 * Paradise Studios - environment loader.
 *
 * Secrets live in a single .env file that is kept OUTSIDE the web root, so it
 * cannot be downloaded over HTTP even by accident.
 *
 * Resolution order for the env file path:
 *   1. the PS_ENV_FILE environment variable, if set;
 *   2. <three levels above config/>paradisestudios.env
 *
 * On this machine that resolves to C:\xampp\paradisestudios.env - above
 * htdocs, so Apache cannot serve it. See config/.env.example.
 *
 * Real environment variables always win over the file, so a hosting panel or
 * a CI secret store can supply values without a file at all.
 *
 * Every accessor has a default, so a project with no .env behaves exactly as
 * it did before this file existed.
 */

if (!defined('PS_ENV_LOADED')) {
    define('PS_ENV_LOADED', true);

    /**
     * Absolute path to the .env file, whether or not it exists.
     */
    function ps_env_file(): string
    {
        $fromEnvironment = getenv('PS_ENV_FILE');

        if (is_string($fromEnvironment) && trim($fromEnvironment) !== '') {
            return trim($fromEnvironment);
        }

        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'paradisestudios.env';
    }

    /**
     * Read the .env file into a lookup table.
     *
     * Deliberately minimal - no interpolation, no variable expansion, no
     * includes. Supports KEY=VALUE, # comments, blank lines, optional
     * `export ` prefix and optional single or double quotes around values.
     *
     * The result is cached per path so repeated reads are cheap. Use
     * ps_env_reset() to clear it, which the test suite needs in order to
     * exercise several configurations inside one process.
     *
     * @return array<string, string>
     */
    function ps_env_parse(?string $path = null, bool $refresh = false): array
    {
        static $cache = [];

        $path ??= ps_env_file();

        if (!$refresh && isset($cache[$path])) {
            return $cache[$path];
        }

        $parsed = [];

        if (is_file($path) && is_readable($path)) {
            $handle = fopen($path, 'rb');

            if ($handle !== false) {
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);

                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }

                    if (str_starts_with($line, 'export ')) {
                        $line = trim(substr($line, 7));
                    }

                    $separator = strpos($line, '=');

                    if ($separator === false) {
                        continue;
                    }

                    $key = trim(substr($line, 0, $separator));
                    $value = trim(substr($line, $separator + 1));

                    if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $key)) {
                        continue;
                    }

                    // Strip matching surrounding quotes, leave inner content alone.
                    if (
                        strlen($value) >= 2
                        && (($value[0] === '"' && str_ends_with($value, '"'))
                            || ($value[0] === "'" && str_ends_with($value, "'")))
                    ) {
                        $value = substr($value, 1, -1);
                    }

                    $parsed[$key] = $value;
                }

                fclose($handle);
            }
        }

        $cache[$path] = $parsed;

        return $parsed;
    }

    /**
     * Discard the cached .env file contents.
     *
     * Exists so tests and long-running CLI tools can switch configuration
     * within a single process. Ordinary web requests never need it.
     */
    /**
     * A counter that increases every time the .env file is re-read.
     *
     * Consumers that cache derived configuration can compare against this and
     * rebuild automatically, so no module has to remember to invalidate
     * anything by hand after the environment changes.
     */
    function ps_env_generation(): int
    {
        return (int) ($GLOBALS['_ps_env_generation'] ?? 0);
    }

    function ps_env_reset(): void
    {
        ps_env_parse(null, true);
        ps_env_bump_generation();
    }

    /**
     * Increment the generation counter.
     */
    function ps_env_bump_generation(): void
    {
        $GLOBALS['_ps_env_generation'] = (int) ($GLOBALS['_ps_env_generation'] ?? 0) + 1;
    }

    /**
     * Read a configuration value.
     *
     * Order: real environment variable, then .env file, then $default.
     */
    function ps_env(string $key, ?string $default = null): ?string
    {
        $fromEnvironment = getenv($key);

        if (is_string($fromEnvironment) && $fromEnvironment !== '') {
            return $fromEnvironment;
        }

        $file = ps_env_parse();

        if (array_key_exists($key, $file) && $file[$key] !== '') {
            return $file[$key];
        }

        return $default;
    }

    function ps_env_bool(string $key, bool $default = false): bool
    {
        $value = ps_env($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    function ps_env_int(string $key, ?int $default = null): ?int
    {
        $value = ps_env($key);

        if ($value === null || !preg_match('/^-?\d+$/', trim($value))) {
            return $default;
        }

        return (int) trim($value);
    }
}
