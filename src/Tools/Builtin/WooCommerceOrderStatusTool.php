<?php

declare(strict_types=1);

namespace WPAiSuite\Tools\Builtin;

use WPAiSuite\Tools\Contract\ToolExecutionContext;
use WPAiSuite\Tools\Contract\ToolInterface;
use WPAiSuite\Tools\Contract\ToolResult;

/**
 * Verbesserung Punkt 9: Bestellstatus-Abfrage fuer eingeloggte Kunden — analog zu
 * WooCommerceProductSearchTool ein read-only wc_get_orders()/wc_get_order()-Wrapper.
 *
 * WICHTIG zur Kundenbindung: ToolInterface::execute(array $arguments) bekommt (Bauplan
 * Abschnitt 8, Phase-1-Vertrag) bewusst KEINEN ToolExecutionContext — nur isAllowedFor() sieht
 * $context->wpUserId. Diese Klasse aendert diesen Kern-Contract NICHT (er wird laut
 * ToolExecutionContext-Docblock 1:1 zum Phase-2-MCP-Tool-Vertrag), sondern bekommt $wpUserId wie
 * KnowledgeSearchTool seinen RagService PER REQUEST in den Konstruktor gebunden (siehe
 * ChatController, wo fuer jede Chat-Anfrage eine frische Tool-Liste gebaut wird). $wpUserId
 * stammt dort aus derselben get_current_user_id()-Aufloesung, die auch
 * ConversationService::buildToolContext() fuer $context->wpUserId verwendet (ueber
 * $conversation->wpUserId) — innerhalb einer Anfrage dieselbe Identitaet.
 *
 * Jede Bestellabfrage (Liste wie Einzelabfrage per order_number) filtert IMMER zusaetzlich nach
 * genau diesem wpUserId — ein Kunde kann so nie eine fremde Bestellnummer erraten und deren
 * Daten abfragen, selbst wenn das Modell eine beliebige Nummer als Parameter uebergibt.
 *
 * Bewusst NICHT WP-Bootstrap-frei (wc_get_orders()) — wie WooCommerceProductSearchTool nur
 * integrationstestbar, nicht per Pest-Unit.
 */
final class WooCommerceOrderStatusTool implements ToolInterface
{
    private const DEFAULT_LIMIT = 5;

    public function __construct(
        private readonly ?int $wpUserId,
    ) {
    }

    public function getName(): string
    {
        return 'woocommerce_order_status';
    }

    public function getDescription(): string
    {
        return 'Zeigt die eigenen WooCommerce-Bestellungen des gerade eingeloggten Kunden '
            . '(Status, Datum, Summe). Ohne "order_number": die letzten Bestellungen. Mit '
            . '"order_number": Details zu genau dieser einen Bestellung — aber nur, wenn sie '
            . 'dem aktuell eingeloggten Kunden gehoert. Nur lesend, kein Zugriff auf fremde Bestellungen.';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_number' => [
                    'type' => 'string',
                    'description' => 'Optionale Bestellnummer, um genau eine Bestellung nachzuschlagen.',
                ],
            ],
        ];
    }

    public function execute(array $arguments): ToolResult
    {
        if (!function_exists('wc_get_orders')) {
            return new ToolResult(success: false, error: 'WooCommerce ist auf dieser Website nicht aktiv.');
        }

        if ($this->wpUserId === null) {
            // Verteidigung in der Tiefe: isAllowedFor() sollte das schon verhindert haben.
            return new ToolResult(success: false, error: 'Dieses Tool steht nur eingeloggten Kunden zur Verfuegung.');
        }

        $orderNumber = trim((string) ($arguments['order_number'] ?? ''));

        if ($orderNumber !== '') {
            $order = wc_get_order((int) $orderNumber);

            if (
                $order === false
                || $order === null
                || (int) $order->get_customer_id() !== $this->wpUserId
            ) {
                // Bewusst dieselbe Fehlermeldung fuer "existiert nicht" und "gehoert jemand
                // anderem" — sonst liesse sich am Unterschied erraten, welche Bestellnummern
                // bei anderen Kunden ueberhaupt existieren.
                return new ToolResult(success: false, error: 'Keine Bestellung mit dieser Nummer gefunden.');
            }

            return new ToolResult(success: true, data: ['order' => $this->describeOrder($order)]);
        }

        $orders = wc_get_orders([
            'customer' => $this->wpUserId,
            'limit' => self::DEFAULT_LIMIT,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        return new ToolResult(success: true, data: [
            'orders' => array_map([$this, 'describeOrder'], $orders),
        ]);
    }

    public function isAllowedFor(ToolExecutionContext $context): bool
    {
        return $context->isLoggedIn && function_exists('wc_get_orders');
    }

    /** @return array<string,mixed> */
    private function describeOrder(\WC_Order $order): array
    {
        return [
            'order_number' => $order->get_order_number(),
            'status' => $order->get_status(),
            'date' => $order->get_date_created()?->date('Y-m-d') ?? '',
            'total' => $order->get_total(),
            'currency' => $order->get_currency(),
        ];
    }
}
