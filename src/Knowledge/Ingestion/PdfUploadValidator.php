<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\Ingestion;

/**
 * Umbauplan Post-MVP Punkt 8: serverseitige Validierung VOR der Ingestion (bisher nur
 * clientseitiges accept="application/pdf" im Upload-Formular — trivial umgehbar/wirkungslos bei
 * REST-Uploads ohne Formular). Prueft Dateiendung, MIME-Typ (echter Dateiinhalt via finfo, nicht
 * der vom Client behauptete Content-Type) und optional eine Maximalgroesse.
 *
 * Bewusst eine eigene Klasse ohne UI-Bezug, von KnowledgeBasePage UND
 * DocumentsController::resolvePdfSource gemeinsam genutzt — dieselbe Pruefung fuer den
 * Admin-Formular- und den REST-Upload-Pfad statt sie zweimal separat zu implementieren.
 *
 * WP-frei bis auf reine PHP-Funktionen (finfo, Dateisystem) — kein WordPress-Bootstrap noetig,
 * daher mit echten temporaeren Dateien unit-testbar statt nur mit Fakes.
 */
final class PdfUploadValidator
{
    private const DEFAULT_MAX_BYTES = 20 * 1024 * 1024; // 20 MB

    /**
     * @param array{name?: string, tmp_name?: string, size?: int} $file Wie ein Eintrag aus
     *        $_FILES, oder ein aequivalentes Array (z.B. beim REST-Pfad aus attachment_ids
     *        rekonstruiert — siehe DocumentsController).
     *
     * @return string|null Fehlermeldung (deutsch, fertig zur Anzeige) oder null wenn gueltig.
     */
    public function validate(array $file, int $maxBytes = self::DEFAULT_MAX_BYTES): ?string
    {
        $name = (string) ($file['name'] ?? '');
        $tmpName = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        if (!str_ends_with(strtolower($name), '.pdf')) {
            return __('Nur .pdf-Dateien sind erlaubt.', 'wp-ai-suite');
        }

        if ($maxBytes > 0 && $size > $maxBytes) {
            return sprintf(
                /* translators: 1: tatsaechliche Groesse in MB, 2: erlaubte Maximalgroesse in MB */
                __('Datei ist zu gross (%1$s MB, erlaubt sind maximal %2$s MB).', 'wp-ai-suite'),
                number_format($size / 1024 / 1024, 1),
                number_format($maxBytes / 1024 / 1024, 1),
            );
        }

        $detectedMime = $this->detectMimeType($tmpName);

        // Kein finfo verfuegbar oder Datei (noch) nicht lesbar: Endungspruefung oben reicht dann
        // als einzige Absicherung, statt gueltige Uploads an einer Umgebungsluecke scheitern zu
        // lassen — konsistent mit dem "graceful statt hart blockieren"-Prinzip aus M6/M9.
        if ($detectedMime !== null && $detectedMime !== 'application/pdf') {
            return sprintf(
                /* translators: %s: der tatsaechlich erkannte Dateityp */
                __('Datei ist kein echtes PDF (erkannter Typ: %s).', 'wp-ai-suite'),
                $detectedMime,
            );
        }

        return null;
    }

    private function detectMimeType(string $path): ?string
    {
        if ($path === '' || !is_readable($path) || !function_exists('finfo_open')) {
            return null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return $mime !== false ? $mime : null;
    }
}
