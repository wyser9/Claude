# Backlog – bexio-Sync & Post Label

Vereinbarte Erweiterungen, die später umgesetzt werden.

## Vorgemerkt

- **Rückerstattung → bexio** (vorgemerkt 08.11.2026):
  - Wird eine Bestellung im Shop ganz oder teilweise erstattet (z. B. „Rückerstattung über TWINT“), schreibt das Plugin einen internen Kommentar in den bexio-Auftrag: Betrag, Grund, Datum.
  - Zusätzlich geht eine Hinweis-Mail an „Hinweise an“, den Auftrag bzw. die Rechnung in bexio zu stornieren oder eine Gutschrift zu erstellen.
  - Umsetzung wie beim Storno: `bexio-woocommerce-sync/includes/class-bws-order-events.php`. Hook `woocommerce_order_refunded` bzw. `woocommerce_order_partially_refunded`, je Erstattung nur einmal.
- **Zahlungserinnerung an den Kunden**: Nach X Tagen unbezahlter Vorkasse bekommt der Kunde eine freundliche Mail mit den Bankdaten. Bisher geht der Hinweis nur an den Shop.
- **Betreff der Tracking-Mail**: Statt „Ihrer Bestellung … wurde ein Hinweis hinzugefügt“ einen eigenen Betreff verwenden, z. B. „Ihr Paket ist unterwegs“.
- **Gewichte importieren**: Die ausgefüllte Gewichtsliste (`exports/`, nicht im Repository) per REST in den Shop übernehmen.
