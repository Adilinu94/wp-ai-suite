#!/bin/sh
# Umbauplan Post-MVP Punkt 10: reproduzierbarer Release-Build (M11-DoD).
#
# Voraussetzung: echtes Composer mit Packagist-Zugriff (z.B. auf solar.local) — in
# Sandbox-/CI-Umgebungen ohne Packagist-Netzwerkfreigabe schlaegt "composer install" fehl,
# siehe FORTSETZUNG.md "Bekannte Einschraenkungen". Muss auf dem committeten Stand laufen
# (git archive HEAD), also vor dem Bauen committen.
#
# Nutzung: ./bin/build-release.sh  (aus dem Repo-Root)
# Ergebnis: build/wp-ai-suite-<VERSION>.zip — installierbar per Plugin-Upload, ohne dass
# Composer auf dem Zielserver noetig ist.

set -eu

PLUGIN_SLUG="wp-ai-suite"
VERSION=$(grep -oE "Version:[[:space:]]+[0-9][^ ]*" wp-ai-suite.php | awk '{print $2}')
BUILD_ROOT="build"
STAGE="${BUILD_ROOT}/${PLUGIN_SLUG}"
ZIP_PATH="${BUILD_ROOT}/${PLUGIN_SLUG}-${VERSION}.zip"

echo "==> composer install --no-dev"
composer install --no-dev

echo "==> composer prefix-namespaces (Strauss)"
composer prefix-namespaces

echo "==> Verifiziere Strauss-Autoload-Bridge (vendor-scoped Smalot)"
php -r '
require __DIR__ . "/vendor/autoload.php";
if (!class_exists(\WPAiSuite\Vendor\Smalot\PdfParser\Parser::class)) {
    fwrite(STDERR, "FEHLER: WPAiSuite\\Vendor\\Smalot\\PdfParser\\Parser fehlt — Strauss-Prefixing fehlgeschlagen.\n");
    exit(1);
}
echo "OK: Strauss-Bridge vorhanden.\n";
'

echo "==> Staging-Verzeichnis aus dem committeten Stand aufbauen (git archive HEAD)"
rm -rf "${BUILD_ROOT}"
mkdir -p "${STAGE}"
git archive HEAD | (cd "${STAGE}" && tar -x)

echo "==> Dev-Only-Dateien aus dem Staging entfernen"
rm -rf "${STAGE}/tests" "${STAGE}/tests-js" "${STAGE}/phpunit.xml" "${STAGE}/bin/strauss.phar"

echo "==> vendor/ + vendor-scoped/ ergaenzen (nicht in Git, von Composer/Strauss erzeugt)"
cp -a vendor "${STAGE}/vendor"
if [ -d vendor-scoped ]; then
    cp -a vendor-scoped "${STAGE}/vendor-scoped"
fi

echo "==> ZIP erzeugen: ${ZIP_PATH}"
(cd "${BUILD_ROOT}" && zip -r -q "$(basename "${ZIP_PATH}")" "${PLUGIN_SLUG}")

echo "==> Fertig: ${ZIP_PATH}"
