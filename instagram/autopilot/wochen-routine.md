# Irene Autopilot – Wochen-Routine

**Zeitplan:** jeden Sonntag 16:47 (Europe/Zurich)

**Prompt (in die Routine kopieren):**

KONTO-SCHUTZ (Inhaber, 18.10.2026): In Composio sind zwei Instagram-Konten verbunden. Für Armatum ALLE Instagram-Aufrufe NUR mit account «armatum» (username armatum_ch) – nie ohne account-Angabe, nie «weltkriegstagebuch». Vorher prüfen (COMPOSIO_MANAGE_CONNECTIONS, instagram, list), dass «armatum» ACTIVE ist; sonst nichts planen/posten und den Inhaber informieren. Permalinks müssen armatum_ch zeigen. Arbeitsstand liegt auf Branch claude/epic-faraday-somrb6 (git fetch + checkout, dort committen/pushen). Jeder angelegte Veröffentlichungs-Termin muss diesen Konto-Schutz enthalten.

Irene-Autopilot (Inhaber: «Posts automatisch ohne Freigabe», «Ja wöchentlich Autopilot»): Plane und produziere die Instagram-Beiträge für @armatum_ch für die kommende Woche (Mo–So) und richte die automatische Veröffentlichung ein.
1. Lies instagram/redaktionsplan.md, instagram/marke.md, instagram/auswertungen.md und .claude/agents/insta-irene.md (Regeln + Autopilot-Abschnitt).
2. Werte die Insights der letzten Woche aus (Composio instagram, armatum_ch) und notiere sie in auswertungen.md.
3. Lass den Subagenten insta-irene **3 Feed-Beiträge** planen (bereits geplante übernehmen, Lücken füllen), **jeder mit eigenem Foto als Titelbild** (insta-irene.md 4c, Regeln ab 31.10.); fehlt ein Foto: kein Feed-Post, stattdessen Story oder Inhaber um Foto bitten. Kein Grafik-Reel, inkl. Caption, Hashtags und Regel-Check. Nur Beiträge, die den Regel-Check klar bestehen, werden automatisch gepostet; Zweifelsfälle als Frage an den Inhaber.
4. Titelbild jedes Beitrags ist ein echtes Foto (Regel «Echtes Foto als Titelbild» in insta-irene.md 4c). Grafiken mit instagram/grafiken/render.mjs (1080×1350) und WhatsApp-Status mit instagram/whatsapp/status.mjs rendern, committen, pushen; Bild-URLs (raw.githubusercontent.com mit Commit-SHA) auf HTTP 200 prüfen.
5. Pro Beitrag zwei Termine anlegen: Veröffentlichung zum Zeitpunkt (Composio INSTAGRAM_CREATE_CAROUSEL_CONTAINER + INSTAGRAM_POST_IG_USER_MEDIA_PUBLISH, ig_user_id 28780651061546459, Caption exakt aus dem Plan) und +10 Min Sicherheitsnetz (prüfen ob online, sonst nachholen; WhatsApp-Status-Bild schicken und erinnern).
6. Plan aktualisieren, committen, pushen.
7. Dem Inhaber eine kurze Wochenübersicht senden (nur zur Info) und um 2–3 Fotos für die nächste Woche bitten.
8. Kontrolle: Für jeden geplanten Beitrag müssen beide Termine (Veröffentlichung + Sicherheitsnetz) tatsächlich existieren (list_triggers prüfen). Termine nur mit send_later/create_trigger anlegen, die in die Hauptsession session_0146xjFVBC1qpP7rcDCkbcK8 feuern (persistent_session_id), nie mit sitzungsgebundenen Werkzeugen.
Hat der Inhaber «Stopp» gesagt: nichts planen, nichts posten.
