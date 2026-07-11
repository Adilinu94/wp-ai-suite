<?php

declare(strict_types=1);

namespace WPAiSuite\Security;

/**
 * Fehler rund um Verschluesselung/Entschluesselung: fehlende/ungueltige Konstante in
 * wp-config.php, manipuliertes oder unlesbares Chiffrat.
 */
final class VaultException extends \RuntimeException
{
}
