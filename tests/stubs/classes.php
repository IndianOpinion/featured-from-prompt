<?php

// phpcs:ignoreFile -- WordPress class stubs for the unit tests.

declare(strict_types=1);

class WP_Post
{
    public int $ID = 0;
    public string $post_title = '';
    public string $post_content = '';
    public string $post_excerpt = '';
    public string $post_status = 'publish';
    public string $post_type = 'post';
    public string $post_password = '';
    public string $post_name = '';
    public string $post_mime_type = '';
    public string $post_date = '2026-11-01 10:00:00';
    public int $post_parent = 0;
    public int $post_author = 1;

    /** @param array<string, mixed> $data */
    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}

class WP_Term
{
    public int $term_id = 0;
    public string $name = '';
    public string $slug = '';
    public string $taxonomy = '';

    public function __construct(int $id, string $name, string $slug, string $taxonomy)
    {
        $this->term_id = $id;
        $this->name = $name;
        $this->slug = $slug;
        $this->taxonomy = $taxonomy;
    }
}

class WP_User
{
    public int $ID;
    public string $display_name;
    public string $user_email;

    public function __construct(int $id, string $name = 'User', string $email = '')
    {
        $this->ID = $id;
        $this->display_name = $name;
        $this->user_email = $email;
    }
}

class WP_Error
{
    /** @var array<string, list<string>> */
    public array $errors = [];
    /** @var array<string, mixed> */
    public array $error_data = [];

    public function __construct(string $code = '', string $message = '', mixed $data = '')
    {
        if ($code !== '') {
            $this->errors[$code][] = $message;
            if ($data !== '') {
                $this->error_data[$code] = $data;
            }
        }
    }

    public function get_error_code(): string
    {
        $codes = array_keys($this->errors);
        return (string) ($codes[0] ?? '');
    }

    public function get_error_message(string $code = ''): string
    {
        $code = $code !== '' ? $code : $this->get_error_code();
        return $this->errors[$code][0] ?? '';
    }

    public function get_error_data(string $code = ''): mixed
    {
        $code = $code !== '' ? $code : $this->get_error_code();
        return $this->error_data[$code] ?? null;
    }
}

class WP_REST_Request implements ArrayAccess
{
    /** @var array<string, mixed> */
    private array $params = [];
    private string $method;
    private string $route;

    /** @param array<string, mixed> $params */
    public function __construct(string $method = 'GET', string $route = '', array $params = [])
    {
        $this->method = $method;
        $this->route = $route;
        $this->params = $params;
    }

    public function get_param(string $key): mixed
    {
        return $this->params[$key] ?? null;
    }

    public function set_param(string $key, mixed $value): void
    {
        $this->params[$key] = $value;
    }

    public function get_route(): string
    {
        return $this->route;
    }

    public function get_method(): string
    {
        return $this->method;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->params[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->params[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->params[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->params[$offset]);
    }
}

class WP_REST_Response
{
    /** @var array<string, string> */
    public array $headers = [];

    public function __construct(public mixed $data = null, public int $status = 200)
    {
    }

    public function header(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function get_data(): mixed
    {
        return $this->data;
    }

    public function get_status(): int
    {
        return $this->status;
    }
}

class WP_Query
{
    /** @var array<string, mixed> */
    public array $vars = [];
    public bool $main = true;

    public function get(string $key): mixed
    {
        return $this->vars[$key] ?? '';
    }

    public function set(string $key, mixed $value): void
    {
        $this->vars[$key] = $value;
    }

    public function is_main_query(): bool
    {
        return $this->main;
    }
}

class WP_Screen
{
    public string $id = '';
    public string $post_type = '';
    public bool $block = false;

    public function is_block_editor(): bool
    {
        return $this->block;
    }
}

class WP_Post_Type
{
    public function __construct(public string $name = 'post', public string $label = 'Posts')
    {
    }
}

/**
 * A small GD editor, enough for crop and format conversion.
 */
class FFP_Test_Image_Editor
{
    /** @var \GdImage */
    private $image;
    private int $quality = 82;

    public function __construct(private string $file)
    {
        $data = (string) file_get_contents($file);
        $image = imagecreatefromstring($data);
        if ($image === false) {
            throw new RuntimeException('cannot load');
        }
        $this->image = $image;
    }

    public function crop(int $x, int $y, int $w, int $h): bool
    {
        $cropped = imagecrop($this->image, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
        if ($cropped === false) {
            return false;
        }
        $this->image = $cropped;
        return true;
    }

    public function set_quality(int $quality): bool
    {
        $this->quality = $quality;
        return true;
    }

    /** @return array<string, mixed>|WP_Error */
    public function save(string $path, string $mime)
    {
        if (in_array($mime, WP::$editor_refuses, true)) {
            return new WP_Error('image_save_error', 'Unsupported.');
        }
        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($this->image, $path, $this->quality),
            'image/webp' => imagewebp($this->image, $path, $this->quality),
            default => imagepng($this->image, $path),
        };
        if (!$ok) {
            return new WP_Error('image_save_error', 'Failed.');
        }
        return ['path' => $path, 'file' => basename($path), 'width' => imagesx($this->image), 'height' => imagesy($this->image), 'mime-type' => $mime];
    }
}

class WP_CLI
{
    /** @var list<string> */
    public static array $log = [];
    public static ?int $halted = null;

    public static function log(string $message): void
    {
        self::$log[] = $message;
    }

    public static function warning(string $message): void
    {
        self::$log[] = 'Warning: ' . $message;
    }

    public static function success(string $message): void
    {
        self::$log[] = 'Success: ' . $message;
    }

    public static function halt(int $code): void
    {
        if (self::$halted === null) {
            self::$halted = $code;
        }
    }

    public static function confirm(string $question): void
    {
    }

    public static function add_command(string $name, mixed $callable): void
    {
    }

    public static function reset(): void
    {
        self::$log = [];
        self::$halted = null;
    }
}
