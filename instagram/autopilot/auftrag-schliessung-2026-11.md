# Auftrag an Irene: Sonderschliessung 05.11. und 19.11.2026

**KORREKTUR Inhaber 08.11.2026:** Do 12.11. ist offen (17–21). Geschlossen ist stattdessen **Do 19.11.** Nächste Öffnung danach: Sa 21.11., 9–13.

Auftraggeber: Inhaber, 28.10.2026 («setz alles um … Auftrag an Irene für die Storys erteilen»).
Samstag 07.11. wie gewohnt offen (9–13). Do 12.11. offen (17–21). Sa 14.11. regulär geschlossen (Zwei-Wochen-Rhythmus). Sa 21.11. offen (9–13).

| Termin | Story-Bild (instagram/stories/schliessung-2026-11/) | WhatsApp-Status an Inhaber |
|---|---|---|
| Mo 02.11. 10:00 | 01-ankuendigung.jpg | ja |
| Mi 04.11. 18:00 | 02-morgen-05.jpg | ja |
| Do 05.11. 08:00 | 03-heute-05.jpg | ja |
| So 08.11. sofort | 06-korrektur.jpg («Do 12.11. wie gewohnt offen») | ja |
| Mi 18.11. 18:00 | 04-morgen-19.jpg | ja |
| Do 19.11. 08:00 | 05-heute-19.jpg | ja |
| Fr 20.11. 09:00 | – | Hinweis im Footer von armatum.ch (Widget block-12, 3. Listenpunkt «Geschlossen: …») wieder entfernen |

Ablauf pro Story-Termin: Konto «armatum» prüfen (aktiv, armatum_ch) → INSTAGRAM_POST_IG_USER_MEDIA (media_type STORIES, image_url = raw-URL mit Commit-SHA aus dem Termin) → INSTAGRAM_POST_IG_USER_MEDIA_PUBLISH → Bild per SendUserFile an den Inhaber (für WhatsApp-Status) + kurze Chat-Meldung mit Bitte, die Story ins Highlight «Laden» zu legen (API kann keine Highlights) → Eintrag im Änderungsprotokoll, commit/push.
Regeln an diesen Tagen: keine «Heute offen»-Story, keine Einladung in den Laden (siehe marke.md «Sonderschliessungen»).
Erledigt ausserdem: Footer-Hinweis auf armatum.ch (28.10.), Aushang neu `marketing/aushang/aushang-geschlossen-2026-11-19.pdf` (alter Aushang 5./12.11. ungültig), Newsletter-Satz bei Mark. Google-Unternehmensprofil: Inhaber.
