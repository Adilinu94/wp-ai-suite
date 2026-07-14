<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge;

/**
 * Eine Wissensbasis-Quelle, die zur Beantwortung beigetragen hat — wird von ChatController in
 * das "sources"-SSE-Event uebersetzt (Bauplan Abschnitt 15, M5-DoD: "Quellen werden im Chat
 * angezeigt"). sourceRef ist bei source_type="wp_content" die Post-ID (siehe
 * WordPressContentSource) — ChatController loest daraus bei Bedarf einen echten Permalink auf.
 */
final class RetrievedSource
{
    public function __construct(
        public readonly int $documentId,
        public readonly string $title,
        public readonly string $sourceType,
        public readonly ?string $sourceRef,
    ) {
    }
}
