# bexio WooCommerce Sync

WordPress-Plugin, das einen WooCommerce-Shop mit dem ERP **bexio** verbindet.

| Was | Richtung | Wann |
|---|---|---|
| Artikel (Produkte & Varianten) | WooCommerce → bexio | bei jedem Speichern eines Produkts |
| **Lagerstatus** | **bexio → WooCommerce** | **täglich** (Uhrzeit einstellbar), Standard |
| Bestellungen als **Auftrag** | WooCommerce → bexio | bei Status „In Bearbeitung“ / „Abgeschlossen“ (konfigurierbar) |
| Besteller als **Kontakt** | WooCommerce → bexio | zusammen mit der Bestellung |

WooCommerce führt die Artikel-Stammdaten (Texte, Bilder, Preise), **bexio führt das Lager**. Alle Übertragungen laufen im Hintergrund über den Action Scheduler von WooCommerce. Der Checkout wird dadurch nicht langsamer, und fehlgeschlagene Jobs bleiben sichtbar.

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
6. **„Alle Artikel jetzt an bexio übertragen“** klicken, um den Artikelstamm einmalig abzugleichen.
7. Uhrzeit für den täglichen Lagerimport prüfen (Standard 03:00) und einmal **„Lagerstatus jetzt aus bexio holen“** klicken.

## Wie die Daten zugeordnet werden

### Artikel
- **SKU → bexio Artikel-Nr. (`intern_code`)**. Gibt es in bexio schon einen Artikel mit dieser Nummer, wird er verknüpft und aktualisiert, nicht doppelt angelegt. Produkte ohne SKU erhalten `WC-<ID>` (abschaltbar).
- Einfache Produkte und **jede Variante** werden je zu einem bexio-Artikel. Variable Elternprodukte, gruppierte und externe Produkte werden nicht übertragen.
- Übertragen werden: Name (bei Varianten mit Merkmalen), Kurzbeschreibung, Verkaufspreis (regulärer Preis), MWST-Satz, Gewicht (in g), Einheit, Konto sowie Lagerbestand, Lager und Lagerplatz.
- Virtuelle Produkte werden als Dienstleistung angelegt, alle anderen als physischer Artikel.
- Die Verknüpfung wird am Produkt gespeichert (Meta `_bws_article_id`).

### Neue Artikel in bexio
Nur beim **Neuanlegen** eines Artikels setzt das Plugin zusätzlich:
- **„Ist ein Produkt mit Lager“** (Lagerbestand und Mindestbestand 0, Lagerort und Lagerplatz aus den bexio-Zuordnungen), abschaltbar mit „Neue Artikel als Lagerartikel“. Virtuelle Produkte werden ohne Lager angelegt.
- **Aufwandkonto**: automatisch Konto **4200 – Einkauf Handelsware** (oder das in den Einstellungen gewählte Konto).
- **MWST Vorsteuer**: automatisch **VM81 – Mat/DL (NS) 8.1 %** (oder die gewählte Vorsteuer).

Bestehende bexio-Artikel werden dabei nicht verändert.

### Bestehende Artikel nachrüsten
Werkzeug **„Bestehende Artikel nachrüsten (Lager, Aufwandkonto, Vorsteuer)“** (oder `wp bexio backfill`): Für alle bexio-Artikel, die mit einem Shop-Produkt verknüpft sind, werden **nur fehlende Werte** ergänzt – „Ist ein Produkt mit Lager“ (Bestand/Mindestbestand 0, Lagerort/-platz) bei Artikeln ohne Lagerführung, Aufwandkonto 4200 und Vorsteuer VM81, wo leer. Bestehende Lagerbestände, gesetzte Konten/Steuern und Artikel ohne Shop-Produkt (z. B. Waffen-Kopien mit Seriennummer) bleiben unverändert. Fortschritt auf der Einstellungsseite.

### Beschreibung als Stichpunkte
Die bexio-Produktbeschreibung enthält die wichtigsten Fakten als Aufzählung: zuerst die sichtbaren Merkmale („Hersteller: Streamlight“, „Packungsgrösse: 100“ …), dann die Aufzählungspunkte der Shop-Beschreibung (Kurz-, sonst Langbeschreibung); gibt es keine, die ersten drei Sätze der Kurzbeschreibung. Max. 10 Punkte, Duplikate entfernt. Abschaltbar („Beschreibung als Stichpunkte“). Bestehende Artikel erhalten die neue Beschreibung bei der nächsten Übertragung („Alle Artikel jetzt an bexio übertragen“).

### Waffen mit Seriennummer-Kopien
Für jede Waffe gibt es in bexio einen **Hauptartikel** (z. B. „19442“, Bestand immer 0) und **Kopien mit Seriennummer** (z. B. „096-77116“), deren **„Produktcode Lieferant“** die Artikelnummer des Hauptartikels trägt. Beim Lagerimport wird der Bestand aller Kopien zum Hauptartikel addiert:
- mindestens eine Kopie an Lager → Hauptartikel im Shop **Vorrätig** (bei Lagerverwaltung mit der Anzahl Waffen),
- keine Kopie an Lager → **Lieferrückstand**.

Gilt nur für Produkte der Kategorien **„Waffen“** und **„Waffenzubehör“** inkl. aller Unterkategorien (damit auch „Lampen / Laser“) – einstellbar unter „Kategorien mit Seriennummern“ –, damit normale Lieferanten-Artikelnummern nicht mitzählen. Shop-Bestellungen laufen weiterhin auf den Hauptartikel. Der Bestand in bexio wird nie verändert.

### Artikelgruppen (Produktkategorien)
- In den Einstellungen unter **Artikelgruppen** steht jede Hauptkategorie des Shops mit einem Feld für die **bexio Artikelgruppen-ID**.
- Die Artikelgruppen müssen zuerst **in bexio angelegt** werden. Die bexio-API bietet keine Schnittstelle zum Anlegen oder Auflisten von Artikelgruppen.
- Unterkategorien übernehmen die Gruppe ihrer Hauptkategorie. Kategorien ohne ID (z. B. „Hersteller“, „Sale %“) werden ignoriert. Varianten übernehmen die Kategorien des Hauptprodukts.
- Liegt ein Produkt in mehreren zugeordneten Kategorien, gewinnt die genaueste Zuordnung, bei Gleichstand die kleinere Kategorie-ID.
- Nach dem Eintragen der IDs einmal **„Alle Artikel jetzt an bexio übertragen“** ausführen, damit auch bestehende Artikel ihre Gruppe erhalten.

### Lagerbestand (bexio → WooCommerce, täglich)
bexio führt das Lager. Einmal täglich holt das Plugin alle Artikel aus bexio und sucht das passende Produkt im Shop, zuerst über **SKU = Artikel-Nr.**, sonst über einen bereits verknüpften Artikel:

| bexio | WooCommerce |
|---|---|
| Produkt im Shop nicht vorhanden | nichts |
| Bestand **> 0** | **Vorrätig** |
| Bestand **<= 0** | **Lieferrückstand** (Kunden können weiterhin bestellen) |

- Berücksichtigt werden nur bexio-**Lagerartikel**. Artikel ohne Lagerführung, z. B. Dienstleistungen, haben in bexio immer Bestand 0 und werden übersprungen.
- **Produkte mit aktivierter Lagerverwaltung in WooCommerce:** WooCommerce berechnet den Status dort selbst aus der Menge. Darum wird zusätzlich die **Menge aus bexio** übernommen und „Lieferrückstand erlauben (mit Hinweis)“ aktiviert, falls nötig. Ergebnis: gleiche Regel, und im Shop steht die bexio-Menge.
- **Produkte ohne Lagerverwaltung:** Nur der Status wird gesetzt.
- Bei Varianten wird der Status pro Variante gesetzt. Das variable Hauptprodukt berechnet WooCommerce daraus automatisch.
- Wahlweise zählt der physische Bestand (`stock_nr`) oder der verfügbare Bestand abzüglich Reservierungen (`stock_available_nr`).
- Der Import schickt nichts an bexio zurück. Bestände werden nie von WooCommerce nach bexio übertragen, solange „bexio führt das Lager“ eingestellt ist.
- Das Ergebnis des letzten Laufs und den nächsten Termin zeigt die Einstellungsseite an. Details stehen im Log.
- Alternativ lässt sich unter *Lagerbestand* „WooCommerce führt das Lager“ wählen. Dann wird der Woo-Bestand bei jeder Änderung an bexio gesendet. **Einschränkung laut bexio-API:** Der Bestand eines Artikels lässt sich nur setzen, solange in bexio noch keine Lagerbuchungen für ihn existieren. Für den Normalbetrieb ist deshalb „bexio führt das Lager“ die richtige Wahl.

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
- **Keine Duplikate:** Vor dem Anlegen sucht das Plugin in bexio nach einem Auftrag, dessen **Titel die Bestellnummer enthält** (z. B. „12345“ oder „12345 Max Muster“). Gibt es ihn schon, wird die Bestellung nur verknüpft. Es wird weder ein Auftrag noch ein Kontakt angelegt, eine Bestellnotiz hält das fest. Die Nummer muss als eigene Zahl vorkommen: „123456“ zählt nicht als Bestellung 12345. Schlägt die Suche fehl, wird sicherheitshalber nichts angelegt.
- Auftragstitel: Ist das Feld *Auftragstitel* leer, besteht der Titel nur aus der Bestellnummer.
- Jede Bestellung wird **genau einmal** übertragen. Die Auftragsnummer erscheint in der Bestellnotiz und in der Box „bexio“ (mit Link zu bexio).
- Manuell senden: In der Bestellung die Aktion **„An bexio übertragen“** wählen oder in der Bestellübersicht die gleichnamige Sammelaktion verwenden.

### Bestellstatus aus bexio (stündlich)
Wenn in bexio aus dem Auftrag eine Rechnung erstellt und diese als bezahlt markiert wird, zieht die Bestellung nach:

| bexio | WooCommerce |
|---|---|
| Rechnung bezahlt | **In Bearbeitung** (bezahlt, Zahlungsdatum gesetzt) |
| Rechnung bezahlt **und** Auftrag „Erledigt“ | **Abgeschlossen** |

- Geprüft werden verknüpfte Bestellungen der letzten 180 Tage mit Status *Wartend*, *Zahlung ausstehend* oder *In Bearbeitung*. Abgeschlossene, stornierte und erstattete Bestellungen bleiben unverändert, ebenso alles in bexio.
- **Zuordnung der Rechnung:** Gesucht werden die Rechnungen des Auftrags-Kontakts. Passend ist eine Rechnung, deren Titel die Bestellnummer enthält, was bei aus dem Auftrag erstellten Rechnungen der Fall ist. Trifft das auf keine zu, zählt eine Rechnung mit gleichem Total, aber nur, wenn es genau eine solche gibt. Stornierte Rechnungen zählen nicht. Bei mehreren Rechnungen (Teilrechnungen) müssen alle bezahlt sein.
- Der Kunde erhält die üblichen WooCommerce-E-Mails („Bestellung in Bearbeitung“ bzw. „Bestellung abgeschlossen“).
- Sofort ausführen: Knopf **„Bestellstatus jetzt aus bexio holen“** oder `wp bexio import-status`. Abschalten: Einstellung *Bestellstatus aus bexio*.

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
wp bexio import-stock         # Lagerstatus sofort aus bexio übernehmen
wp bexio import-status        # Bezahlt/Erledigt sofort aus bexio übernehmen
wp bexio backfill             # bestehende Artikel nachrüsten
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
2. „Lagerstatus jetzt aus bexio holen“ ausführen und bei einigen Produkten mit Bestand > 0 bzw. 0 den Status im Shop kontrollieren.
3. Gibt es in bexio schon Artikel: Stimmen deren Artikel-Nr. mit den SKUs im Shop überein? Nur dann werden sie verknüpft statt doppelt angelegt.

## Tests
```bash
php tests/test-util.php                                                     # Unit-Tests (ohne WordPress)
wp eval-file wp-content/plugins/bexio-woocommerce-sync/tests/integration.php  # nur in einer Test-Installation!
```

## Anforderungen
WordPress ≥ 6.0, WooCommerce ≥ 7.0 (HPOS-kompatibel), PHP ≥ 7.4.
