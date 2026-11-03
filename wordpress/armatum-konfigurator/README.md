# Armatum Konfigurator

WordPress-Plugin für armatum.ch (WooCommerce). Auf der Produktseite einer eingerichteten Waffe erscheint der Bereich «Waffe konfigurieren». Der Kunde wählt eine Optik und, falls nötig, eine Montage, sieht eine massstabsgetreue Vorschau und legt das Paket in den Warenkorb.

- Jede Komponente ist eine **eigene Warenkorbposition** (Lager, bexio, Versandcodes bleiben korrekt).
- Die **Montage** erscheint als eigene Position «Montage Optik (gratis im Paket)» mit CHF 0.00. Das Produkt legt das Plugin beim ersten Paket im Warenkorb an (SKU `AK-MONTAGE`, im Katalog versteckt). Einzeln kann man es nicht kaufen. Fehlt im Warenkorb die Waffe oder die Optik des Pakets, wird die Montage automatisch entfernt.
- Einschiessen ist nicht Teil des Pakets.
- Austauschteile (Schulterstütze, Griff) überdecken das Originalteil nur sauber, wenn ihr Bild freigestellt ist (PNG mit transparentem Hintergrund) und gross genug ist.
- Nur passende Artikel werden angezeigt (Regeln siehe unten). Der Server prüft die Auswahl beim Hinzufügen nochmals.

## Installation

1. Ordner `armatum-konfigurator` als ZIP packen (oder die fertige ZIP verwenden).
2. wp-admin → Plugins → Installieren → Plugin hochladen → aktivieren.
3. Die Produktseite einer Waffe aus dem Cache von WP Super Cache leeren, nachdem sie eingerichtet ist.

## Testmodus

Nach der Installation ist der Testmodus an: Den Konfigurator sehen nur eingeloggte Admins und Shop-Manager (WP Super Cache liefert eingeloggten Benutzern keine Cache-Seiten aus). Freischalten für alle: Option `ak_testmode` auf `no` setzen.

## Einrichtung pro Produkt

Im Produkt-Editor gibt es die Box **Konfigurator**.

| Feld | Waffe | Montage | Optik |
| --- | --- | --- | --- |
| Rolle | Waffe | Montage | Optik |

Ab Version 0.2 gibt es zusätzlich die Rolle **Zubehör** mit Gruppe (Schalldämpfer, Handgriff, Laser, Schulterstütze, Griff), Schnittstelle, Hinweis für Kunden und der Option «Bild ist freigestellt». Die Waffe hat dafür Steckplätze (Mündung, Handschutz unten, Handschutz seitlich/oben, Schaftaufnahme, Griffaufnahme) mit Schnittstellen und je einem Punkt im Bild. Zubehör passt, wenn eine seiner Schnittstellen am Steckplatz vorkommt.

| Feld | Waffe | Montage | Optik |
| Schnittstelle(n) | z.B. `picatinny` (mehrere mit Komma) | `picatinny` | nur bei «direkt»: `picatinny` |
| Mittelrohr [mm] | – | Ringgrösse, z.B. `30` | leer = Attribut «Mittelrohrdurchmesser» |
| direkt auf Schiene | – | – | Rotpunkt oder Optik mit integrierter Montage |
| Gesperrte Produkt-IDs | Ausnahmen, die trotz Regel nicht passen | – | – |

**Punkte im Bild setzen** (Knopf drücken, dann ins Bild klicken):

- **Messpunkt 1 und 2** mit bekanntem Abstand in mm (Waffe: Mündung und Schaftende = Gesamtlänge; Optik: Vorder- und Hinterende = Länge; Montage: Vorder- und Hinterende der Basis).
- **Ankerpunkt**: Waffe = Oberkante Schiene, wo die Optik sitzen soll. Montage = Mitte der Unterseite. Optik mit Mittelrohr = Rohrachse in der Mitte. Rotpunkt = Mitte der Unterseite.
- **Rohrmitte** (nur Montage): Mitte der Ringe.

Zeigt ein Bild die linke Seite, «Bild spiegeln» ankreuzen. Beste Ergebnisse mit Seitenansicht von rechts auf weissem oder transparentem Hintergrund.

## Regeln

- Optik «direkt»: passt, wenn eine ihrer Schnittstellen auch an der Waffe ist.
- Optik mit Mittelrohr: braucht eine Montage, deren Schnittstelle an der Waffe ist und deren Mittelrohr gleich gross ist.
- Nur veröffentlichte, kaufbare und lieferbare Produkte, die vollständig eingerichtet sind.

## Shortcode

`[armatum_konfigurator waffe="123"]` zeigt den Konfigurator auf einer beliebigen Seite.
