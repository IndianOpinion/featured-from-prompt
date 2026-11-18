<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * REST API, namespace featured-from-prompt/v1 (5.6).
 */
final class Rest
{
    public const NS = 'featured-from-prompt/v1';

    private const STATUS = [
        Reason::FORBIDDEN => 403,
        Reason::BAD_NONCE => 403,
        Reason::POST_GONE => 404,
        Reason::NOT_FOUND => 404,
        Reason::NOT_GENERATED => 404,
        Reason::NOT_PENDING => 409,
        Reason::WRONG_POST => 409,
        Reason::ALREADY_QUEUED => 409,
        Reason::POST_LOCKED => 409,
        Reason::CAP_DAY => 429,
        Reason::CAP_POST => 429,
    ];

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
        add_filter('rest_pre_serve_request', [self::class, 'serve_raw'], 10, 4);
    }

    public static function routes(): void
    {
        $id = ['id' => ['type' => 'integer', 'minimum' => 1, 'required' => true]];
        register_rest_route(self::NS, '/posts/(?P<id>\d+)/status', [
            'methods' => 'GET',
            'callback' => [self::class, 'status'],
            'permission_callback' => [self::class, 'can_edit_post'],
            'args' => $id,
        ]);
        register_rest_route(self::NS, '/posts/(?P<id>\d+)/prompt', [
            'methods' => 'GET',
            'callback' => [self::class, 'prompt'],
            'permission_callback' => [self::class, 'can_edit_post'],
            'args' => $id + ['prompt' => ['type' => 'string', 'required' => false]],
        ]);
        register_rest_route(self::NS, '/posts/(?P<id>\d+)/generate', [
            'methods' => 'POST',
            'callback' => [self::class, 'generate'],
            'permission_callback' => [self::class, 'can_generate'],
            'args' => $id + ['prompt' => ['type' => 'string', 'required' => false]],
        ]);
        register_rest_route(self::NS, '/posts/(?P<id>\d+)/cancel', [
            'methods' => 'POST',
            'callback' => [self::class, 'cancel'],
            'permission_callback' => [self::class, 'can_edit_post'],
            'args' => $id,
        ]);
        register_rest_route(self::NS, '/posts/(?P<id>\d+)/opt-out', [
            'methods' => 'POST',
            'callback' => [self::class, 'opt_out'],
            'permission_callback' => [self::class, 'can_edit_post'],
            'args' => $id + ['value' => ['type' => 'boolean', 'required' => true]],
        ]);
        register_rest_route(self::NS, '/posts/(?P<id>\d+)/clear-marker', [
            'methods' => 'POST',
            'callback' => [self::class, 'clear_marker'],
            'permission_callback' => [self::class, 'can_generate'],
            'args' => $id,
        ]);
        register_rest_route(self::NS, '/suggestions/(?P<id>\d+)/accept', [
            'methods' => 'POST',
            'callback' => [self::class, 'accept'],
            'permission_callback' => [self::class, 'can_review'],
            'args' => $id + [
                'post_id' => ['type' => 'integer', 'required' => false],
                'confirm_lock' => ['type' => 'boolean', 'required' => false],
            ],
        ]);
        register_rest_route(self::NS, '/suggestions/(?P<id>\d+)/reject', [
            'methods' => 'POST',
            'callback' => [self::class, 'reject'],
            'permission_callback' => [self::class, 'can_review'],
            'args' => $id + [
                'post_id' => ['type' => 'integer', 'required' => false],
                'reason' => ['type' => 'string', 'required' => false],
            ],
        ]);
        register_rest_route(self::NS, '/suggestions/(?P<id>\d+)/preview', [
            'methods' => 'GET',
            'callback' => [self::class, 'preview'],
            'permission_callback' => [self::class, 'can_review'],
            'args' => $id,
        ]);
    }

    public static function generate_capability(): string
    {
        $cap = apply_filters('ffp_capability_generate', 'edit_others_posts');
        return is_string($cap) && $cap !== '' ? $cap : 'edit_others_posts';
    }

    /** @return bool|\WP_Error */
    public static function can_edit_post(\WP_REST_Request $request)
    {
        $post_id = (int) $request['id'];
        if (!get_post($post_id) instanceof \WP_Post) {
            return self::error(Reason::POST_GONE);
        }
        return current_user_can('edit_post', $post_id) ? true : self::error(Reason::FORBIDDEN);
    }

    /** @return bool|\WP_Error */
    public static function can_generate(\WP_REST_Request $request)
    {
        $edit = self::can_edit_post($request);
        if ($edit !== true) {
            return $edit;
        }
        return current_user_can('upload_files') && current_user_can(self::generate_capability())
            ? true : self::error(Reason::FORBIDDEN);
    }

    /** @return bool|\WP_Error */
    public static function can_review(\WP_REST_Request $request)
    {
        $attachment_id = (int) $request['id'];
        $parent = (int) get_post_field('post_parent', $attachment_id);
        if ($parent <= 0 || !Suggestions::is_generated($attachment_id)) {
            return self::error(Reason::NOT_GENERATED);
        }
        return current_user_can('edit_post', $parent) && current_user_can('upload_files')
            ? true : self::error(Reason::FORBIDDEN);
    }

    public static function status(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response(self::status_payload((int) $request['id']), 200);
    }

    /** @return \WP_REST_Response */
    public static function prompt(\WP_REST_Request $request)
    {
        $override = $request->get_param('prompt');
        $provider = Plugin::providers()->get(Settings::provider_id());
        $preview = PromptBuilder::preview((int) $request['id'], is_string($override) ? $override : null, $provider?->capabilities());
        return new \WP_REST_Response($preview->to_array(), 200);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function generate(\WP_REST_Request $request)
    {
        $prompt = $request->get_param('prompt');
        $result = Queue::request((int) $request['id'], [
            'manual' => true,
            'trigger' => 'manual',
            'prompt' => is_string($prompt) ? $prompt : null,
            'user_id' => get_current_user_id(),
        ]);
        if (!$result->queued) {
            return self::error($result->reason, ['request_id' => $result->request_id]);
        }
        return new \WP_REST_Response($result->to_array() + ['status' => self::status_payload((int) $request['id'])], 202);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function cancel(\WP_REST_Request $request)
    {
        $active = Plugin::storage()->active_request_for_post((int) $request['id']);
        if ($active === null || !Queue::cancel((string) $active['id'])) {
            return self::error(Reason::NOT_FOUND);
        }
        return new \WP_REST_Response(['cancelled' => (string) $active['id']], 200);
    }

    public static function opt_out(\WP_REST_Request $request): \WP_REST_Response
    {
        $post_id = (int) $request['id'];
        if ((bool) $request->get_param('value')) {
            update_post_meta($post_id, Eligibility::OPT_OUT_META, '1');
        } else {
            delete_post_meta($post_id, Eligibility::OPT_OUT_META);
        }
        return new \WP_REST_Response(self::status_payload($post_id), 200);
    }

    public static function clear_marker(\WP_REST_Request $request): \WP_REST_Response
    {
        Queue::clear_marker((int) $request['id']);
        return new \WP_REST_Response(self::status_payload((int) $request['id']), 200);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function accept(\WP_REST_Request $request)
    {
        $result = Suggestions::accept(
            (int) $request['id'],
            get_current_user_id(),
            (int) $request->get_param('post_id'),
            (bool) $request->get_param('confirm_lock')
        );
        if (!$result->ok) {
            $data = ['lock_user' => $result->lock_user];
            if ($result->lock_user > 0) {
                $user = get_userdata($result->lock_user);
                $data['lock_name'] = $user instanceof \WP_User ? $user->display_name : '';
            }
            return self::error($result->reason, $data);
        }
        return new \WP_REST_Response($result->to_array() + ['status' => self::status_payload($result->post_id)], 200);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function reject(\WP_REST_Request $request)
    {
        $reason = $request->get_param('reason');
        $result = Suggestions::reject(
            (int) $request['id'],
            get_current_user_id(),
            is_string($reason) ? $reason : '',
            (int) $request->get_param('post_id')
        );
        if (!$result->ok) {
            return self::error($result->reason);
        }
        return new \WP_REST_Response($result->to_array() + ['status' => self::status_payload($result->post_id)], 200);
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function preview(\WP_REST_Request $request)
    {
        $attachment_id = (int) $request['id'];
        $file = Suggestions::private_file($attachment_id);
        if ($file === '') {
            $attached = get_attached_file($attachment_id);
            $file = is_string($attached) && is_file($attached) ? $attached : '';
        }
        if ($file === '') {
            return self::error(Reason::FILE_MISSING, [], 404);
        }
        $response = new \WP_REST_Response(['ffp_file' => $file], 200);
        $response->header('Content-Type', (string) get_post_mime_type($attachment_id));
        $response->header('Cache-Control', 'private, no-store');
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    /**
     * Stream the preview bytes instead of JSON.
     *
     * @param mixed $served
     * @param mixed $result
     * @param mixed $request
     * @param mixed $server
     */
    public static function serve_raw($served, $result, $request, $server): bool
    {
        if ($served || !$result instanceof \WP_REST_Response || !$request instanceof \WP_REST_Request) {
            return (bool) $served;
        }
        if (preg_match('#^/' . preg_quote(self::NS, '#') . '/suggestions/\d+/preview$#', $request->get_route()) !== 1) {
            return false;
        }
        $data = $result->get_data();
        if (!is_array($data) || !isset($data['ffp_file']) || !is_string($data['ffp_file']) || !is_file($data['ffp_file'])) {
            return false;
        }
        readfile($data['ffp_file']);
        return true;
    }

    public static function preview_url(int $attachment_id): string
    {
        $url = rest_url(self::NS . '/suggestions/' . $attachment_id . '/preview');
        return add_query_arg('_wpnonce', wp_create_nonce('wp_rest'), $url);
    }

    /**
     * Everything the panel shows (case 60).
     *
     * @return array<string, mixed>
     */
    public static function status_payload(int $post_id): array
    {
        return Api::status($post_id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function error(string $reason, array $data = [], int $status = 0): \WP_Error
    {
        $status = $status > 0 ? $status : (self::STATUS[$reason] ?? 400);
        return new \WP_Error($reason, Reason::describe($reason), ['status' => $status] + $data);
    }
}
