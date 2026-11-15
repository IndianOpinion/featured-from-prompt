<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * The protected directory for pending suggestions.
 *
 * Pending files live outside the uploads tree until a person accepts them,
 * so offload and CDN plugins never see an unreviewed image and the file has
 * no public URL. Previews are served through an authenticated REST route.
 */
final class Paths
{
    public static function private_dir(): string
    {
        $base = Constants::get('FFP_PRIVATE_DIR');
        if (!is_string($base) || $base === '') {
            $base = WP_CONTENT_DIR . '/ffp-private';
        }
        $base = rtrim($base, '/\\');
        if (is_multisite()) {
            $base .= '/site-' . get_current_blog_id();
        }
        $dir = apply_filters('ffp_private_dir', $base);
        return is_string($dir) && $dir !== '' ? rtrim($dir, '/\\') : $base;
    }

    /** Create the directory with deny files for Apache and IIS. */
    public static function ensure_private_dir(): string
    {
        $dir = self::private_dir();
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            throw new \RuntimeException('Cannot create the private directory.');
        }
        $files = [
            '.htaccess' => "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
            'index.php' => "<?php\n// Silence is golden.\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
        ];
        foreach ($files as $name => $body) {
            $path = $dir . '/' . $name;
            if (!file_exists($path)) {
                file_put_contents($path, $body);
            }
        }
        return $dir;
    }

    public static function is_private(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        $dir = self::private_dir() . '/';
        return strncmp(wp_normalize_path($path), wp_normalize_path($dir), strlen(wp_normalize_path($dir))) === 0;
    }

    /** A random, non-guessable file name (case 56). */
    public static function random_name(string $ext): string
    {
        return 'ffp-' . bin2hex(random_bytes(8)) . '.' . $ext;
    }

    /**
     * Move a pending file into the uploads tree. Returns the new path.
     */
    public static function publish(string $private_path, int $time): string
    {
        $uploads = wp_upload_dir(gmdate('Y/m', $time));
        if (!empty($uploads['error'])) {
            throw new \RuntimeException('Uploads directory is not writable.');
        }
        $dir = (string) $uploads['path'];
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            throw new \RuntimeException('Cannot create the uploads directory.');
        }
        $name = wp_unique_filename($dir, basename($private_path));
        $target = $dir . '/' . $name;
        if (!rename($private_path, $target)) {
            if (!copy($private_path, $target)) {
                throw new \RuntimeException('Cannot move the file into uploads.');
            }
            unlink($private_path);
        }
        return $target;
    }

    /** Remove the private directory tree (uninstall). */
    public static function remove_private_dir(): void
    {
        $dir = self::private_dir();
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }
}
