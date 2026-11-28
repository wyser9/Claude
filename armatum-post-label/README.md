# Armatum Post Label

WordPress-Plugin: erstellt Versandlabels der Schweizerischen Post (Digital Commerce API) direkt aus WooCommerce-Bestellungen.

- Produkt: **PostPac Economy** (Gewichtsstufe bis 2 / 10 / 30 kg ergibt sich aus dem Gewicht)
- Zusatzleistungen pro Bestellung: **Signature** (SI) und **Sperrgut** (SP)
- Nur Lieferungen in die Schweiz/Liechtenstein, max. 30 kg

## Ablauf in der Bestellung
Box **„Schweizerische Post“** (rechts):
1. Gewicht prüfen – vorausgefüllt aus den Produktgewichten (Warnung, wenn Produkte kein Gewicht haben).
2. **Signature** / **Sperrgut** ankreuzen – vorgeschlagen wird Signature, wenn die Versandart „Signature“ oder „Waffen“ enthält, Sperrgut bei „Sperrgut“ oder „Überlänge“. Ausschliessende Zusätze wie „(ohne Munition/Waffen)“ zählen nicht.
3. **„Post-Label erstellen“** – die Adresse wird bei der Post geprüft, das Label als PDF erzeugt. Standard ist **A4 mit dem Label im oberen Drittel** und Falzlinien, zum Falten wie ein Brief. Alternativ gibt es das A6-PDF der Post (Einstellung *Druckformat*). Gedruckt wird in Originalgrösse (100 %).
4. **„Label öffnen / drucken“** – das PDF ist nur für angemeldete Shop-Mitarbeitende abrufbar (geschützter Ordner, zufälliger Dateiname).

Die Sendungsnummer steht in der Box und als Bestellnotiz. Der Kunde bekommt sie **sofort beim Erstellen des Labels** mit Link zur Sendungsverfolgung, als WooCommerce-E-Mail „Kundenhinweis“ mit eigenem Betreff „Ihr Paket ist unterwegs – Bestellung #…“. Jede Nummer wird nur einmal geschickt, abschaltbar unter *Kunde informieren*. Zusätzlich steht sie in der E-Mail **„Bestellung abgeschlossen“**. Bei Testlabels gibt es keines von beidem. Abholungen werden erkannt, Briefe (z. B. „Grossbrief B-Post“) nicht unterstützt.

## Mehrere Labels auf einmal
In *WooCommerce → Bestellungen* die gewünschten Bestellungen anhaken und unter „Mehrfachaktionen“ **„Post-Labels erstellen“** wählen. Gewicht, Signature und Sperrgut übernimmt das Plugin wie in der Bestell-Box vorgeschlagen, und jeder Kunde bekommt sofort seine Sendungsnummer. Danach gibt es oben die Schaltfläche **„Alle Labels drucken (PDF)“**: ein PDF, pro Label eine A4-Seite (oberes Drittel). Übersprungen werden mit Grund:
- Abholungen und Briefe,
- stornierte und unbezahlte Bestellungen,
- Bestellungen ohne Produktgewicht,
- Bestellungen, die schon ein echtes Label haben.

Ein Durchgang verarbeitet etwa 20 Sekunden lang Bestellungen. Was danach noch übrig ist, wird gemeldet und kann erneut ausgewählt werden. Das gemeinsame PDF gibt es nur mit dem Papier „A4, oberes Drittel“.

## Einstellungen
*Einstellungen → Armatum Post Label*: Client ID, Client Secret, Frankierlizenz, Absender, **Testmodus**.

- **Logo auf dem Label**: Das Logo wird im Absenderfeld gedruckt. Standard ist das Website-Logo, sonst das Website-Icon; eine andere Mediathek-ID kann eingetragen werden. Das Bild wird als PNG verkleinert (max. 30 KB). Lehnt die Post das Logo ab, entsteht das Label trotzdem, dann ohne Logo, mit Hinweis in der Bestellnotiz.

**Testmodus** (bei Installation aktiv): Labels werden mit Aufdruck „SPECIMEN“ erstellt – nicht versandfähig, nicht verrechnet. Nach einem erfolgreichen Testlabel ausschalten.

## Update von Version 0.1
Beim ersten Aufruf nach dem Update werden die Muster-Labels der Version 0.1 (öffentlich erreichbarer Ordner `uploads/post-labels/`) samt Verknüpfungen an den Bestellungen gelöscht. Zugangsdaten bleiben erhalten.

## Tests
```bash
php tests/test-util.php
wp eval-file wp-content/plugins/armatum-post-label/tests/integration.php   # nur Test-Installation
```

## Für andere Plugins
Nach jedem erstellten Label wird `do_action( 'apl_label_created', $order, $ident_code, $preview )` ausgelöst. *bexio WooCommerce Sync* (ab 1.6.0) nutzt das, um die Sendungsnummer in den bexio-Auftrag zu schreiben.

## Postlabel aus bexio drucken (Test, ab 1.7.0)

- Userscript `bexio-postlabel.user.js` (Tampermonkey) zeigt im bexio-Lieferschein den Knopf «Postlabel drucken».
- Der Knopf öffnet `admin-post.php?action=apl_bexio_label&key=…&delivery=<bexio-ID>&hints=…`. Nötig: Anmeldung im Shop (edit_shop_orders) und der Schlüssel aus Einstellungen > Armatum Post Label.
- Zuordnung: API-Referenz `woocommerce-<ID>`, bexio-Auftrag (`_bws_order_nr`), `#Bestellnummer` (aus Seite oder Titel des Lieferscheins via bexio-API), sonst die einzige offene Bestellung des bexio-Kontakts ohne Label. Sonst Auswahlliste bzw. Eingabe der Nummer.
- Vorhandenes Label wird nur angezeigt; ohne Label wird es mit den üblichen Vorschlägen (Gewicht, Signature, Sperrgut) erstellt. Danach öffnet sich der Druckdialog.
- Einstellungsseite: Knopf «Dateizugriff prüfen» testet lesend `GET 3.0/files` mit dem Token des bexio-Sync-Plugins.
