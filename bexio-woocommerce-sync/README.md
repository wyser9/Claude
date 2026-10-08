# bexio WooCommerce Sync

WordPress-Plugin, das einen WooCommerce-Shop mit dem ERP **bexio** verbindet.

| Was | Richtung | Wann |
|---|---|---|
| Artikel (Produkte & Varianten) | WooCommerce → bexio | bei jedem Speichern eines Produkts |
| Lagerbestände | WooCommerce → bexio | bei jeder Bestandsänderung (Verkauf, Storno, manuell) |
| Bestellungen als **Auftrag** | WooCommerce → bexio | bei Status „In Bearbeitung“ / „Abgeschlossen“ (konfigurierbar) |
| Besteller als **Kontakt** | WooCommerce → bexio | zusammen mit der Bestellung |

WooCommerce ist das führende System für Artikel und Bestand. Alle Übertragungen laufen im Hintergrund über den Action Scheduler von WooCommerce. Der Checkout wird dadurch nicht langsamer, und fehlgeschlagene Jobs bleiben sichtbar.

## Installation

1. Den Ordner `bexio-woocommerce-sync` nach `wp-content/plugins/` kopieren (oder als ZIP über *Plugins → Installieren → Plugin hochladen*).
2. Das Plugin aktivieren.
3. In bexio einen **API-Token** erstellen: *Einstellungen → Sicherheit → API-Tokens* (Personal Access Token). Der Token braucht Zugriff auf Kontakte, Artikel/Lager, Aufträge und Buchhaltung (Steuern, Konten, Währungen).
4. In WordPress *WooCommerce → bexio Sync* öffnen und den Token eintragen.
   Alternativ den Token in `wp-config.php` setzen (empfohlen, weil er dann nicht in der Datenbank liegt):
   ```php
   define( 'BEXIO_API_TOKEN', 'eyJ...' );
   ```
5. Danach die Zuordnungen wählen: **Einheit** (z. B. „Stk.“), **Lager**/**Lagerplatz**, optional Ertragskonto, Kontaktgruppe und Sprache.
6. **„Alle Artikel & Bestände jetzt übertragen“** klicken, um den Artikelstamm einmalig abzugleichen.

## Wie die Daten zugeordnet werden

### Artikel
- **SKU → bexio Artikel-Nr. (`intern_code`)**. Gibt es in bexio schon einen Artikel mit dieser Nummer, wird er verknüpft und aktualisiert, nicht doppelt angelegt. Produkte ohne SKU erhalten `WC-<ID>` (abschaltbar).
- Einfache Produkte und **jede Variante** werden je zu einem bexio-Artikel. Variable Elternprodukte, gruppierte und externe Produkte werden nicht übertragen.
- Übertragen werden: Name (bei Varianten mit Merkmalen), Kurzbeschreibung, Verkaufspreis (regulärer Preis), MWST-Satz, Gewicht (in g), Einheit, Konto sowie Lagerbestand, Lager und Lagerplatz.
- Virtuelle Produkte werden als Dienstleistung angelegt, alle anderen als physischer Artikel.
- Die Verknüpfung wird am Produkt gespeichert (Meta `_bws_article_id`).

### Lagerbestand
- Nur bei Produkten mit aktivierter **Lagerverwaltung** in WooCommerce. Der aktuelle Bestand wird als `stock_nr` an den bexio-Artikel gesendet (Lagerartikel mit gewähltem Lager/Lagerplatz).
- Übernimmt bexio den Bestand nicht (z. B. weil im bexio-Konto keine Lagerverwaltung aktiv ist), steht eine Warnung im Log.

### Besteller → Kontakt
1. Kontakt-ID am Kundenkonto (bei Stammkunden)
2. Suche in bexio per **E-Mail**
3. Sonst Neuanlage: Ist das Feld *Firma* ausgefüllt, als **Firma** (Ansprechperson in Zeile 2), sonst als **Person**.
- Die Adresse wird in Strasse und Hausnummer zerlegt (`Bahnhofstrasse 12a`, `12 Rue du Lac`), Land, Telefon und E-Mail werden übernommen.
- Bestehende bexio-Kontakte werden standardmässig **nicht** überschrieben (Option „Bestehende Kontakte aktualisieren“).

### Bestellung → Auftrag
- Titel `WooCommerce Bestellung #<Nr>`, Datum, Währung und `api_reference = woocommerce-<ID>`.
- **Positionen:** Produkte als Artikelposition (verknüpft mit dem bexio-Artikel), Gutscheine als Rabattposition, Versand und Gebühren als freie Positionen.
- **MWST:** Jede Position bekommt die bexio-Umsatzsteuer mit dem passenden Satz (8.1 %, 2.6 %, 3.8 %, 0 %). Die Zuordnung erfolgt automatisch über den Prozentsatz.
- **Preise brutto oder netto** (Einstellung). Brutto (inkl. MWST) ist der Standard für Schweizer B2C-Shops. Nach dem Anlegen vergleicht das Plugin das Total in bexio mit dem Bestelltotal und schreibt eine Bestellnotiz, falls sie abweichen.
- Zahlungsart, Transaktions-ID und Kundenbemerkung landen im Kopftext, eine abweichende Lieferadresse im Feld Lieferadresse.
- Jede Bestellung wird **genau einmal** übertragen. Die Auftragsnummer erscheint in der Bestellnotiz und in der Box „bexio“ (mit Link zu bexio).
- Manuell senden: In der Bestellung die Aktion **„An bexio übertragen“** wählen oder in der Bestellübersicht die gleichnamige Sammelaktion verwenden.

## Fehler & Protokoll
- Log: *WooCommerce → Status → Logs*, Quelle **bexio-sync**
- Hintergrund-Jobs: *WooCommerce → Status → Geplante Aktionen*, Gruppe **bexio-sync**. Fehlgeschlagene Jobs lassen sich dort erneut ausführen.
- Fehler bei Bestellungen erscheinen zusätzlich als Bestellnotiz und in der bexio-Box der Bestellung.
- Rate-Limits (HTTP 429) und Serverfehler von bexio werden automatisch bis zu 3× wiederholt.

## WP-CLI
```bash
wp bexio test                 # Verbindung prüfen
wp bexio products             # alle Artikel/Bestände synchron übertragen
wp bexio products --id=123    # einzelnes Produkt
wp bexio order 456            # einzelne Bestellung übertragen
```

## Anpassen (Filter)
```php
// z.B. Einkaufspreis aus einem eigenen Feld mitschicken
add_filter( 'bws_article_payload', function ( $payload, $product ) {
	$payload['purchase_price'] = $product->get_meta( '_einkaufspreis' );
	return $payload;
}, 10, 2 );
```
Weitere Filter: `bws_contact_payload`, `bws_order_payload`, `bws_order_item_position`. Action nach erfolgreicher Übertragung: `bws_order_synced`.

## Vor dem Live-Betrieb testen
1. Mit einer Testbestellung prüfen, ob in bexio Total, MWST und Positionen stimmen (Brutto/Netto-Einstellung).
2. Bei einem Lagerartikel den Bestand ändern und in bexio kontrollieren.
3. Gibt es in bexio schon Artikel: Stimmen deren Artikel-Nr. mit den SKUs im Shop überein? Nur dann werden sie verknüpft statt doppelt angelegt.

## Tests
```bash
php tests/test-util.php                                                     # Unit-Tests (ohne WordPress)
wp eval-file wp-content/plugins/bexio-woocommerce-sync/tests/integration.php  # nur in einer Test-Installation!
```

## Anforderungen
WordPress ≥ 6.0, WooCommerce ≥ 7.0 (HPOS-kompatibel), PHP ≥ 7.4.
