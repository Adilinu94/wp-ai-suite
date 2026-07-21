<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\Ingestion;

/**
 * Reference implementation of PdfTextExtractorInterface (M6) via smalot/pdfparser.
 * Prefer the Strauss-scoped class (WPAiSuite\Vendor\...) so the plugin never collides with
 * another copy of smalot on the same site; fall back to the unscoped class when vendor-scoped
 * was not generated yet (local composer without prefix step).
 */
final class SmalotPdfTextExtractor implements PdfTextExtractorInterface
{
    public function extract(string $filePath): string
    {
        if ($filePath === '' || !is_readable($filePath)) {
            throw new PdfExtractionException(sprintf(
                /* translators: %s: absolute file path of the unreadable PDF */
                __('PDF-Datei nicht lesbar: %s', 'wp-ai-suite'),
                $filePath === '' ? '(kein Pfad)' : $filePath,
            ));
        }

        try {
            $parserClass = $this->resolveParserClass();
            $parser = new $parserClass();
            $document = $parser->parseFile($filePath);

            return $document->getText();
        } catch (PdfExtractionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // smalot/pdfparser throws various exception types for corrupt/encrypted PDFs —
            // normalise to PdfExtractionException so PdfSource only handles one type.
            throw new PdfExtractionException(
                sprintf(
                    /* translators: 1: file path, 2: underlying error message from smalot/pdfparser */
                    __('PDF-Textextraktion fehlgeschlagen fuer %1$s: %2$s', 'wp-ai-suite'),
                    $filePath,
                    $e->getMessage(),
                ),
                previous: $e,
            );
        }
    }

    /**
     * @return class-string
     */
    private function resolveParserClass(): string
    {
        if (class_exists(\WPAiSuite\Vendor\Smalot\PdfParser\Parser::class)) {
            return \WPAiSuite\Vendor\Smalot\PdfParser\Parser::class;
        }

        if (class_exists(\Smalot\PdfParser\Parser::class)) {
            return \Smalot\PdfParser\Parser::class;
        }

        throw new PdfExtractionException(
            __('PDF-Parser nicht verfuegbar — composer install / prefix-namespaces ausfuehren.', 'wp-ai-suite'),
        );
    }
}
