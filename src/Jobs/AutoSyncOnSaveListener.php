<?php

declare(strict_types=1);

namespace WPAiSuite\Jobs;

use WPAiSuite\Knowledge\Ingestion\WordPressContentSource;

/**
 * Verbesserung Punkt 2 ("Automatische Re-Synchronisierung bei WP-Content-Aenderungen"): bisher
 * musste nach jeder Post-Aenderung manuell auf "Neu indexieren" geklickt werden (und das synct
 * dann noch dazu ALLE WP-Inhalte neu, siehe WordPressContentSource-Docblock). Dieser Listener
 * haengt sich stattdessen an `save_post` und synct NUR den einen geaenderten Post — ueber den in
 * WordPressContentSource neu ergaenzten $postIds-Filter.
 *
 * Bewusst ueber Action Scheduler eingeplant statt synchron im save_post-Request selbst
 * ausgefuehrt (falls verfuegbar): ein echter Chunking+Embedding-Aufruf mit Netzwerklatenz zum
 * Provider wuerde sonst JEDEN Beitrag-Speichern-Klick spuerbar verlangsamen — exakt der Grund,
 * aus dem Punkt 3 (Async-Ingestion) ueberhaupt existiert. Ohne AS: synchroner Fallback ueber
 * IngestionJob::handle() (dieselbe Methode, die auch der AS-Hook selbst aufruft), damit es
 * ueberhaupt einen Effekt hat statt den Post-Save nur zu ignorieren.
 *
 * Bewusst NICHT gebaut (ausserhalb des Punkt-2-Scopes "Re-Sync bei Aenderung"): kein
 * automatisches Entfernen aus der Wissensbasis, wenn ein bereits indexierter Post depubliziert
 * oder in den Papierkorb verschoben wird — das waere ein eigenes Feature ("Cleanup bei
 * Statuswechsel"), keine Re-Synchronisierung.
 *
 * WP-gebunden (WP_Post, add_action, wp_is_post_autosave/-revision) wie WordPressContentSource
 * selbst — nur manuell/per Integrationstest pruefbar, nicht per Pest-Unit.
 */
final class AutoSyncOnSaveListener
{
    public function __construct(
        private readonly IngestionJob $ingestionJob,
    ) {
    }

    public function register(): void
    {
        add_action('save_post', [$this, 'handle'], 10, 3);
    }

    public function handle(int $postId, \WP_Post $post, bool $update): void
    {
        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) {
            return;
        }

        if ($post->post_status !== 'publish') {
            return;
        }

        if (!in_array($post->post_type, WordPressContentSource::DEFAULT_POST_TYPES, true)) {
            return;
        }

        $source = new WordPressContentSource(postIds: [$postId]);
        $rawDocuments = iterator_to_array($source->fetch(), false);

        if ($rawDocuments === []) {
            // Leerer Inhalt (siehe WordPressContentSource::fetch()) — nichts zu synchronisieren.
            return;
        }

        $payload = IngestionJob::serialize($rawDocuments[0]);

        if (ActionSchedulerIngestionDispatcher::isAvailable()) {
            as_schedule_single_action(time(), ActionSchedulerIngestionDispatcher::HOOK, ['payload' => $payload], ActionSchedulerIngestionDispatcher::GROUP);

            return;
        }

        $this->ingestionJob->handle($payload);
    }
}
