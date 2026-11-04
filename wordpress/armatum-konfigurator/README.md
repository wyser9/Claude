# Armatum Konfigurator

WordPress-Plugin für armatum.ch (WooCommerce). Der Konfigurator läuft auf einer eigenen Seite (ab 0.4). Der Kunde wählt dort eine Waffe, dann Optik, Montage und Zubehör, sieht eine massstabsgetreue Vorschau und legt das Paket in den Warenkorb.

- Produktseite einer Waffe: Knopf «Diese Waffe konfigurieren» (öffnet die Seite mit `?waffe=ID`).
- Produktseite von Optik, Montage oder Zubehör: Knopf «Im Konfigurator ansehen» (`?teil=ID`). Passt das Teil nur auf eine Waffe, ist sie gleich gewählt, sonst wählt der Kunde zuerst unter den passenden Waffen. Beim Schalldämpfer wählt der Konfigurator einen passenden HUB (und falls nötig Mündungsaufsatz) mit.
- Jede Gruppe ist ein aufklappbarer Schritt. Ab 7 Artikeln gibt es eine Suche und einen Herstellerfilter (Attribut «Hersteller», `pa_hersteller`).

- Jede Komponente ist eine **eigene Warenkorbposition** (Lager, bexio, Versandcodes bleiben korrekt).
- Die **Montage** erscheint als eigene Position «Montage Optik (gratis im Paket)» mit CHF 0.00. Das Produkt legt das Plugin beim ersten Paket im Warenkorb an (SKU `AK-MONTAGE`, im Katalog versteckt). Einzeln kann man es nicht kaufen. Fehlt im Warenkorb die Waffe oder die Optik des Pakets, wird die Montage automatisch entfernt.
- Einschiessen ist nicht Teil des Pakets.
- Austauschteile (Schulterstütze, Griff) überdecken das Originalteil nur sauber, wenn ihr Bild freigestellt ist (PNG mit transparentem Hintergrund) und gross genug ist.
- Nur passende Artikel werden angezeigt (Regeln siehe unten). Der Server prüft die Auswahl beim Hinzufügen nochmals.

## Installation

1. Ordner `armatum-konfigurator` als ZIP packen (oder die fertige ZIP verwenden).
2. wp-admin → Plugins → Installieren → Plugin hochladen → aktivieren (bei Update «Ersetzen»).
3. WooCommerce → Konfigurator → «Seite anlegen». Das legt die private Seite «Konfigurator» mit dem Shortcode an.
3. Die Produktseite einer Waffe aus dem Cache von WP Super Cache leeren, nachdem sie eingerichtet ist.

## Testmodus

Nach der Installation ist der Testmodus an: Den Konfigurator sehen nur eingeloggte Admins und Shop-Manager (WP Super Cache liefert eingeloggten Benutzern keine Cache-Seiten aus). Freischalten für alle: unter WooCommerce → Konfigurator den Testmodus ausschalten und die Seite veröffentlichen.

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

## Mündung (ab 0.3)

Die Mündung ist eine Kette: **Gewinde → Mündungsaufsatz → HUB → Schalldämpfer**.

- **Mündungsaufsatz** (Mündungsfeuerdämpfer, Mündungsbremse): Schnittstelle = Laufgewinde (z.B. `1/2x28`). Ersetzt den Original-Aufsatz. «Ausgang» = Schnittstelle für einen HUB (z.B. `taper-lok`), dazu «Ausgangspunkt» = vorderes Ende.
- **HUB**: Schnittstelle = Laufgewinde oder Ausgang eines Mündungsaufsatzes. «Ausgang» = HUB-Standard des Schalldämpfers (z.B. `1.375x24`), dazu «Ausgangspunkt».
- **Schalldämpfer**: Schnittstelle = HUB-Standard. Er erscheint erst, wenn ein passender HUB gewählt ist.
- **Waffe**: Steckplatz Mündung = Laufgewinde mit Punkt an der Gewindeschulter. Optional «Original-Mündungsaufsatz»: Schnittstelle für einen HUB und Punkt am vorderen Ende, dann passt ein HUB auch direkt auf den Original-Aufsatz.

## Attribut «Mündungsgewinde» (ab 0.3.1)

Ist bei Waffe (Steckplatz Mündung), Mündungsaufsatz oder Schalldämpfer keine Schnittstelle eingetragen, nimmt das Plugin das Produktattribut «Mündungsgewinde». Attribut **«HUB-Gewinde»** (Slug `pa_hub-gewinde`, z.B. 1.375x24 UNEF): beim HUB der **Ausgang** zum Schalldämpfer, beim Schalldämpfer der **Eingang**. «Mündungsgewinde» ist beim HUB der Eingang (Laufgewinde). Steht ein HUB-Standard (1.375x24, 1.5x24, 1.5x18, 1.75x24) noch im «Mündungsgewinde», wird er übergangsweise als Ausgang erkannt. Die Felder «Schnittstelle» und «Ausgang» haben Vorrang.

## Regeln

- Optik «direkt»: passt, wenn eine ihrer Schnittstellen auch an der Waffe ist.
- Optik mit Mittelrohr: braucht eine Montage, deren Schnittstelle an der Waffe ist und deren Mittelrohr gleich gross ist.
- Nur veröffentlichte, kaufbare und lieferbare Produkte, die vollständig eingerichtet sind.

## Shortcode

`[armatum_konfigurator]` zeigt die Waffenauswahl (oder mit `?waffe=ID` direkt den Konfigurator). `[armatum_konfigurator waffe="123"]` zeigt den Konfigurator einer festen Waffe.

## Automatisch einmessen (ab 0.5)

Herstellerbilder in gerader Seitenansicht (90°) auf weissem oder transparentem Hintergrund lassen sich automatisch einmessen. Das Plugin erkennt den Umriss des Produkts. Die **Länge** kommt aus dem Feld «Länge [mm]» im Konfigurator oder aus dem Attribut «Länge [mm]» (`pa_laenge`). Daraus berechnet es den Massstab und setzt die Punkte:

| Gruppe | Ankerpunkt | Rohrmitte / Ausgangspunkt |
| --- | --- | --- |
| Waffe | – (selbst setzen, ebenso Steckplätze) | – |
| Optik mit Mittelrohr | Rohrachse in der Mitte (Spalte, deren Höhe dem Mittelrohr entspricht) | – |
| Optik direkt | Mitte Unterseite | – |
| Montage | Mitte Unterseite | Oberkante + Rohrradius + 3 mm (Schätzung, prüfen) |
| Mündungsaufsatz, HUB | hinteres Ende Mitte | vorderes Ende Mitte |
| Schalldämpfer | hinteres Ende Mitte | – |
| Handgriff, Laser | Mitte Oberseite | – |
| Adapterschiene | Mitte Oberseite (Auflage) | Mitte Unterseite (Schiene) |
| Schulterstütze | vorderes Ende Mitte | – |
| Griff | oberer vorderer Punkt | – |

- **Im Produkt:** Knopf «Automatisch einmessen» rechnet mit den Werten im Formular. Danach prüfen und speichern.
- **Für viele Produkte:** WooCommerce → Konfigurator → «Automatisch einrichten». Erst einen Probelauf machen. Bereits eingemessene Produkte werden übersprungen (ausser «Bestehende Punkte überschreiben»).
- **Kategorien zuordnen:** In den Einstellungen Kategorien einer Rolle/Gruppe zuordnen. Produkte ohne Rolle erhalten sie beim automatischen Einrichten.

## Adapterschiene (ab 0.5)

Gruppe «Adapterschiene», z.B. M-LOK auf Picatinny: Schnittstelle `m-lok`, Ausgang `picatinny`. Passt ein Handgriff oder Laser nicht direkt auf den Steckplatz, aber über eine Adapterschiene, zeigt der Konfigurator ihn trotzdem an und wählt die Schiene mit. Sie ist eine eigene Warenkorbposition.

## Schnittstellen-Schreibweise (ab 0.5)

Unter jedem Schnittstellen-Feld stehen die bekannten Werte als Knöpfe. Neue Werte werden rot markiert. Beim Speichern werden Schreibweisen vereinheitlicht (z.B. `mlok`, `M Lok` → `m-lok`; `Picatinny-Schiene`, `1913` → `picatinny`).

## Neu in 0.6

- **Variable Produkte:** Einrichtung einmal am Hauptprodukt. Jede kaufbare Variante erscheint einzeln (eigener Preis, Lager). Mittelrohr kommt aus dem Variantenattribut «Mittelrohrdurchmesser [mm]». Im Warenkorb landet die Variante.
- **Pistolen:** Footprints als Schnittstelle (`rmr`, `dpp`, `rms`, `rmsc`, `acro`, `docter`; Schreibweisen wie «Trijicon RMR/SRO» werden vereinheitlicht). Rotpunkt mit «direkt» + Footprint passt auf die Pistole mit gleichem Footprint. Schalldämpfer mit gleichem Mündungsgewinde passen ohne HUB.
- **Gruppe Lampe** (Waffenlicht). Laser und Lampe passen an die Schiene unten oder seitlich (direkt bevorzugt, sonst über Adapterschiene).
- **Bezeichnungen:** «Frontgriff (am Handschutz)» und «Pistolengriff (beim Abzug)». Das Schnittstellen-Feld heisst je nach Rolle «Optik-Aufnahme» oder «Passt auf (…)», die Knöpfe zeigen nur passende Werte (Rest unter «weitere»).
- **Bildauswahl:** «Vorschaubild aus Produktbildern» zeigt Beitragsbild und Galerie, «andere Bilder» die ganze Mediathek.
- **Fehler melden:** Link «Passt etwas nicht? Melden» unter der Vorschau. Die Auswahl wird mitgeschickt, die Meldung geht per E-Mail an den Shop und steht unter WooCommerce → Konfigurator.
