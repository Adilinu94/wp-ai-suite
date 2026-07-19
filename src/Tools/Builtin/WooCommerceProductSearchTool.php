<?php

declare(strict_types=1);

namespace WPAiSuite\Tools\Builtin;

use WPAiSuite\Tools\Contract\ToolExecutionContext;
use WPAiSuite\Tools\Contract\ToolInterface;
use WPAiSuite\Tools\Contract\ToolResult;

/**
 * Bauplan Abschnitt 8: "WooCommerceProductSearchTool { wc_get_products() Wrapper, read-only }".
 * Bauplan Abschnitt 7 begruendet ausdruecklich, warum das ein Tool ist und KEINE RAG-Quelle:
 * "Produktdaten sind strukturiert und live (Preis/Lager), das gehoert in ein Tool, nicht in den
 * Vektor-Index" — ein Preis von vor einer Stunde waere im Vektor-Index laengst veraltet, ein
 * Tool-Aufruf fragt dagegen bei jeder Nutzung live ab.
 *
 * Bewusst NICHT WP-Bootstrap-frei (wc_get_products()/get_permalink() sind echte WP/WooCommerce-
 * Funktionen) — anders als KnowledgeSearchTool nicht unit-, sondern nur integrationstestbar
 * (siehe FORTSETZUNG.md, analog zu WordPressContentSource in M4). Read-only im Wortsinn: kein
 * einziger schreibender WooCommerce-Aufruf in dieser Klasse.
 */
final class WooCommerceProductSearchTool implements ToolInterface
{
    private const DEFAULT_LIMIT = 5;
    private const MAX_LIMIT = 20;

    public function getName(): string
    {
        return 'woocommerce_product_search';
    }

    public function getDescription(): string
    {
        return 'Durchsucht die WooCommerce-Produkte dieser Website nach Name oder Stichwort und '
            . 'liefert aktuellen Preis, Lagerstatus und Produkt-URL. Nur lesend, keine Bestellungen o.ae.';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => [
                    'type' => 'string',
                    'description' => 'Suchbegriff, z.B. ein Produktname oder Stichwort.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximale Anzahl Ergebnisse (Standard 5, maximal 20).',
                ],
            ],
            'required' => ['search'],
        ];
    }

    public function execute(array $arguments): ToolResult
    {
        if (!function_exists('wc_get_products')) {
            return new ToolResult(success: false, error: 'WooCommerce ist auf dieser Website nicht aktiv.');
        }

        $search = trim((string) ($arguments['search'] ?? ''));

        if ($search === '') {
            return new ToolResult(success: false, error: 'Parameter "search" fehlt oder ist leer.');
        }

        $limit = (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $products = wc_get_products(['s' => $search, 'limit' => $limit, 'status' => 'publish']);

        return new ToolResult(success: true, data: [
            'products' => array_map(static function ($product): array {
                return [
                    'name' => $product->get_name(),
                    'price' => $product->get_price(),
                    'in_stock' => $product->is_in_stock(),
                    'url' => get_permalink($product->get_id()),
                ];
            }, $products),
        ]);
    }

    public function isAllowedFor(ToolExecutionContext $context): bool
    {
        // Oeffentlich wie der Produktkatalog selbst (jeder Website-Besucher sieht die
        // Produktseiten auch ohne Login) — keine zusaetzliche Einschraenkung in Phase 1.
        return true;
    }
}
