# Armatum Konfigurator

WordPress-Plugin für armatum.ch (WooCommerce). Der Konfigurator läuft auf einer eigenen Seite (ab 0.4). Der Kunde wählt dort eine Waffe, dann Optik, Montage und Zubehör, sieht eine massstabsgetreue Vorschau und legt das Paket in den Warenkorb.

- Produktseite einer Waffe: Knopf «Diese Waffe konfigurieren» (öffnet die Seite mit `?waffe=ID`).
- Produktseite von Optik, Montage oder Zubehör: Knopf «Im Konfigurator ansehen» (`?teil=ID`). Passt das Teil nur auf eine Waffe, ist sie gleich gewählt, sonst wählt der Kunde zuerst unter den passenden Waffen. Beim Schalldämpfer wählt der Konfigurator einen passenden HUB (und falls nötig Mündungsaufsatz) mit.
- Jede Gruppe ist ein aufklappbarer Schritt. Ab 7 Artikeln gibt es eine Suche und einen Herstellerfilter (Marke `product_brand` wie im Shop, ohne Marke Attribut «Hersteller» `pa_hersteller`).

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

## Bauhöhe (ab 0.7)

Eine Montage passt nur, wenn die Optik nicht auf der Waffe aufsteht:

**Achshöhe der Montage + Freiraum der Waffe ≥ Tiefe der Optik unter der Rohrachse + 1 mm**

- **Achshöhe:** aus Ankerpunkt (Unterseite) und Rohrmitte der Montage. Steht im Produkt unter dem Bild.
- **Tiefe der Optik:** aus dem Punkt «Tiefster Punkt» (meist Unterkante Objektiv, beim Einmessen automatisch gesetzt). Fehlt er, wird sie aus dem Attribut «Objektivdurchmesser» geschätzt (halber Durchmesser + 4 mm). Ohne beides wird nicht geprüft.
- **Freiraum (Waffe):** wie weit unter der Oberkante der Schiene unter Objektiv und Okular Platz ist. Durchgehende Schiene (AR15) = 0, Repetierer mit Montagebasis z.B. 10–15 mm.

## Neu in 0.8

- **Prüfliste** (WooCommerce → Konfigurator-Prüfliste): jedes eingerichtete Produkt mit Bild und Punkten. «OK» markiert es als geprüft, «Bearbeiten» öffnet das Produkt. Filter: Offen, Mit Bildhinweis, Geprüft, Alle; nach Rolle. Neu eingemessene Produkte gelten wieder als offen.
- **Bildhinweise** beim Einmessen: Hintergrund nicht weiss/einheitlich, Produkt angeschnitten, sehr klein, wohl keine gerade Seitenansicht (lange Produkte zu wenig gestreckt). Gespeichert in `_ak_warn`.
- **REST** `ak/v1` (nur `manage_woocommerce`): `GET /products` (Status, Attribute, Filter role/category/status), `POST /setup` (`ids`, `set` = Felder wie role, gruppe, schnittstelle, laenge …, `measure`, `overwrite`, `dry`), `POST /check`.
- **Zweibein**: Gruppe `zweibein`, Steckplatz «Schiene unten vorne» (sonst Steckplatz unten).

## Neu in 0.9

- **Magnifier:** neue Rolle «Magnifier». Mit eigener Halterung «direkt» ankreuzen (Schnittstelle z.B. `picatinny`), sonst Mittelrohr eintragen, dann braucht er eine Montage der Art «nur Magnifier». Im Konfigurator erscheint er unter der Optik («Magnifier dazu?»), aber nur bei Rotpunkten (direkt, ohne Mittelrohr).
- **Rotpunkt rückt nach vorne:** Mit Magnifier sitzt das Paar (Rotpunkt, 10 mm Abstand, Magnifier) mittig um den Optik-Anker der Waffe, der Rotpunkt vorne Richtung Laufende. An der Waffe lassen sich das hintere und vordere Ende der oberen Schiene setzen. Das Paar wird dann so verschoben, dass es auf der Schiene bleibt. Passt es nicht, wird der Magnifier nicht angeboten.
- **Achshöhe:** neues Feld bei Rotpunkt und Magnifier mit Halterung (Höhe der optischen Achse über der Auflagefläche, z.B. 39 mm absolute co-witness, 49 mm lower 1/3). Bei Magnifier-Montagen kommt sie aus Ankerpunkt und Rohrmitte. Magnifier und Rotpunkt passen nur bei höchstens 1.5 mm Unterschied. Leer = nicht geprüft.
- **Montage-Art:** «Optik mit Mittelrohr» (wie bisher), «nur Magnifier» (erscheint nie bei Zielfernrohren) und «Adapterplatte / Erhöhung für Rotpunkt».
- **Adapterplatten:** Montage-Art «Adapterplatte», Schnittstelle = Schiene (`picatinny`), Ausgang = Footprint (z.B. `rmr`, `acro`, `micro`). Ein Rotpunkt mit Footprint erscheint damit auch auf Gewehren mit Picatinny-Schiene, die Platte wird mitgewählt und ist eine eigene Warenkorbposition. Beim Einmessen ist die «Rohrmitte» die Mitte der Oberseite. Neuer Footprint `micro` (Aimpoint Micro T1/T2).
- **Einmessen:** Schräge Bilder (Hinweis «wohl keine gerade Seitenansicht») oder nicht lesbare Bilder werden automatisch durch das geradeste Galeriebild ersetzt (gespeichert als Vorschaubild). Produkte, die eingemessen, aber nicht vollständig eingerichtet sind, werden nicht mehr als «Schon eingemessen» übersprungen.
- **Kategorien zuordnen:** neue Ziele «Montage nur für Magnifier», «Adapterplatte», «Magnifier mit Halterung», «Magnifier ohne Halterung».
- **REST:** `set` kennt zusätzlich `montage_art`, `achshoehe`, `rail_front`, `rail_rear` (Punkt «x,y»). `status` zeigt `montage_art`, `achshoehe`, `axis_height`.

## Neu in 1.0

- **Waffenart Pistole / Langwaffe:** Feld «Waffenart» an der Waffe (leer = automatisch aus der Kategorie: Pistole/Revolver, sonst Langwaffe). Die Waffenauswahl zeigt Reiter «Langwaffen» und «Pistolen» (`?art=pistole`), sobald es beide gibt. Bei Pistolen heisst der Schritt «Rotpunkt»: nur Rotpunkte (direkt auf den Optik-Schnitt oder über eine Adapterplatte), keine Zielfernrohre und Magnifier. Mündung = Laufgewinde (Kompensator, Adapter), Unterschiene = Lampe.
- **Plattformen:** Feld «Plattform» (ar15, ar10, ak, repetierer, pistole, flinte, andere). Waffe: eine Plattform. Teil: eine oder mehrere, leer = passt auf alle. Ein Teil mit Plattform erscheint nur bei Waffen dieser Plattform.
- **Kaliber-Prüfung:** Mündungsteile passen nur, wenn ihr Kaliber (Bohrung) mindestens so gross ist wie das der Waffe. Kaliber aus Feld «Kaliber», sonst Attribut «Kaliber», sonst Produktname (.308, 6.5 Creedmoor, .30 cal, 9mm …). Unbekannt = keine Einschränkung.
- **Achshöhe von Hand bei Montagen:** überschreibt die Bildmessung (bei Schrägbildern oft zu hoch). Status zeigt «von Hand» oder «gemessen».
- **Vorbelegung aus Attributen:** Schnittstelle, Ausgang, Mündungsgewinde der Waffe und Kaliber zeigen im Produkt-Editor als Platzhalter, was das Plugin aus den Attributen nimmt. Schienenteile nehmen die Schnittstelle aus dem Attribut «Montage» (Picatinny, M-LOK), wenn das Feld leer ist.
- **REST ak/v1/setup:** setzt jetzt auch Bildpunkte (anchor, p1, p2, tube_anchor, bottom, muzzle_device_anchor, slot_*_anchor als «x,y»), Steckplätze slot_*_if, muzzle_device_if, ref_mm, gesperrt, waffenart, plattform, kaliber. Der Status liefert Steckplätze, Punkte mit Bildgrösse, Waffenart, Plattform, Kaliber und gemessene Achshöhe.

## Neu in 1.0.8

- **Frontgriff und Zweibein nur bei Langwaffen:** Pistolen (Waffenart Pistole, auch automatisch aus der Kategorie) bekommen diese Gruppen nicht mehr angeboten.
- **Pistolen- und KK-Schalldämpfer:** Ein Schalldämpfer gilt als Kurzwaffen-Dämpfer, wenn seine Plattform nur `pistole` ist, das Feld «Für Waffenart» auf Kurzwaffe steht oder sein Kaliber nur Pistolenkaliber nennt (z.B. «9x19 mm», «.45 ACP»). Er erscheint nur an Waffen mit Pistolenkaliber (Pistole oder Pistolenkaliber-Karabiner); an Pistolen ohne erkanntes Kaliber ebenfalls. Ein KK-Dämpfer (nur «.22 LR», «.22 WMR», «.17 HMR», «5.6 mm lfB», Randfeuer) erscheint nur an Randfeuerwaffen. Nennt der Dämpfer ein Gewehrkaliber (z.B. «.243 Win», «5.56 / 9mm»), gilt wie bisher nur die Bohrungsregel (Bohrung ≥ Kaliber der Waffe), ein .243-Dämpfer passt also weiter an .22-Waffen. Status (REST) zeigt `kaliber_klasse`, `sd_fuer` und `platzhalter`.
- **Platzhalter für Schrägbilder:** Teile mit Bildhinweis «wohl keine gerade Seitenansicht» (`winkel` in `_ak_warn`) zeigt die Vorschau als grauen Platzhalter in Originallänge (Länge aus den Messpunkten bzw. Feld Länge) mit kurzem Namen statt als Foto. Auswahl, Karte und Warenkorb bleiben normal. Waffen nie als Platzhalter.
- **Herstellerfilter auf Marke:** Der Filter in den Schritten nimmt die Marke (`product_brand`), ohne Marke weiter das Attribut «Hersteller».
- **Cache gezielter:** Nur Änderungen an Konfigurator-Produkten leeren den Cache: `_ak_`-Felder (ausser Bildhinweis, Prüfvermerk, Hinweis, freigestellt), Rolle, Status, Name, Preis, Lager, Bild, Attribute, Kategorie. Änderung an einer Waffe leert nur diese Waffe, Änderung an einem Teil alle. Die Teile-Listen je Rolle sind jetzt auch zwischengespeichert (schnellere erste Berechnung je Waffe). Im Hintergrund werden die 30 meistbesuchten Waffen vorgerechnet (Besuche in Option `ak_visits`).
- **Kaliber-Erkennung:** auch «9x19mm», «9×19 mm», «5.6 mm lfB», «5.7x28»; «5.6 mm» wird nicht mehr als 6 mm gelesen. Tests: `php wordpress/tests/ak-kaliber-test.php` (ohne WordPress).

## Neu in 1.0.9

- **Erwerbs-Zusammenfassung** (Pflicht vor Go-live, Julia 27.11.: sonst irreführend nach Art. 3 Abs. 1 Bst. b UWG): Unter der Zusammenstellung, direkt über «Paket in den Warenkorb», steht «Für diese Zusammenstellung brauchst du mindestens: …», darunter aufklappbar «Je Teil» mit Begründung. Die gleiche Angabe steht im Warenkorb und in der Bestellung bei der Waffe («Erwerb (Paket)»). Jede Karte zeigt die Bedingung des Teils («Erwerb: …»).
  - Grundlage: Attribut «Erwerbsbedingungen» (`pa_erwerbsbedingungen`, Attribut 12) je Teil, strengste Bedingung zählt. Stufen: Ausweiskopie < schriftlicher Vertrag < Strafregisterauszug < WES < Ausnahmebewilligung (Sportschütze/Sammler) < kantonale Ausnahmebewilligung. Ausnahmebewilligungen nach Art. 5 Abs. 2 WG (Schalldämpfer, Laser) stehen je Stück als eigene Angabe.
  - Regeln zentral in `includes/class-ak-erwerb.php` (`AK_Erwerb::evaluate`), Tests `php wordpress/tests/ak-erwerb-test.php` (ohne WordPress):
    - **Schalldämpfer, Laser** (Gruppe Laser, Lampe mit Laser am Namen oder Feld «Laser»), **Nachtsicht-/Wärmebildoptik**: kantonale Ausnahmebewilligung, auch wenn das Attribut fehlt.
    - **Schulterstütze oder Brace an Pistole**: gilt als Handfeuerwaffe (Art. 4a Abs. 1 WV), Magazingrenze 10 statt 20; klappbare/ausziehbare Brace wie Klappschaft (Julia C2).
    - **Magazin** (Waffe: Feld «Magazin», sonst Attribut «Magazinkapazität»; Teil: Feld «Magazin +Schuss» für Verlängerungen) über 20 (Faust) bzw. 10 (Hand) Schuss an halbautomatischer Zentralfeuerwaffe: Ausnahmebewilligung. Randfeuer ausgenommen. Magazine sind heute keine Gruppe im Konfigurator, die Felder sind vorbereitet.
    - **Klapp-/Teleskopschaft an Halbautomat**: Gesamtlänge eingeklappt unter 60 cm = kantonale Ausnahmebewilligung, keine Sportschützen-Ausnahme. Originalschaft: Waffenfeld «Gesamtlänge mit eingeklapptem Originalschaft» (`laenge_eingeklappt`, laut Waffenhersteller). Fremdschaft: nur mit belegter Zusatzlänge beim Schaft (`laenge_eingeklappt`, ab Puffer-/Gehäuseende) plus «Länge ohne Schaft» der Waffe. Fehlt ein Wert oder ist die Waffenlänge nur aus dem Bild geschätzt (und nicht klar unter 50 cm): **«Abklärung durch Armatum»**, nie stillschweigend erlaubt. Feld «eingeklappt schussfähig = nein» (z.B. AR15-Klappadapter) schaltet die Regel ab.
    - **Langwaffe ohne Schulterstütze** (Bauart «kein Schaft»): gesperrt (Art. 33 Abs. 3 WV), auch serverseitig. Der Konfigurator selbst entfernt keinen Schaft, «Keine Schulterstütze» heisst jetzt «Original-Schulterstütze behalten».
    - **Repetierer mit Klapp-/Teleskopschaft oder Brace** (gewählter Schaft oder Originalschaft laut Waffenfeld «Originalschaft»): WES + Ausweiskopie (Pascal 17.11., Julia C5). Bauart unbekannt: «Abklärung durch Armatum». Halbautomat mit Originalklappschaft ohne Längenangabe: «Abklärung durch Armatum».
    - IR-Strahler (X300V, M340V, M640V, KM1), Laserrail-Montagen, Fernschalter und Lampen ohne Laser (P.ID, GTL II) gelten nicht als Laser (Julia C1).
    - Bauart der Schulterstütze, Funktionsweise der Waffe, Brace und Laser werden aus Name/Kategorie/Plattform erkannt, wenn das Feld leer ist; Unbekanntes führt zu «Abklärung durch Armatum».
  - Neue Felder im Editor und in REST `ak/v1/setup`: Waffe `funktion`, `schaft_art` (Originalschaft), `laenge_ohne_schaft`, `laenge_eingeklappt`, `magazin`; Zubehör `schaft_art` (fest/teleskop/klapp/ohne), `laenge_eingeklappt`, `eingeklappt_funktion` (nein), `brace`, `laser` (yes/no), `magazin_plus`. Der Status (`GET /products`) zeigt unter `erwerb` Text und Längen.
- **Teilen-Link**: Die Adresszeile enthält die ganze Zusammenstellung (`?waffe=ID&teile=ID,ID,…`), Knopf «Link kopieren» (mit `einstieg=teilen`). Beim Laden wird sie wiederhergestellt; nicht mehr passende oder unbekannte Teile fallen still weg.
- **GA4-Ereignisse** (nur wenn `gtag` auf der Seite ist, kein Personenbezug, Parameter `einstieg` aus der URL): `konfigurator_auswahl`, `konfigurator_start`, `komponente_gewaehlt` (nur bei Klick), `paket_in_warenkorb`, Standard-`add_to_cart` mit allen Positionen (`item_list_name` Konfigurator), `paket_fehler`, `konfigurator_meldung`, `konfigurator_link_kopiert`; serverseitig `konfigurator_kauf` auf der Bestätigungsseite (einmal je Bestellung, Merker `_ak_ga_gesendet`). Bestellpositionen tragen neu `_ak_group` und `_ak_role`. Das Paket löst weiterhin kein `added_to_cart` aus (sonst doppeltes `add_to_cart`).
- **Produktseiten-Knopf**: Priorität 34 (vor «Vorneigung berechnen»), Links mit `einstieg=produkt`, bei Waffen kein Knopf mehr, wenn die zwischengespeicherte Auswahl leer ist.
- **Feste Vorwärm-Liste**: WooCommerce → Konfigurator → «Immer zuerst vorrechnen» (Option `ak_warm_fixed`, Waffen-IDs), z.B. Kampagnenwaffen. Sie werden nach jeder Änderung vor den 30 meistbesuchten Waffen vorgerechnet, die Einstellungsseite zeigt je ID, ob sie schon vorgerechnet ist.
- **Last**: Sperre gegen gleichzeitiges Neuberechnen derselben Waffe; während ein Prozess rechnet, sehen andere die letzte Auswahl (der Warenkorb prüft immer frisch). Cache-Einträge 7 Tage statt 12 h (Gültigkeit regelt die Version). `?teil=` rechnet höchstens 3 s statt 8 s.

## Neu in 1.0.10

- **Handy-Layout** (Meldung Inhaber 27.11.: «Mobile ist es nicht optimal», Android 412 px):
  - **Keine Überbreite mehr:** Ein langer Name im Schritt-Kopf (z.B. gewählte Optik, einzeilig mit «…») gab dem einspaltigen Raster seine volle Textbreite (`1fr` = mindestens Inhaltsbreite). Die Seite wurde dadurch bis rund 630 px breit, Suchfeld, Herstellerfilter und Karten waren rechts abgeschnitten (auch am Desktop ragte die rechte Spalte leicht über den Rahmen). Neu überall `minmax(0, 1fr)` bzw. `min-width: 0`, Kartentexte brechen um.
  - **Vorschau nicht mehr über Karten und Preisen:** Die Vorschau war auch auf dem Handy `sticky`. Beim Scrollen liefen die Karten darunter durch; durchgestrichene Preise (`del`, vom Theme halbtransparent und damit in eigener Ebene) und der Text unter der Vorschau lagen übereinander. Bis 860 px steht die Vorschau jetzt fest oben in voller Breite, darunter die Schritte einspaltig. Die Bildebenen der Vorschau bleiben in ihrem eigenen Bereich (`isolation`), Vorschau-Block mit weissem Hintergrund.
  - **Filterleiste** (Suchen, Alle Hersteller) bricht um statt zu überlaufen.
  - **Bedienelemente mind. 44 px hoch:** Knöpfe, Suchfeld, Auswahl, Schritt-Köpfe, Karten, «Passt etwas nicht? Melden», «Je Teil»; «Link kopieren» und Meldung brechen um.
  - Auf dem Handy weniger Innenabstand und 6em Abstand nach unten, damit schwebende Theme-Knöpfe (z.B. Warenkorb unten links) am Ende nichts verdecken.
  - Prüfung: `wordpress/tests/ak-mobile/` (Testseite aus `AK_Frontend::render()` mit Beispieldaten, Playwright auf Pixel 7, 360×740 und Desktop 1440: keine Überbreite, keine Überlappung mit der Vorschau, Bedienelemente ≥ 44 px).
