# Armatum News (Newsletter)

Alles zum Newsletter von armatum.ch. Dieses Repo ist öffentlich: hier liegen nur Konzept, Texte, Vorlagen und Code.
**Nie** Kundenlisten, E-Mail-Adressen oder Zugangsdaten ablegen.

| Datei | Inhalt |
| --- | --- |
| [konzept.md](konzept.md) | Konzept (Werkzeug, Anmeldung, Rubriken, Regeln, Themenplan) |
| [mails/double-optin.md](mails/double-optin.md) | Texte der Bestätigungsmail und der Bestätigungsseite |
| [code/armatum-news-anmeldung.php](code/armatum-news-anmeldung.php) | Code-Snippet: Häkchen an der Kasse + Formular `[armatum_newsletter]` |
| [2026-10-neuheiten.md](2026-10-neuheiten.md) | Früherer Entwurf «Neu bei Armatum» (Oktober 2026) |

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
- Snippet 23 von Pascal am 16.11.2026 aktiviert.
- Seite «Newsletter» (ID 33781) live: https://armatum.ch/newsletter/
- Fusszeile: Hinweis mit Link auf /newsletter/ (Widget block-14).
- Bewertungsmail (Bestellung abgeschlossen): Link «Armatum News abonnieren».

## Snippet einfügen (Pascal)

WordPress → Snippets → «Armatum News: Anmeldung Kasse + Formular» (Nr. 23) → Inhalt von
`code/armatum-news-anmeldung.php` komplett einfügen → «Speichern und aktivieren».

## Offen

- [ ] Testanmeldung (Pascal mit eigener Adresse)
- [ ] Block auf der Startseite
- [ ] Willkommensmail, Vorlage im Armatum-Design
- [ ] QR-Karte für den Laden
- [ ] Erste Ausgabe Dezember 2026 (Black Week 26.–30.11. siehe ../verkaufsplan-nov-dez-2026.md)
