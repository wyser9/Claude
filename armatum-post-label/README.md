# Armatum Post Label

WordPress-Plugin: erstellt Versandlabels der Schweizerischen Post (Digital Commerce API) direkt aus WooCommerce-Bestellungen.

- Produkt: **PostPac Economy** (Gewichtsstufe bis 2 / 10 / 30 kg ergibt sich aus dem Gewicht)
- Zusatzleistungen pro Bestellung: **Signature** (SI) und **Sperrgut** (SP)
- Nur Lieferungen in die Schweiz/Liechtenstein, max. 30 kg

## Ablauf in der Bestellung
Box **„Schweizerische Post“** (rechts):
1. Gewicht prüfen – vorausgefüllt aus den Produktgewichten (Warnung, wenn Produkte kein Gewicht haben).
2. **Signature** / **Sperrgut** ankreuzen – vorgeschlagen wird Signature, wenn die Versandart „Signature“ oder „Waffen“ enthält, Sperrgut bei „Sperrgut“.
3. **„Post-Label erstellen“** – die Adresse wird bei der Post geprüft, das Label als A6-PDF erzeugt.
4. **„Label öffnen / drucken“** – das PDF ist nur für angemeldete Shop-Mitarbeitende abrufbar (geschützter Ordner, zufälliger Dateiname).

Die Sendungsnummer steht in der Box und als Bestellnotiz und wird in der E-Mail **„Bestellung abgeschlossen“** mit Link zur Sendungsverfolgung an den Kunden geschickt (nicht bei Testlabels). Abholungen werden erkannt, Briefe (z. B. „Grossbrief B-Post“) nicht unterstützt.

## Einstellungen
*Einstellungen → Armatum Post Label*: Client ID, Client Secret, Frankierlizenz, Absender, **Testmodus**.

**Testmodus** (bei Installation aktiv): Labels werden mit Aufdruck „SPECIMEN“ erstellt – nicht versandfähig, nicht verrechnet. Nach einem erfolgreichen Testlabel ausschalten.

## Update von Version 0.1
Beim ersten Aufruf nach dem Update werden die Muster-Labels der Version 0.1 (öffentlich erreichbarer Ordner `uploads/post-labels/`) samt Verknüpfungen an den Bestellungen gelöscht. Zugangsdaten bleiben erhalten.

## Tests
```bash
php tests/test-util.php
wp eval-file wp-content/plugins/armatum-post-label/tests/integration.php   # nur Test-Installation
```
