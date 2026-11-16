# Armatum News (Newsletter)

Alles zum Newsletter von armatum.ch. Dieses Repo ist öffentlich: hier liegen nur Konzept, Texte, Vorlagen und Code.
**Nie** Kundenlisten, E-Mail-Adressen oder Zugangsdaten ablegen.

| Datei | Inhalt |
| --- | --- |
| [konzept.md](konzept.md) | Konzept (Werkzeug, Anmeldung, Rubriken, Regeln, Themenplan) |
| [mails/double-optin.md](mails/double-optin.md) | Texte der Bestätigungsmail und der Bestätigungsseite |
| [code/armatum-news-anmeldung.php](code/armatum-news-anmeldung.php) | Code-Snippet: Häkchen an der Kasse + Formular `[armatum_newsletter]` |
| [2026-10-neuheiten.md](2026-10-neuheiten.md) | Früherer Entwurf «Neu bei Armatum» (Oktober 2026) |
| [2026-11-training-223.md](2026-11-training-223.md) | Baustein Hornady Frontier .223 (Mark) → für die Dezember-Ausgabe |

Ausgaben kommen als `JJJJ-MM-thema.md` in diesen Ordner.

## Stand (15.11.2026)

- Freigabe Pascal 15.11.2026 («Umsetzen»).
- FluentCRM 3.3.1 (gratis) installiert und aktiv. Versand über FluentSMTP / Hostpoint (shop@armatum.ch).
- Liste «Armatum News» = FluentCRM-Liste ID 1.
- Absender «Armatum», shop@armatum.ch; Fusszeile mit Firma, Adresse und Abmeldelink (deutsch).
- Double-Opt-in-Mail und Bestätigungsseite auf Deutsch (siehe mails/).
- Das Häkchen an der Kasse ist in FluentCRM gratis nicht verfügbar (WooCommerce-Integration nur Pro). Deshalb das Snippet in `code/`.
  In Code Snippets als Nr. 23 angelegt, **noch inaktiv**: muss von Pascal eingefügt/aktiviert werden.

- Datenschutzerklärung (Seite 3): Abschnitt «Datenschutzerklärung für den Newsletter» eingefügt (15.11.2026).
- Abmeldehinweis in den Bestellmails «Bestellung in Wartestellung» und «Bestellung in Bearbeitung» (15.11.2026).
- Seite «Newsletter» (ID 33781, /newsletter/) als Entwurf mit `[armatum_newsletter]`.

## Snippet einfügen (Pascal)

WordPress → Snippets → «Armatum News: Anmeldung Kasse + Formular» (Nr. 23) → Inhalt von
`code/armatum-news-anmeldung.php` komplett einfügen → «Speichern und aktivieren».

## Offen

- [x] Snippet 23 aktiv (16.11.). **Nachtrag nötig:** Häkchen erscheint nicht, weil die Kasse der Checkout-Block ist → erweiterten Code (Abschnitt «Block-Kasse») in Snippet 23 ersetzen.
- [ ] Testanmeldung (Inhaber, eigene Adresse)
- [x] Seite /newsletter/ veröffentlicht, Formular in Fusszeile (Widget block-15, ersetzt Link-Widget block-14) – 16.11.
- [ ] Formular auf Startseite
- [ ] Newsletter-Link in der Bewertungsmail
- [ ] Willkommensmail, Vorlage im Armatum-Design
- [ ] QR-Karte für den Laden
- [ ] Erste Ausgabe Dezember 2026 (Do 03.12., erster Donnerstag). **Keine Black Week** (Inhaber hat sie am 12.11. abgelehnt), Weihnachtsplan ohne Black Week: ../verkaufsideen-2026-11-12.md

## Abgleich Insta-Session / Projekt (15.11.2026)

- Mark's .223-Baustein wird **nicht** im November verschickt (noch keine bestätigten Abonnenten, Bestandskunden nur mit Einwilligung). Er kommt in die Dezember-Ausgabe, Rubrik «Wiederladen/Training» oder «Im Laden».
- Preise Hornady Frontier .223 (Shop, seit 13.11.): 14.50 / ab 10: 12.90 / ab 25: 11.50 / ab 50: 9.50 pro 20er-Pack.
- Schliessung: **Do 12.11. offen, Do 19.11. geschlossen** (Projekt-Branch hatte in 2026-10-neuheiten.md noch den alten Stand).
- Occasion B&T SPC9 (seit 15.11. wieder bewerbbar) als Kandidat für «Occasionen» im Dezember.
