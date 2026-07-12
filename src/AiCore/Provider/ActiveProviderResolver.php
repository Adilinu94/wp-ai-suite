<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Provider;

/**
 * Bruecke zwischen WP-Options (wpais_active_provider, wpais_custom_*, wpais_default_model_*) und
 * einer konkreten, einsatzbereiten AiProviderInterface-Instanz. Bewusst NICHT Unit-testbar ohne
 * WP-Bootstrap (ruft get_option() direkt auf) — das ist hier Absicht: diese Klasse IST die
 * WP-Beruehrungspunkt-Seite der Aufloesung, analog zu ApiKeyVault::fromWpConfigConstant() in M1.
 * ConversationService selbst bleibt dadurch weiterhin WP-frei und unit-testbar (Abschnitt 14).
 */
final class ActiveProviderResolver
{
    public function __construct(
        private readonly ProviderFactory $factory,
    ) {
    }

    /**
     * @return array{0: Contract\AiProviderInterface, 1: string} [Provider-Instanz, Standard-Modell]
     * @throws NoActiveProviderException Kein Provider gewaehlt, kein Key hinterlegt, oder kein
     *         Standard-Modell konfiguriert.
     */
    public function resolve(): array
    {
        $activeKey = (string) get_option('wpais_active_provider', '');

        if ($activeKey === '') {
            throw new NoActiveProviderException(
                __('Kein aktiver Provider konfiguriert (WP AI Suite > Einstellungen).', 'wp-ai-suite'),
            );
        }

        $customConfig = [];
        if ($activeKey === 'custom') {
            $customConfig = [
                'label' => (string) get_option('wpais_custom_label', 'Custom'),
                'base_url' => (string) get_option('wpais_custom_base_url', ''),
            ];
        }

        $provider = $this->factory->make($activeKey, $customConfig);

        if ($provider === null) {
            throw new NoActiveProviderException(sprintf(
                /* translators: %s: provider key, e.g. "openai" */
                __('Fuer den aktiven Provider "%s" ist kein API-Key hinterlegt (WP AI Suite > Einstellungen).', 'wp-ai-suite'),
                $activeKey,
            ));
        }

        $model = (string) get_option('wpais_default_model_' . $activeKey, '');

        if ($model === '') {
            throw new NoActiveProviderException(sprintf(
                /* translators: %s: provider key, e.g. "openai" */
                __('Fuer den aktiven Provider "%s" ist kein Standard-Modell hinterlegt (WP AI Suite > Einstellungen).', 'wp-ai-suite'),
                $activeKey,
            ));
        }

        return [$provider, $model];
    }
}
