<?php

declare(strict_types=1);

namespace WPAiSuite\Admin\Pages;

use WPAiSuite\Admin\UsageCostEstimator;
use WPAiSuite\AiCore\Conversation\Repository\ConversationRepositoryInterface;

/**
 * Bauplan Abschnitt 11 (M10): "Logs: einfache Tabelle aus wpais_usage_logs (Tokens, Provider,
 * Zeitpunkt) — Kostenschätzung als simple Multiplikation, keine Abrechnungslogik nötig (BYOK)."
 *
 * Kostenschaetzung selbst liegt in UsageCostEstimator (WP-frei, unit-testbar) — dieselbe
 * Trennung wie ueberall sonst im Plugin: reine Rechenlogik raus aus der WP-gekoppelten
 * Admin-Seite.
 */
final class UsageLogsPage
{
    private const CAPABILITY = 'manage_options';

    public function __construct(
        private readonly ConversationRepositoryInterface $conversations,
        private readonly UsageCostEstimator $costEstimator,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page(
                'wpais-settings',
                __('Logs', 'wp-ai-suite'),
                __('Logs', 'wp-ai-suite'),
                self::CAPABILITY,
                'wpais-logs',
                [$this, 'renderPage'],
            );
        });
    }

    public function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'wp-ai-suite'));
        }

        $logs = $this->conversations->listUsageLogs();

        echo '<div class="wrap"><h1>' . esc_html__('Nutzungs-Logs', 'wp-ai-suite') . '</h1>';
        echo '<p class="description">' . esc_html__('Kostenschätzung ist eine grobe Näherung anhand hinterlegter Richtpreise, keine echte Abrechnung (BYOK — du zahlst direkt beim jeweiligen Anbieter).', 'wp-ai-suite') . '</p>';

        if ($logs === []) {
            echo '<p>' . esc_html__('Noch keine Nutzung erfasst.', 'wp-ai-suite') . '</p></div>';

            return;
        }

        $this->renderTotals($logs);
        $this->renderTable($logs);

        echo '</div>';
    }

    /** @param array<int, array{provider:string, model:string, tokens_input:int, tokens_output:int, created_at:string}> $logs */
    private function renderTotals(array $logs): void
    {
        $totalInput = array_sum(array_column($logs, 'tokens_input'));
        $totalOutput = array_sum(array_column($logs, 'tokens_output'));
        $totalCost = 0.0;

        foreach ($logs as $log) {
            $totalCost += $this->costEstimator->estimate($log['provider'], $log['tokens_input'], $log['tokens_output']);
        }

        printf(
            '<p><strong>%s:</strong> %s Tokens (Input) / %s Tokens (Output) — %s ~%s USD</p>',
            esc_html__('Gesamt', 'wp-ai-suite'),
            esc_html(number_format_i18n($totalInput)),
            esc_html(number_format_i18n($totalOutput)),
            esc_html__('geschätzt', 'wp-ai-suite'),
            esc_html(number_format($totalCost, 2)),
        );
    }

    /** @param array<int, array{provider:string, model:string, tokens_input:int, tokens_output:int, created_at:string}> $logs */
    private function renderTable(array $logs): void
    {
        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        foreach ([
            __('Zeitpunkt', 'wp-ai-suite'),
            __('Provider', 'wp-ai-suite'),
            __('Modell', 'wp-ai-suite'),
            __('Tokens (Input)', 'wp-ai-suite'),
            __('Tokens (Output)', 'wp-ai-suite'),
            __('Geschätzte Kosten (USD)', 'wp-ai-suite'),
        ] as $column) {
            echo '<th>' . esc_html($column) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($logs as $log) {
            $cost = $this->costEstimator->estimate($log['provider'], $log['tokens_input'], $log['tokens_output']);
            echo '<tr>';
            echo '<td>' . esc_html($log['created_at']) . '</td>';
            echo '<td>' . esc_html($log['provider']) . '</td>';
            echo '<td>' . esc_html($log['model']) . '</td>';
            echo '<td>' . esc_html(number_format_i18n($log['tokens_input'])) . '</td>';
            echo '<td>' . esc_html(number_format_i18n($log['tokens_output'])) . '</td>';
            echo '<td>' . esc_html(number_format($cost, 4)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }
}
