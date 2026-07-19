<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Provider\Adapter;

/**
 * Referenzimplementierung des Adapter-Vertrags (Bauplan Abschnitt 6): ausgereiftes
 * Function-Calling, breite Modellauswahl. listModels() fragt live /v1/models ab, damit die
 * Admin-Oberflaeche nie eine veraltete, hartkodierte Modell-Liste zeigt.
 */
final class OpenAiProvider extends AbstractOpenAiFormatProvider
{
    public function getKey(): string
    {
        return 'openai';
    }

    public function getLabel(): string
    {
        return 'OpenAI';
    }

    protected function baseUrl(): string
    {
        return 'https://api.openai.com/v1';
    }
}
