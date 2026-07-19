<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Provider\Contract;

/**
 * Basis-Exception fuer alle Fehler, die eine AiProviderInterface-Implementierung wirft
 * (Netzwerkfehler, HTTP-4xx/5xx vom Provider, nicht dekodierbare Antworten). Nicht im Bauplan
 * namentlich vorgegeben, aber noetig, damit Aufrufer (Conversation Engine, ab M2) einen
 * providerunabhaengigen Fehler abfangen koennen — Teil des "einfachste Loesung, die den Contract
 * erfuellt"-Prinzips aus Abschnitt "Wie dieses Dokument zu benutzen ist", Regel 2.
 */
class ProviderException extends \RuntimeException
{
}
