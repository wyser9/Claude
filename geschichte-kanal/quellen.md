# Material für den Kanal: was wir verwenden dürfen

Stand: 17.10.2026. Keine Rechtsberatung. Bei jedem einzelnen Bild oder Film die Lizenz auf der Quellseite prüfen und im Post nennen.

## Grundregel

Für Bilder und Filme aus dem Ersten und Zweiten Weltkrieg gilt: **Alt heisst nicht automatisch frei.** Frei verwendbar ist Material, wenn
1. es von einer US-Bundesbehörde stammt (Armee, Navy, Signal Corps, Office of War Information): Diese Werke sind in den USA gemeinfrei, oder
2. das Urheberrecht abgelaufen ist (in der Schweiz und der EU meist 70 Jahre nach dem Tod des Fotografen, bei unbekanntem Fotografen 70 Jahre nach der Veröffentlichung), oder
3. eine offene Lizenz es erlaubt (z. B. CC BY oder CC BY-SA, dann mit Namensnennung).

Achtung: Ein Kanal mit Reichweite gilt rasch als **kommerziell** (Sponsoring, Bonusprogramme). Lizenzen mit «nur nicht-kommerziell» (NC) meiden wir deshalb von Anfang an.

## ✅ Gute Quellen

| Quelle | Was es gibt | Lizenz / Hinweis |
|---|---|---|
| **US National Archives (NARA)**, catalog.archives.gov | Fotos und **Filme** (Signal Corps, Navy, Army Air Forces), Wochenschauen der US-Regierung, Karten | Werke der US-Regierung sind gemeinfrei. Ausnahmen (Schenkungen, z. B. Universal Newsreels) sind im Katalog markiert |
| **US National Archives auf YouTube** | Viele Filme in guter Qualität | wie oben. Den Download über den Katalog nehmen, nicht über YouTube |
| **Naval History and Heritage Command** (history.navy.mil) | Fotos der US Navy, Pazifikkrieg, Pearl Harbor | meist gemeinfrei (US Navy) |
| **Library of Congress** (loc.gov) | Fotos, Plakate, Karten, Zeitungen | oft «No known restrictions», pro Objekt prüfen |
| **Wikimedia Commons** | sehr viele Bilder aus allen Ländern | nach Lizenz filtern. **Bundesarchiv** (CC BY-SA 3.0 DE): Nennung «Bundesarchiv, Bild …, Fotograf» nötig |
| **Australian War Memorial** (awm.gov.au) | Fotos WK1 + WK2, viele gemeinfrei | Lizenzfeld pro Bild prüfen |
| **Library and Archives Canada** | Fotos der Kanadier im WK1 (z. B. Passchendaele) | viele gemeinfrei |
| **Imperial War Museums** (iwm.org.uk) | riesige Sammlung | eigene IWM-Lizenz ist **nur nicht-kommerziell**. Britische Amtsfotos («Crown Copyright», vor 1957) sind abgelaufen und meist gemeinfrei, dann die Version von Wikimedia Commons verwenden |
| **Internet Archive** (archive.org) | Filme, u. a. US-Regierungsfilme | nur Material mit klar gemeinfreier Herkunft |
| **Europeana** (europeana.eu) | europäische Archive, viel zum WK1 («Europeana 1914–1918») | nach «Public Domain» oder «CC BY» filtern |
| **Schweizerisches Bundesarchiv / e-pics ETH** | Material zur Schweiz im Krieg (Aktivdienst, Réduit) | Lizenz pro Bild prüfen |
| **Arolsen Archives, Yad Vashem, USHMM** | Dokumente zu Verfolgung und Holocaust | meist nur mit Erlaubnis bzw. Zitatrecht. Inhalte erzählen und auf die Quelle verweisen, statt Bilder zu übernehmen |
| **Feldpostbriefe, Tagebücher** | Zitate für die Serie «Ein Mensch, ein Schicksal» | Autor über 70 Jahre tot → frei. Kurze Zitate mit Quelle sind meist unproblematisch |

## ❌ Nicht verwenden

- **British Pathé, AP Archive, Getty Images, Alamy, Critical Past, Reuters:** Das ist Lizenzmaterial, auch wenn es auf YouTube steht.
- **Ausschnitte aus Dokumentationen, Filmen und Serien** (z. B. «Band of Brothers», «1917», «Im Westen nichts Neues», Netflix-Dokus).
- **Kolorierte Fotos anderer** (z. B. von Marina Amaral oder Royston Leonard): Die Kolorierung ist ein eigenes geschütztes Werk. Wir kolorieren bei Bedarf selbst und kennzeichnen es.
- **Fremde Reels und TikToks**, auch nicht «mit Credit». Credit ersetzt keine Erlaubnis.
- **Musik ausserhalb der Instagram-Bibliothek**, ausser lizenzfreie Musik mit klarer Lizenz (z. B. YouTube Audio Library mit Lizenz «keine Namensnennung erforderlich», Pixabay Music). Beim Creator-Konto ist die Instagram-Musikbibliothek grösser als beim Business-Konto. Auch das spricht für ein Creator-Konto.

## Selbst erstellen (was ich liefern kann)

| Was | Wie | Status |
|---|---|---|
| **Text-Reels** wie das Beispiel 11.11.1918 | `reels/make_reel.py` mit `slides.json` → `reel.mp4` + `cover.jpg` | ✅ funktioniert |
| **Reels mit Archivfotos** (Ken-Burns-Zoom, Text, Quelle) | gleicher Baukasten, ein Bild pro Slide | sobald die Archive erreichbar sind (siehe unten) |
| **Karten** (Frontverläufe, Bewegungen) | eigene Grafiken auf Basis gemeinfreier Karten bzw. Natural-Earth-Daten (gemeinfrei) | auf Anfrage |
| **Voiceover** | Skript schreibe ich; vertont von dir oder über ElevenLabs | Skripte ✅, Ton braucht Zugang |
| **KI-Bilder** | nur für Symbolbilder (z. B. leerer Schützengraben bei Nebel), immer mit Hinweis «KI-generiert», nie als echtes Foto ausgeben | optional |

### Wichtig: Archive sind in dieser Umgebung gesperrt

Die Netzwerk-Richtlinie dieser Cloud-Umgebung blockiert im Moment u. a. `commons.wikimedia.org`, `upload.wikimedia.org`, `catalog.archives.gov`, `archive.org` und `www.loc.gov`. Damit ich Archivfotos und -filme selbst suchen und herunterladen kann, diese Domains freigeben: im Titel der Sitzung das Umgebungsmenü öffnen → *Edit* → *Network access* → unter *Allowed domains* eintragen (Anleitung: https://code.claude.com/docs/en/cloud-environments#network-access). Empfohlene Liste:

```
commons.wikimedia.org
upload.wikimedia.org
catalog.archives.gov
s3.amazonaws.com
archive.org
www.loc.gov
tile.loc.gov
www.history.navy.mil
www.awm.gov.au
api.europeana.eu
```

`s3.amazonaws.com` braucht es, weil NARA seine Dateien dort ablegt.
