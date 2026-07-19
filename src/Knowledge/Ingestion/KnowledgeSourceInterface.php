<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\Ingestion;

/**
 * Port fuer Wissensquellen (Bauplan Abschnitt 5). Phase-1-Quellen laut Abschnitt 7:
 * WordPressContentSource (M4, dieser Meilenstein), PdfSource + FaqSource/custom_text (M6).
 * WooCommerce-Produkte laufen bewusst NICHT hierueber, sondern ueber
 * WooCommerceProductSearchTool (Abschnitt 8, M7) — strukturierte/live Daten gehoeren in ein Tool,
 * nicht in den Vektor-Index.
 */
interface KnowledgeSourceInterface
{
    /** wp_content|pdf|faq|custom_text|woocommerce_product */
    public function getType(): string;

    /** @return iterable<RawDocument> */
    public function fetch(): iterable;
}
