# Irene Autopilot – Wochen-Routine

**Zeitplan:** jeden Sonntag 16:47 (Europe/Zurich)

**Prompt (in die Routine kopieren):**

Irene-Autopilot (Inhaber: «Posts automatisch ohne Freigabe», «Ja wöchentlich Autopilot»): Plane und produziere die Instagram-Beiträge für @armatum_ch für die kommende Woche (Mo–So) und richte die automatische Veröffentlichung ein.
1. Lies instagram/redaktionsplan.md, instagram/marke.md, instagram/auswertungen.md und .claude/agents/insta-irene.md (Regeln + Autopilot-Abschnitt).
2. Werte die Insights der letzten Woche aus (Composio instagram, armatum_ch) und notiere sie in auswertungen.md.
3. Lass den Subagenten insta-irene 3–4 Feed-Beiträge planen (bereits geplante übernehmen, Lücken füllen; Beiträge mit fehlendem Material des Inhabers durch Text-/Grafik-Beiträge ersetzen), inkl. Caption, Hashtags und Regel-Check. Nur Beiträge, die den Regel-Check klar bestehen, werden automatisch gepostet; Zweifelsfälle als Frage an den Inhaber.
4. Grafiken mit instagram/grafiken/render.mjs (1080×1350) und WhatsApp-Status mit instagram/whatsapp/status.mjs rendern, committen, pushen; Bild-URLs (raw.githubusercontent.com mit Commit-SHA) auf HTTP 200 prüfen.
5. Pro Beitrag zwei Termine anlegen: Veröffentlichung zum Zeitpunkt (Composio INSTAGRAM_CREATE_CAROUSEL_CONTAINER + INSTAGRAM_POST_IG_USER_MEDIA_PUBLISH, ig_user_id 28780651061546459, Caption exakt aus dem Plan) und +10 Min Sicherheitsnetz (prüfen ob online, sonst nachholen; WhatsApp-Status-Bild schicken und erinnern).
6. Plan aktualisieren, committen, pushen.
7. Dem Inhaber eine kurze Wochenübersicht senden (nur zur Info).
Hat der Inhaber «Stopp» gesagt: nichts planen, nichts posten.
