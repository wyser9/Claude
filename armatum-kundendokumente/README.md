# Armatum Kundendokumente

Prüft bei Bestellungen mit Erwerbsbedingung (Attribut `pa_erwerbsbedingungen`), ob beim bexio-Kontakt des Kunden folgende Dateien liegen:

- Ausweiskopie, noch gültig
- Waffenerwerbsschein (max. 2 Jahre alt) **oder** Strafregisterauszug (max. 3 Monate alt)

Ergebnis: Kasten «Kundendokumente (bexio)» in der Bestellung, Spalte «Dokumente» in der Bestellliste, Bestellnotiz bei Statuswechsel. Fehlt etwas, kann im Kasten eine Erinnerungs-Mail (Text anpassbar) über den WooCommerce-Mailer an den Kunden gesendet werden.

## Ablauf
- Nach der Übertragung an bexio (`bws_order_synced`) und bei Status «In Bearbeitung»/«In Wartestellung» wird die Prüfung eingeplant.
- Dateiverzeichnis: stündlich `GET 3.0/files` und für neue Dateien einmal `GET 3.0/files/{id}/usage` (Zuordnung Datei → Kontakt). Nur lesend, bexio-Token aus dem bexio-Sync-Plugin.
- Erkennung über den Dateinamen (Ausweis/ID/Pass/Identitätskarte, WES/Waffenerwerbsschein, Strafregister, Jagdschein).
- Datum im Dateinamen: beim Ausweis = gültig bis, bei WES/Strafregister = Ausstelldatum. Ohne Datum: Ausweis «prüfen», WES/Strafregister nach Upload-Datum.

Einstellungsseite: WooCommerce → Kundendokumente (Verzeichnisstand, Kontakt testen).

## Tests
- `php tests/test-docs.php` (ohne WordPress)
- `wp eval-file wp-content/plugins/armatum-kundendokumente/tests/integration.php` (nur Testinstallation)
