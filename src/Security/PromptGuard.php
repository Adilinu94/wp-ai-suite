<?php

declare(strict_types=1);

namespace WPAiSuite\Security;

/**
 * Bauplan Abschnitt 9: "PromptGuard: einfache Heuristik gegen bekannte Jailbreak-Phrasen".
 * AUSDRUECKLICH eine ZUSAETZLICHE Filterschicht, nicht die primaere Absicherung — die eigentliche
 * Rollentrennung (User-Input landet nie in der system-Rolle, Tool-Ergebnisse immer als
 * tool-Rolle) ist strukturell durch SystemPromptBuilder (M2) und den Tool-Loop (M7) bereits
 * gegeben und wuerde auch ohne PromptGuard halten. Eine Wortlisten-Heuristik kann grundsaetzlich
 * sowohl falsch-negativ (neue, hier nicht gelistete Formulierung) als auch falsch-positiv sein
 * (legitime Frage ÜBER Jailbreaks, z.B. journalistische/Forschungsanfragen) — bewusst eher
 * konservativ gehalten (spezifische Phrasen statt einzelner Schlagworte wie "ignorieren" oder
 * "Anweisungen" allein), um harmlose Nachrichten nicht unnoetig zu blockieren.
 *
 * WP-frei (reiner String-Vergleich), dadurch unit-testbar ohne WP-Bootstrap.
 */
final class PromptGuard
{
    /**
     * Deutsch UND Englisch, da der Chat auf deutschsprachigen Websites eingebettet wird, aber
     * jeder Besucher (auch potenzielle Angreifer) in jeder Sprache schreiben kann. Regex statt
     * reinem str_contains(): erlaubt etwas Flexibilitaet bei Leerzeichen/Wortstellung, ohne so
     * weit zu fassen, dass einzelne haeufige Woerter allein schon ausloesen.
     *
     * @var string[]
     */
    private const PATTERNS = [
        '/ignor(e|iere)\s+(all(e)?\s+)?(previous|vorherige[n]?|bisherige[n]?)\s+(instructions|anweisungen)/i',
        '/ignor(e|iere)\s+(deinen|your)\s+system[ -]?prompt/i',
        '/disregard\s+(your|the)\s+(system[ -]?prompt|instructions|guidelines)/i',
        '/vergiss\s+(deine|alle)\s+(anweisungen|regeln|vorgaben)/i',
        '/(reveal|show|repeat|print|zeig(e)?|verrate)\s+(mir\s+)?(your|deinen|den)\s+system[ -]?prompt/i',
        '/what(\'|’)?s\s+your\s+system\s+prompt/i',
        '/wie\s+lautet\s+dein\s+system[ -]?prompt/i',
        '/\bDAN\s+mode\b/i',
        '/\byou\s+are\s+now\s+DAN\b/i',
        '/\bdo\s+anything\s+now\b/i',
        '/\b(entwickler|developer)[ -]?mod(e|us)\b/i',
        '/\bjailbreak\b/i',
        '/act\s+as\s+if\s+you\s+(have\s+no|had\s+no)\s+(restrictions|guidelines|rules)/i',
        '/pretend\s+you\s+(have\s+no|are\s+not)\s+(restrictions|an\s+ai|guidelines)/i',
        '/tu\s+so,?\s+als\s+(ob\s+)?(du\s+)?keine\s+(regeln|einschraenkungen|vorgaben)/i',
    ];

    /** true = mindestens ein bekanntes Jailbreak-Muster erkannt. */
    public function isSuspicious(string $userMessage): bool
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $userMessage) === 1) {
                return true;
            }
        }

        return false;
    }
}
