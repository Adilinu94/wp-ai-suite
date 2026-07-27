<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\Ingestion;

/**
 * Bauplan Abschnitt 7: "WordPressContentSource (Posts/Pages via WP_Query)". Einzige in M4
 * gebaute Quelle — PdfSource und FaqSource/custom_text sind laut Definition of Done (Abschnitt
 * 15) M6-Scope ("PDF/FAQ-Ingestion").
 *
 * source_ref = Post-ID (Abschnitt 4: "source_ref VARCHAR(255) NULL, -- post_id oder Dateipfad").
 * Inhalt wird durch die the_content-Filterkette gejagt (loest Shortcodes/Bloecke auf) und dann von
 * HTML befreit, bevor er an den Chunker geht — RAG braucht Lesetext, keine Block-Kommentare/Markup.
 */
final class WordPressContentSource implements KnowledgeSourceInterface
{
    /** @var string[] Wiederverwendet von AutoSyncOnSaveListener, um denselben Default zu treffen. */
    public const DEFAULT_POST_TYPES = ['post', 'page'];

    /**
     * @param string[] $postTypes
     * @param int[]|null $postIds Verbesserung Punkt 2: wenn gesetzt, werden NUR diese Posts
     *     geholt statt aller — genutzt vom save_post-Listener fuer den Einzelpost-Resync. null
     *     (Default) bleibt das bisherige Verhalten (kompletter Re-Sync ueber "Neu indexieren").
     */
    public function __construct(
        private readonly array $postTypes = self::DEFAULT_POST_TYPES,
        private readonly ?array $postIds = null,
    ) {
    }

    public function getType(): string
    {
        return 'wp_content';
    }

    public function fetch(): iterable
    {
        $args = [
            'post_type' => $this->postTypes,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'no_found_rows' => true,
            'orderby' => 'ID',
            'order' => 'ASC',
        ];

        if ($this->postIds !== null) {
            $args['post__in'] = $this->postIds === [] ? [0] : $this->postIds;
        }

        $query = new \WP_Query($args);

        foreach ($query->posts as $post) {
            $rendered = apply_filters('the_content', $post->post_content);
            $plainText = trim((string) preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $rendered)));

            if ($plainText === '') {
                continue;
            }

            yield new RawDocument(
                sourceType: $this->getType(),
                sourceRef: (string) $post->ID,
                title: get_the_title($post),
                content: $plainText,
            );
        }

        wp_reset_postdata();
    }
}
