<?php
/**
 * Plugin Name: AIKO
 * Description: AI Knowledge Observatory Markup
 * Version: 0.2
 * Author: AIKO Contributors
 */

if (!defined('ABSPATH')) {
    exit;
}

final class AIKO
{
    const SCHEMA_VERSION = '1.0';

    const OUTPUT_FILE = 'aiko.json';

    /**
     * Bootstrap
     */
    public static function init()
    {
        register_activation_hook(__FILE__, [self::class, 'activate']);

        add_action('save_post', [self::class, 'onPostSaved'], 20, 3);

        add_action('trashed_post', [self::class, 'onPostDeleted'], 20, 1);

        add_action('admin_menu', [self::class, 'adminMenu']);

        add_action('template_redirect', [self::class, 'serveWellKnown']);
    }

    public static function serveWellKnown()
    {
        if (!isset($_SERVER['REQUEST_URI'])) {
            return;
        }

        if ($_SERVER['REQUEST_URI'] !== '/.well-known/aiko.json') {
            return;
        }

        header('Content-Type: application/json');

        echo json_encode(self::loadDocuments());

        exit;
    }

    /**
     * Plugin activated
     */
    public static function activate()
    {
        self::rebuild();
    }

    /**
     * Read current AIKO documents.
     */
    private static function loadDocuments(): array
    {
        $file = ABSPATH . '.well-known/aiko.json';

        if (!file_exists($file)) {
            return [];
        }

        $json = file_get_contents($file);

        if (!$json) {
            return [];
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            return [];
        }

        return $data['documents'] ?? [];
    }

    /**
     * Update a single document.
     */
    private static function updateDocument(WP_Post $post): void
    {
       $documents = self::loadDocuments();

       $permalink = $post->post_name;

       /*
        * 建立 permalink 索引
        */
       $index = null;

       foreach ($documents as $i => $doc) {

           if (
               isset($doc['permalink']) &&
               $doc['permalink'] === $permalink
           ) {
               $index = $i;
               break;
           }
       }

       /*
        * 非 publish：删除
        */
       if ($post->post_status !== 'publish') {

           if ($index !== null) {
               unset($documents[$index]);
           }

           self::publish(array_values($documents));
           return;
       }

       /*
        * build 新 document
        */
       $document = self::buildDocument($post);

       if ($index !== null) {

           $documents[$index] = $document;

       } else {

           $documents[] = $document;

       }

       /*
        * 保持最近更新排序
        */
       usort($documents, function ($a, $b) {

           return strcmp(
               $b['lastUpdated'],
               $a['lastUpdated']
           );

       });

       self::publish($documents);
    }

    /**
     * Handle post updates.
     */
    public static function onPostSaved(
        int $postId,
        WP_Post $post,
        bool $update
    ): void {

        /*
         * Ignore autosave
         */
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        /*
         * Ignore revisions
         */
        if (wp_is_post_revision($postId)) {
            return;
        }

        /*
         * Ignore unsupported post types
         */
        if (!in_array(
            $post->post_type,
            self::supportedPostTypes(),
            true
        )) {
            return;
        }

        if ($post->post_status !== 'publish') {
            return;
        }

        self::updateDocument($post);
    }

    /**
     * Handle post deletion.
     */
    public static function onPostDeleted($post_id)
    {
       $post = get_post($post_id);

       if (!$post) {
           return;
       }

       if ($post->post_type !== 'post') {
           return;
       }

       $permalink = $post->post_name;

       $permalink = preg_replace('/__trashed.*$/', '', $permalink);

       $documents = self::loadDocuments();

       foreach ($documents as $i => $doc) {
           if (
               isset($doc['permalink']) &&
               $doc['permalink'] === $permalink
           ) {
               unset($documents[$i]);
               break;
           }
       }

       $documents = array_values($documents);

       self::publish($documents);
    }

    /**
     * Supported post types.
     */
    private static function supportedPostTypes(): array
    {
        return apply_filters(
            'aiko_supported_post_types',
            ['post', 'page']
        );
    }

    /**
     * Rebuild the entire AIKO index.
     */
    public static function rebuild(): void
    {
        $documents = [];

        $posts = get_posts([
            'post_type'        => self::supportedPostTypes(),
            'post_status'      => 'publish',
            'posts_per_page'   => -1,
            'orderby'          => 'modified',
            'order'            => 'DESC',
            'no_found_rows'    => true,
            'suppress_filters' => true
        ]);

        foreach ($posts as $post) {

            $document = self::buildDocument($post);

            if (!empty($document)) {
                $documents[] = $document;
            }
        }

        self::publish($documents);
    }

    /**
     * Build one AIKO document
     */
    private static function buildDocument(WP_Post $post): array
    {
        // Only publish public content
        if ($post->post_status !== 'publish') {
            return [];
        }

        $html = (string) $post->post_content;

        // Parse HTML
        libxml_use_internal_errors(true);

        $dom = new DOMDocument();

        $dom->loadHTML(
            '<?xml encoding="utf-8" ?>' . $html,
            LIBXML_HTML_NOIMPLIED |
            LIBXML_HTML_NODEFDTD |
            LIBXML_NOERROR |
            LIBXML_NOWARNING
        );

        libxml_clear_errors();

        // Build document
        $document = [
            /*
             * Basic
             */
            'title' => html_entity_decode(
                get_the_title($post),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            ),

            'description' => self::description($post),

            'permalink' => $post->post_name,

            'language' => get_bloginfo('language'),

            'lastUpdated' => get_post_modified_time(
                DATE_ATOM,
                true,
                $post
            ),

            /*
             * Structure
             */
            'outline' => self::extractOutline($dom),

            /*
             * Semantic capabilities
             */
            'capabilities' => self::detectCapabilities($dom),

            /*
             * Reserved for future schema
             */
            'schema' => [
                'schema-version' => self::SCHEMA_VERSION
            ]

        ];

        return apply_filters(
            'aiko_document',
            $document,
            $post
        );
    }

    private static function levelOf(string $tag): int
    {
        return match ($tag) {
            'h1' => 1,
            'h2' => 2,
            'h3' => 3,

            // semantic blocks fallback
            'section' => 2,
            'article' => 2,
            'header' => 2,
            'canvas' => 3,

            default => 3,
        };
    }

    private static function nodeText(DOMNode $node): string
    {
        return trim(
            preg_replace('/\s+/', ' ', $node->textContent ?? '')
        );
    }

    private static function semanticId(DOMNode $node): string
    {
        if ($node->hasAttributes()) {

            $id = $node->attributes->getNamedItem('id');
            if ($id && $id->nodeValue) {
                return $id->nodeValue;
            }

            $data = $node->attributes->getNamedItem('data-id');
            if ($data && $data->nodeValue) {
                return $data->nodeValue;
            }
        }

        // fallback: generate stable hash
        return substr(
            sha1($node->nodeName . $node->textContent),
            0,
            10
        );
    }

    /**
     * Extract document outline (H1/H2/H3 + semantic blocks)
     *
     * Output format:
     * [
     *   ['level' => 1, 'type' => 'h1', 'text' => '...'],
     *   ['level' => 2, 'type' => 'h2', 'text' => '...'],
     *   ...
     * ]
     */
    private static function extractOutline(DOMDocument $dom): array
    {
        $xpath = new DOMXPath($dom);

        // We only care about structural + semantic anchors
        $nodes = $xpath->query(
            '//h1|//h2|//h3|//section|//canvas|//article|//header'
        );

        $outline = [];

        if (!$nodes) {
            return $outline;
        }

        foreach ($nodes as $node) {

            $tag = strtolower($node->nodeName);

            $text = trim(self::nodeText($node));

            if ($text === '') {
                continue;
            }

            $outline[] = [
                'type' => $tag,
                'level' => self::levelOf($tag),
                'text' => $text,
                'id' => self::semanticId($node)
            ];
        }

        return $outline;
    }

    /**
     * Detect document capabilities (AI understanding layer)
     *
     * Returns:
     * [
     *   "math",
     *   "code",
     *   "mermaid",
     *   "canvas",
     *   "faq",
     *   ...
     * ]
     */
    private static function detectCapabilities(DOMDocument $dom): array
    {
        $xpath = new DOMXPath($dom);

        $capabilities = [];

        /*
         * 1. Code blocks
         */
        if ($xpath->query('//pre//code')->length > 0 || $xpath->query('//code')->length > 0) {
            $capabilities[] = 'code';
        }

        /*
         * 2. Math (KaTeX / MathJax)
         */
        if ($xpath->query('//*[contains(@class,"math") or contains(@class,"katex") or contains(@class,"mjx")]')->length > 0) {
            $capabilities[] = 'math';
        }

        /*
         * 3. Mermaid diagrams
         */
        if ($xpath->query('//*[contains(@class,"mermaid")]')->length > 0) {
            $capabilities[] = 'mermaid';
        }

        /*
         * 4. Canvas / WebGL / interactive graphics
         */
        if ($xpath->query('//canvas')->length > 0) {
            $capabilities[] = 'canvas';
        }

        /*
         * 5. SVG graphics (diagrams, charts)
         */
        if ($xpath->query('//svg')->length > 0) {
            $capabilities[] = 'svg';
        }

        /*
         * 6. Tables (structured data)
         */
        if ($xpath->query('//table')->length > 0) {
            $capabilities[] = 'table';
        }

        /*
         * 7. Video embeds
         */
        if (
            $xpath->query('//iframe[contains(@src,"youtube")]')->length > 0 ||
            $xpath->query('//video')->length > 0
        ) {
            $capabilities[] = 'video';
        }

        /*
         * 8. FAQ structured content (simple heuristic)
         */
        if (
            $xpath->query('//*[contains(@class,"faq")]')->length > 0 ||
            $xpath->query('//dl')->length > 0
        ) {
            $capabilities[] = 'faq';
        }

        /*
         * 9. Lists (structured enumeration content)
         */
        if ($xpath->query('//ul|//ol')->length > 0) {
            $capabilities[] = 'list';
        }

        /*
         * 10. Remove duplicates
         */
        return array_values(array_unique($capabilities));
    }

    private static function isUsefulClass(string $class): bool
    {
        static $blacklist = [

            '',
            'container',
            'row',
            'col',
            'clearfix',

            'hidden',
            'active',
            'selected',

            'elementor-widget',
            'elementor-container',

            'wp-block-group',
            'wp-block-columns',
            'wp-block-column',

            'alignleft',
            'alignright',
            'aligncenter',

            'entry-content',
            'post-content'
        ];

        return !in_array($class, $blacklist, true);
    }

    /**
     * Extract semantic hints from a DOM node.
     */
    private static function semanticOf(DOMElement $node): array
    {
        $semantic = [];

        /*
         * id
         */
        if ($node->hasAttribute('id')) {

            $id = trim($node->getAttribute('id'));

            if ($id !== '') {
                $semantic[] = strtolower($id);
            }
        }

        /*
         * aria-label
         */
        if ($node->hasAttribute('aria-label')) {

            $label = trim($node->getAttribute('aria-label'));

            if ($label !== '') {
                $semantic[] = strtolower($label);
            }
        }

        /*
         * title
         */
        if ($node->hasAttribute('title')) {

            $title = trim($node->getAttribute('title'));

            if ($title !== '') {
                $semantic[] = strtolower($title);
            }
        }

        /*
         * data-title
         */
        if ($node->hasAttribute('data-title')) {

            $title = trim($node->getAttribute('data-title'));

            if ($title !== '') {
                $semantic[] = strtolower($title);
            }
        }

        /*
         * useful classes
         */
        if ($node->hasAttribute('class')) {

            $classes = preg_split(
                '/\s+/',
                $node->getAttribute('class')
            );

            foreach ($classes as $class) {

                $class = strtolower(trim($class));

                if (self::isUsefulClass($class)) {
                    $semantic[] = $class;
                }
            }
        }

        return array_values(array_unique($semantic));
    }

    /**
     * Generate document description.
     *
     * Priority:
     *
     * 1. SEO Meta Description
     * 2. Excerpt
     * 3. First paragraph
     */
    private static function description(WP_Post $post): string
    {
        /*
         * RankMath
         */
        $description = get_post_meta(
            $post->ID,
            'rank_math_description',
            true
        );

        /*
         * Yoast
         */
        if (empty($description)) {

            $description = get_post_meta(
                $post->ID,
                '_yoast_wpseo_metadesc',
                true
            );
        }

        /*
         * Excerpt
         */
        if (empty($description)) {

            $description = $post->post_excerpt;
        }

        /*
         * First paragraph
         */
        if (empty($description)) {

            $text = wp_strip_all_tags($post->post_content);

            $text = preg_replace('/\s+/', ' ', $text);

            $parts = preg_split('/(?<=[.!?。！？])\s+/u', $text);

            $description = $parts[0] ?? '';
        }

        return trim($description);
    }

    /**
     * Publish AIKO JSON file.
     *
     * Output:
     * /.well-known/aiko.json
     */
    private static function publish(array $documents): void
    {
        $data = [

            'schema-version' => self::SCHEMA_VERSION,

            'generated' => gmdate(
                DATE_ATOM
            ),

            'site' => [

                'name' => get_bloginfo('name'),

                'description' => get_bloginfo('description'),

                'url' => home_url('/')

            ],

            'documents' => $documents

        ];


        $json = wp_json_encode(
            $data,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        );


        if ($json === false) {
            return;
        }


        /*
         * /.well-known location
         */
        $directory = ABSPATH . '.well-known';


        if (!is_dir($directory)) {

            wp_mkdir_p($directory);

        }


        $file = $directory . '/aiko.json';


        /*
         * Atomic write
         *
         * Write temp file first,
         * then replace.
         */
        $temp = $file . '.tmp';


        file_put_contents(
            $temp,
            $json,
            LOCK_EX
        );


        rename(
            $temp,
            $file
        );
    }

    /**
     * Admin Menu
     */
    public static function adminMenu()
    {
        // TODO
    }
}

AIKO::init();
