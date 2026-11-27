# Datenvorgaben armatum.ch (Entwurf v0.1, 27.11.2026)

Verantwortlich: Dana (Daten-Governance, Shop-Team Salome). Gilt für Paul-Team (neue Artikel) und Sina (Stammdaten/Bestand).
Wird später Skill `armatum-datenvorgaben`. Ergänzt `armatum-shopzugriff`, ersetzt ihn nicht.

Markierungen:
- **[B: Quelle]** = bestehende Regel, schon vom Inhaber freigegeben oder im Skill.
- **[V]** = Vorschlag, braucht Freigabe Pascal.

Stand Shop (gelesen 27.11.2026): 87 Attribute, 356 Kategorien (183 Herstellerkategorien unter «Hersteller» 122, rund 170 Produktkategorien), 184 Marken (product_brand).

---

## 1. Grundregeln

| # | Regel | Status |
|---|---|---|
| G1 | Schweizer Rechtschreibung, «ss» statt «ß», auch in Titeln, Attributen, Kategorien | [B: armatum-marke] |
| G2 | Gegenüber Pascal Artikel mit Artikelnummer (SKU) + Titel nennen, nie Shop- oder Media-ID | [B: kommunikation-pascal] |
| G3 | Neue Produkte sofort veröffentlichen, ausser kritisch: kein/falscher Preis, kein Bild, Herstellerkategorie oder Marke fehlt, Erwerbsbedingung unklar. Fehlendes Gewicht ist nicht kritisch | [B: produkte-direkt-veroeffentlichen] |
| G4 | Jede technische Angabe aus dem Text auch als Attribut | [B: attribute-aus-text] |
| G5 | Fachbegriffe korrekt (Magazinauslöser, nicht Magazinhalter) | [B: armatum-marke] |
| G6 | Ohne Lager: Lagerstatus «auf Bestellung» (onbackorder) | [B: armatum-shopzugriff] |

## 2. SKU-Regeln

| # | Regel | Status |
|---|---|---|
| S1 | SKU = Hersteller-Artikelnummer, nie Lieferanten-Nr.; bei zwei Hersteller-Nr. die erste | [B: armatum-shopzugriff] |
| S2 | Occasion-Waffen: SKU (und mpn) = Seriennummer | [B: occasionen] |
| S3 | G+E-Artikel: SKU = G+E-Nr. (z. B. 14.9510), auch für Gehmann/Centra über G+E | [B: gruenel-elmiger-artikel] |
| S4 | Hersteller ohne Artikelnummer (z. B. RAD Solutions): eigene SKU nach Muster `SRX.000.10xx`, Variante mit Suffix (`-L`) | [B: produktdaten-bereinigung, 17.11.] |
| S5 | Burris: SKU = «DB» + Burris-Nr. (z. B. DB200626) | [B: zf-verstellbereich] (Ausnahme zu S1) |
| S6 | Occasion ohne Seriennummer (Zubehör, Wiederladen): Hersteller-Nr. bzw. Kürzel + `-OCC` (z. B. 8038RNG1SZ-OCC, RCBS-PARTNER-OCC) | [V] (heute so gelebt, nie als Regel freigegeben) |
| S7 | Erlaubte Zeichen: A–Z, 0–9, `-`, `.`, `/`; keine Leerzeichen, Grossbuchstaben wie beim Hersteller | [V] |
| S8 | SKU-Änderung nur mit Meldung an Pascal (bexio muss nachgeführt werden) | [B: produktdaten-bereinigung] |

## 3. Namensschema je Artikeltyp

Allgemein: `Marke Modell [Variante] [Hauptmerkmale] [Farbe]`. Marke wie Markenliste (Abschnitt 9). Keine Artikelnummer im Titel, kein «NEU», keine Preise. Masse mit Punkt als Dezimaltrenner. Status aller Schemata: **[V]**, ausser wo vermerkt.

| Typ | Schema | Beispiel |
|---|---|---|
| Waffe | Marke Modell Kaliber Lauflänge [Schaft/Magazin] Farbe | Ruger Mini-14 Tactical .223 REM 16.1" Klappschaft 20 Schuss Schwarz (Schaft + Schuss im Namen: [B: produktdaten-bereinigung, Mini-14]) |
| Optik ZF | Marke Serie Vergrösserung x Objektiv Absehen Bildebene Winkelmass | Sig Sauer TANGO6 5-30x56 MRAD Milling FFP |
| Optik Rotpunkt | Marke Modell Punktgrösse MOA Farbe | Holosun 509T 2 MOA Rot |
| Montage | Marke Typ Rohrdurchmesser Bauhöhe Schnittstelle | ERATAC T7 Blockmontage kurz 30mm H 23mm Picatinny |
| Munition | Marke Linie Kaliber Geschossgewicht gr Geschosstyp, Packung Stk | Hornady Frontier .223 REM 55 gr FMJ, 20 Stk |
| Wiederladen Geschoss | Marke Kaliber (Durchmesser) Gewicht gr Typ, Packung Stk | Hornady .224 55 gr V-MAX, 100 Stk |
| Wiederladen Matrize/Werkzeug | Marke Produktart Kaliber | Hornady Neck Size Die 6.5mm |
| Zubehör | Marke Modell Produktart Kompatibilität Farbe | Magpul PMAG 30 AR/M4 Gen M3 5.56x45 Schwarz |
| Occasion | Marke Modell Kaliber [Besonderheit] «Occasion» | SIG P210 ex Armee 9mm Para Occasion |

## 4. Pflichtfelder je Typ

Legende: P = Pflicht (kritisch, sonst Entwurf), Q = Pflicht für «vollständig» (Audit), – = nicht nötig.

| Feld | Waffe | Optik | Montage | Munition | Wiederladen | Zubehör | Occasion |
|---|---|---|---|---|---|---|---|
| Preis | P | P | P | P | P | P | P |
| Hauptbild ≥ 500 px | P | P | P | P | P | P | P (eigene Fotos) |
| Herstellerkategorie + Produktkategorie + Marke | P | P | P | P | P | P | P (+ 3628) |
| Erwerbsbedingungen (12) | P | – | – | P | Pulver/Zündhütchen Q [V] | Schalldämpfer P | P |
| Gewicht (WC-Feld, kg) | Q | Q | Q | Q | Q | Q | Q |
| GTIN | Q | Q | Q | Q | Q | Q | – (condition used) |
| Kaliber (11) | P | – | – | P | Q | Q falls kaliberbezogen | P |
| Lauflänge (25) | Q | – | – | – | – | – | Q |
| Magazinkapazität (27) | Q | – | – | – | – | Q bei Magazinen | Q |
| Farbe (5) | Q | Q | Q | – | – | Q | Q |
| Optik: Vergrösserung 18, Objektiv 42, Mittelrohr 48, Absehen 30, Bildebene 96, Winkelmass 67, Verstellbereich 97/98 | – | Q (ZF) | – | – | – | – | – |
| Rotpunkt: Montage/Footprint (32) | – | P [B: footprint-schreibweise] | – | – | – | – | – |
| Montage: Mittelrohr 48, Höhe opt. Achse 88, Montage 32, Material 6 | – | – | Q [B: attribute-aus-text] | – | – | – | – |
| Munition: Geschossgewicht 23, Geschosstyp 61, Packungsgrösse 10, Bleifrei 92 | – | – | – | Q | Q (Geschosse) | – | – |
| Kompatibilität (41) | – | – | Q | – | – | Q | – |
| Mündungsgewinde (38) | Q | – | – | – | – | Q bei Mündungsaufsätzen | Q |
| Zustand/Lieferumfang im Text, GLA condition used, adult yes, Bestand 1, Versandklasse signature-waffe | – | – | – | – | – | – | P [B: occasionen] |

Die Spalte Q ist **[V]** bis auf die markierten Stellen. «Vollständig» im Audit = alle P und Q erfüllt.

## 5. Attribute und erlaubte Werte

Alle 87 Attribute sind Typ «Auswahl» (select). Neue Begriffe nur anlegen, wenn kein bestehender passt (vorher Termliste prüfen).

| ID | Attribut | Format / erlaubte Werte | Status |
|---|---|---|---|
| 5 | Farbe | Typ bleibt «Auswahl», nie «color» ohne Farbcodes. Deutsche Grundfarbe oder Herstellerfarbe mit etablierter Bezeichnung: Schwarz, FDE - Flat Dark Earth, Olive Drab Green, Coyote, Grau, Grün, Ranger Green, Tan, MultiCam, Silber … | Typ [B: attribut-farbe-swatches]; Werteliste [V] |
| 11 | Kaliber | Schreibweise wie Leitterm: `9x19mm / 9mm Para`, `.223 REM / 5.56x45mm`, `.308 Win / 7.62x51mm`, `.22 LR`, `6.5mm Creedmoor`, `7.62x39mm`. Diabolos: `4.5 mm`. Schrot: Attribut 94 Schrotkaliber, nicht 11 | [V] (214 Terms, uneinheitlich) |
| 12 | Erwerbsbedingungen | nur die 8 bestehenden Terms (siehe 5.1) | [B: occasionen, schalldaempfer-erwerb] |
| 19 | Gewicht [g] | Zahl in g ohne Einheit im Term (Herstellerangabe netto) | [B: attribute-aus-text] |
| 13 | Länge [mm] | Zahl in mm | [B] |
| 25 | Lauflänge | `Zoll" / mm` (z. B. `16.1" / 409mm`), Zoll mit geradem `"` (nicht `″`), mm = Zoll × 25.4 gerundet auf ganze mm | Format [B: produktdaten-bereinigung, Term 1715]; Rundung + Zeichen [V] |
| 23 | Geschossgewicht | `55 gr` (Grain, Leerzeichen, «gr») | [V] |
| 32 | Montage | Footprints nur: Picatinny, Shield RMSc, Trijicon RMR, Leupold DeltaPoint Pro, Aimpoint ACRO, Aimpoint Micro, Docter, SIG Pro RX, SIG-LOC COMPACT, SIG-LOC PRO, Holosun 509, Glock MOS, CZ P-10 OR, Walther PDP, HK VP9 OR, S&W M&P 2.0 OR; dazu M-LOK, Arca, RRS Dovetail, RRS BTC, ARMS 17, Sling Swivel | [B: footprint-schreibweise, attribute-aus-text] |
| 41 | Kompatibilität | Waffenmodell wie Hersteller (AR15, Tikka T3x, Stgw90 …) | [B: attribute-aus-text, gruenel-elmiger-artikel] |
| 48 | Mittelrohrdurchmesser [mm] | 30, 34, 35, 36 (Zahl), Pflicht bei allen Ringen/Rohrmontagen | [B: attribute-aus-text] |
| 67 | Winkelmass | MOA, MRAD | [B: attribute-aus-text] |
| 78 | Vorneigung MOA | Zahl MOA | [B] |
| 88 | Höhe optische Achse [mm] | mm mit einer Dezimale (1.00" = 25.4) | [B: attribute-aus-text] |
| 96 | Bildebene | FFP (1. Bildebene), SFP (2. Bildebene) | [B: attribute-aus-text] |
| 97 / 98 | Verstellbereich Höhe / Seite | `Zahl Einheit` in Herstellereinheit: `26 MRAD`, `100 MOA`, `235 cm` | [B: zf-verstellbereich] |
| 6 | Material | Aluminium, Stahl, Titan, Polymer, Kohlefaser, Gummi | [B: attribute-aus-text] |
| 10 | Packungsgrösse | Zahl Stück | [B] |
| 38 | Mündungsgewinde | `M14x1 LH`, `1/2"-28 UNEF`, `5/8"-24` (Steigung immer angeben) | [V] |

### 5.1 Erwerbsbedingungen (Attribut 12)

| Fall | Terms | Status |
|---|---|---|
| Pistole, normaler Halbautomat | Ausweiskopie + WES (10701) bzw. gültige Ausweiskopie (272) + WES (617) | [B: occasionen] |
| Halbautomat mit grossem Magazin | Ausweiskopie + Kleine Ausn.-Bewilligung Sportschütze (649) + Ausnahmebewilligung klein (Sammler) (10794) | [B: occasionen] |
| Repetierer fester Schaft | Ausweiskopie + Strafregisterauszug (271) | [B: occasionen] |
| Repetierer Klappschaft | Ausweiskopie + WES | [B: occasionen] |
| Schalldämpfer, Halbautomat < 60 cm kürzbar, integrierter SD | Ausweiskopie + kantonale Ausnahmebewilligung (658); Mündungsbremse/Feuerdämpfer/Druckumlenker nicht | [B: schalldaempfer-erwerb, occasionen] |
| Schreckschuss/Imitation/Soft-Air verwechselbar | Ausweiskopie + schriftlicher Vertrag, ab 18 Jahren (11242) | [B: occasionen] |
| Munition | Regel fehlt im Memory | offen, Werberegeln-Skill prüfen [V] |
| Einheitlich nur Einzelterms (272 + 617) statt Kombiterm 10701 | Kombiterm auslaufen lassen | [V] |

## 6. Kategorieregeln

| # | Regel | Status |
|---|---|---|
| K1 | Jedes Produkt: Herstellerkategorie (unter «Hersteller» 122) + genau eine Produktkategorie (tiefste passende Ebene) + Marke (product_brand) | [B: armatum-shopzugriff]; «genau eine» [B: eratac] für ERATAC, allgemein [V] |
| K2 | Fehlt die Herstellerkategorie: neu anlegen unter 122, Slug = Markenname, zugleich Marke anlegen | [B: produktdaten-bereinigung, occasionen] |
| K3 | Occasionen: Hersteller + 3628 + passende Waffen-/Zubehörkategorie (z. B. 615 Pistolen) | [B: occasionen] |
| K4 | Kategorie 11005 und andere Nicht-Google-Kategorien müssen in Snippet 15 (armatum_gla_cat_ids) stehen | [B: merchant-center-probleme] |
| K5 | «Sale %» (6390) nur als Zusatzkategorie während Aktionen, nie allein | [V] |
| K6 | «Unkategorisiert» (16) muss 0 bleiben | [V] |
| K7 | Keine neuen Produkte in «Zubehör» (104); Patch/Fahne/Batterie/Kompressor nach «Ausrüstung» bzw. «Waffenzubehör» verschieben | [V] |
| K8 | Munitions-Unterkategorien gleich schreiben wie Attribut Kaliber (z. B. `.22 LR` statt `.22lr`, `.357 Magnum` statt `357 Magnum`, `.40 S&W`, Schrot `12/76` statt `12"`) | [V] |
| K9 | Leere Herstellerkategorien (z. B. Black Trident, Nextorch, Elcan, 365+, Dillon, HIKMICRO, Smith & Wesson, Brownells, Opinel) nicht löschen, im Audit führen | [V] |

## 7. Bildregeln

| # | Regel | Status |
|---|---|---|
| B1 | Hauptbild mindestens 500 × 500 px (Google ab 31.01.2027), Ziel 800 px und mehr | [B: armatum-shopzugriff, merchant-center-bilder] |
| B2 | Kleiner als 500 px auf einer Seite: weiss auf Quadrat auffüllen (`*-quadrat.jpg`), unter 400 px neues Bild | [B: merchant-center-bilder] |
| B3 | Import per URL in die Mediathek, Dateiname = Artikelnummer (`<SKU>-1.jpg`, `-2` …) | [B: armatum-shopzugriff]; Nummerierung [V] |
| B4 | Dateinamen ohne Sonderzeichen («–», «®», Umlaute, Leerzeichen) | [B: merchant-center-probleme] |
| B5 | Fehlende Bilder dürfen mit Tintloom erzeugt werden (Credits) | [B: MEMORY 25.11.] |
| B6 | Occasionen: eigene Fotos, keine Herstellerbilder; Wasserzeichen fremder Inserate entfernen | [B: occasionen]; «keine Herstellerbilder» [V] |
| B7 | Bild muss exakt die Variante zeigen (Farbe, Höhe); sonst Hinweis an Pascal | [B: eratac] |
| B8 | Hauptbild weisser oder neutraler Hintergrund, Produkt freigestellt, kein Text/Logo darüber | [V] |

## 8. Preise, Gewicht, GTIN

| # | Regel | Status |
|---|---|---|
| P1 | VK = Händler-VK (Schild) minus 0.10 | [B: armatum-shopzugriff] |
| P2 | ERATAC: erst minus 0.10, dann ab CHF 100 auf nächstkleinere 9.– (451.60 → 449.00), unter 100 auf nächstkleinere ,90 (78.10 → 77.90) | [B: eratac, 23.11.] |
| P3 | Occasionen: Preis wie angegeben, kein −0.10 | [B: occasionen] |
| P4 | gruenel.ch-Preise sind EK: nie publizieren, Entwurf ohne Preis bis VK-Liste | [B: gruenel-elmiger-artikel] |
| P5 | ERATAC-Rundung auf alle Lieferanten ausweiten? | [V] (offen, nur Frage) |
| W1 | Gewicht im WC-Feld in kg; Versandgewicht = netto + max(40 g, 10 %), aufgerundet auf 10 g, min. 0.05 kg | [B: produktgewichte] |
| W2 | Platzhalter 6666/7777/8888 verboten (wurden am 18.11. ersetzt) | [B: produktgewichte] |
| W3 | Fehlendes Gewicht blockiert die Veröffentlichung nicht, wird aber im Audit gemeldet und innert 7 Tagen geschätzt | erster Teil [B]; Frist [V] |
| E1 | GTIN nur echte EAN/UPC (12/13 Stellen, mit führender 0), nie eigene Artikelnummer, nie raten (Hornady 4/6-stellig) | [B: merchant-center-probleme, produktgewichte] |
| E2 | Speicherort GTIN: ein Feld festlegen | [V] (Widerspruch, siehe unten) |

## 9. Schreibweisen-Liste

| Falsch / uneinheitlich | Richtig | Status |
|---|---|---|
| Magazinhalter (für Release) | Magazinauslöser | [B: produktdaten-bereinigung] |
| Gross, Grösse mit ß | Gross, Grösse | [B: armatum-marke] |
| RMSc Footprint (Shield) | Shield RMSc | [B: footprint-schreibweise] |
| Acro Footprint | Aimpoint ACRO | [B: footprint-schreibweise] |
| SIG, Sig, Sig Sauer | SIG Sauer (Marke), Modell wie Hersteller (TANGO6, P320) | [V] |
| B&T, Brügger + Thomet | Brügger & Thomet (Marke), «B&T» im Titel erlaubt | [V] |
| OD Green, Oliv | Olive Drab Green | [V] |
| Coyote Brown / Coyote Tan / Coyote | je Hersteller-Farbname behalten, aber nicht Coyote = Coyote Brown doppelt | [V] |
| Matte Black | Schwarz | [V] |
| Dark Gray / Stealth Gray (Grundfarbe) | Grau, ausser Herstellerfarbname | [V] |
| ″ (Zollzeichen typografisch) | " | [V] |
| .22lr, 22LR | .22 LR | [V] |
| 9mm, 9mm Para, 9x19 | 9mm Para / 9x19mm (Titel kurz: 9mm Para) | [V] |
| Lenght of pull (Auszugslänge) (Attribut 37) | Auszugslänge (Length of Pull) [mm] | [V] |
| TCH «B» | Nickelschwarz; ohne B = vernickelt (Farbe Silber) | [B: produktdaten-bereinigung] |

## 10. Durchsetzung und Audit

- Paul-Team (neue Artikel) und Sina (Bestand) arbeiten nach diesem Dokument. Abweichungen sammelt Dana in einer Liste; Dana ändert keine Daten. [Auftrag]
- Monats-Audit, Kennzahl «vollständige Artikel in %» je Produktkategorie; Listen: ohne Bild, Bild < 500 px, ohne Gewicht, ohne Pflichtattribute, ohne GTIN, falsche/fehlende Kategorie. Sortiert nach Umsatz. Neue Artikel an Paul, Bestand an Sina. [Auftrag]
- Regeln, die bestehende Inhaber-Regeln ändern, gehen zuerst an Pascal.

## Offene Widersprüche (Stand 27.11.)

1. **GTIN-Feld:** Skill sagt `_wc_gla_gtin`, Memory 12. und 16.11. schreibt `global_unique_id` (WooCommerce-Kernfeld). Ein Feld festlegen.
2. **SKU = Hersteller-Nr.** gilt nicht bei G+E (G+E-Nr. auch für Gehmann/Centra), Burris (Präfix DB), RAD (eigene SKU), Occasionen ohne SN (-OCC). Ausnahmen offiziell machen oder angleichen.
3. **Gewicht:** «nicht kritisch» vs. Google-Fehler missing_shipping_weight; ausserdem neue Occasion SIG-P220-MAG-OCC (26.11.) wieder mit Platzhalter 6666 angelegt.
4. **Doppelte Herstellerangabe:** Herstellerkategorie + Marke + Attribut Hersteller (2) – drei Stellen, keine Regel für Attribut 2.
5. **Gewicht doppelt:** WC-Feld (kg, Versandgewicht) und Attribut Gewicht [g] 19 (netto); dazu MOA 17 vs. Winkelmass 67.
