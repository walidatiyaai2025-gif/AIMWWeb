<?php

defined('ABSPATH') || exit;

/**
 * Authoritative, bounded WordPress REST data for signed content.read calls.
 * No request may mutate WordPress or impersonate a WordPress user.
 */
final class AIMW_Connector_Resource_Reader
{
    private const RESOURCES = ['posts', 'pages', 'media', 'categories', 'tags', 'comments'];

    public static function listing(WP_REST_Request $request): array|WP_Error
    {
        $resource = (string) $request['resource'];
        if (! in_array($resource, self::RESOURCES, true)) {
            return new WP_Error('invalid_resource', 'Unsupported content resource.', ['status' => 422]);
        }

        $page = filter_var($request->get_param('page') ?? 1, FILTER_VALIDATE_INT);
        $perPage = filter_var($request->get_param('per_page') ?? 100, FILTER_VALIDATE_INT);
        if ($page === false || $page < 1 || $page > 1000 || $perPage === false || $perPage < 1 || $perPage > 100) {
            return new WP_Error('invalid_pagination', 'Invalid resource page or page size.', ['status' => 422]);
        }

        $after = $request->get_param('modified_after');
        if ($after !== null && $after !== '') {
            try {
                $after = (new DateTimeImmutable((string) $after))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (Throwable) {
                return new WP_Error('invalid_modified_after', 'Invalid modified_after timestamp.', ['status' => 422]);
            }
        }

        if (in_array($resource, ['posts', 'pages', 'media'], true)) {
            $args = [
                'post_type' => $resource === 'posts' ? 'post' : ($resource === 'pages' ? 'page' : 'attachment'),
                'post_status' => $resource === 'media'
                    ? ['inherit', 'private']
                    : ['publish', 'draft', 'pending', 'private', 'future'],
                'posts_per_page' => $perPage,
                'paged' => $page,
                'orderby' => 'ID',
                'order' => 'ASC',
            ];
            if ($after) {
                $args['date_query'] = [['column' => 'post_modified_gmt', 'after' => $after, 'inclusive' => false]];
            }

            $query = new WP_Query($args);
            $rows = array_map(
                static fn (WP_Post $post): array => self::postRow($post, $resource === 'media'),
                $query->posts,
            );

            return ['items' => $rows];
        }

        if ($resource === 'comments') {
            $comments = get_comments([
                'number' => $perPage,
                'offset' => ($page - 1) * $perPage,
                'status' => 'all',
                'orderby' => 'comment_ID',
                'order' => 'ASC',
            ]);

            return ['items' => array_map(static fn (WP_Comment $comment): array => self::commentRow($comment), $comments)];
        }

        $taxonomy = $resource === 'categories' ? 'category' : 'post_tag';
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'number' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'orderby' => 'term_id',
            'order' => 'ASC',
        ]);
        if (is_wp_error($terms)) {
            return $terms;
        }

        return ['items' => array_map(static fn (WP_Term $term): array => self::termRow($term), $terms)];
    }

    public static function item(WP_REST_Request $request): array|WP_Error
    {
        $resource = (string) $request['resource'];
        $id = (int) $request['id'];
        if (! in_array($resource, self::RESOURCES, true) || $id < 1) {
            return new WP_Error('invalid_resource', 'Invalid content resource identity.', ['status' => 422]);
        }

        if (in_array($resource, ['posts', 'pages', 'media'], true)) {
            $post = get_post($id);
            $expected = $resource === 'posts' ? 'post' : ($resource === 'pages' ? 'page' : 'attachment');
            if (! ($post instanceof WP_Post) || $post->post_type !== $expected) {
                return new WP_Error('not_found', 'Content not found.', ['status' => 404]);
            }

            return self::postRow($post, $resource === 'media');
        }

        if ($resource === 'comments') {
            $comment = get_comment($id);

            return $comment instanceof WP_Comment
                ? self::commentRow($comment)
                : new WP_Error('not_found', 'Comment not found.', ['status' => 404]);
        }

        $taxonomy = $resource === 'categories' ? 'category' : 'post_tag';
        $term = get_term($id, $taxonomy);

        return $term instanceof WP_Term
            ? self::termRow($term)
            : new WP_Error('not_found', 'Term not found.', ['status' => 404]);
    }

    private static function postRow(WP_Post $post, bool $media): array
    {
        if ($media) {
            return [
                'id' => (int) $post->ID,
                'title' => (string) $post->post_title,
                'slug' => (string) $post->post_name,
                'mime_type' => (string) $post->post_mime_type,
                'media_type' => str_starts_with((string) $post->post_mime_type, 'image/') ? 'image' : 'file',
                'source_url' => wp_get_attachment_url($post->ID) ?: '',
                'alt_text' => (string) get_post_meta($post->ID, '_wp_attachment_image_alt', true),
                'caption' => (string) $post->post_excerpt,
                'description' => (string) $post->post_content,
                'media_details' => wp_get_attachment_metadata($post->ID) ?: [],
                'modified_gmt' => get_post_modified_time(DATE_ATOM, true, $post),
            ];
        }

        return array_merge(AIMW_Connector_V1::serialize($post), [
            'status' => (string) $post->post_status,
            'author' => (int) $post->post_author,
            'featured_media' => (int) get_post_thumbnail_id($post->ID),
            'link' => get_permalink($post->ID) ?: '',
            'template' => (string) get_page_template_slug($post->ID),
            'comment_status' => (string) $post->comment_status,
            'ping_status' => (string) $post->ping_status,
            'format' => get_post_format($post->ID) ?: 'standard',
            'sticky' => is_sticky($post->ID),
            'date_gmt' => get_post_time(DATE_ATOM, true, $post),
            'modified_gmt' => get_post_modified_time(DATE_ATOM, true, $post),
        ]);
    }

    private static function commentRow(WP_Comment $comment): array
    {
        $status = match ((string) $comment->comment_approved) {
            '1' => 'approved',
            '0' => 'hold',
            default => (string) $comment->comment_approved,
        };

        return [
            'id' => (int) $comment->comment_ID,
            'post' => (int) $comment->comment_post_ID,
            'parent' => (int) $comment->comment_parent,
            'author_name' => (string) $comment->comment_author,
            'author_email' => (string) $comment->comment_author_email,
            'content' => (string) $comment->comment_content,
            'status' => $status,
            'link' => get_comment_link($comment) ?: '',
            'date_gmt' => (string) $comment->comment_date_gmt,
        ];
    }

    private static function termRow(WP_Term $term): array
    {
        return [
            'id' => (int) $term->term_id,
            'name' => (string) $term->name,
            'slug' => (string) $term->slug,
            'description' => (string) $term->description,
            'parent' => (int) $term->parent,
            'count' => (int) $term->count,
        ];
    }
}
