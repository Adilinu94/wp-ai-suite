<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Provider\Contract;

/**
 * Wird geworfen, wenn eine laut AiProviderInterface vorhandene Methode von einem konkreten
 * Provider fachlich nicht unterstuetzt werden kann (z.B. AnthropicProvider::embed() — Anthropic
 * bietet keine eigene Embeddings-API, siehe Bauplan Abschnitt 7). Bewusst eine eigene Klasse statt
 * generischer ProviderException, damit Aufrufer diesen Fall gezielt abfangen und z.B. auf einen
 * anderen konfigurierten Provider ausweichen koennen.
 */
final class UnsupportedCapabilityException extends ProviderException
{
}
