# Armatum PLZ → Ort

Füllt bei Schweizer und Liechtensteiner Adressen nach Eingabe der Postleitzahl den **Ort** automatisch aus.

- **Wo:** Block-Kasse und Block-Warenkorb (Versandrechner), «Mein Konto → Adressen» und klassische Formulare.
- **Ein Ort zur PLZ** (z. B. 4616 → Kappel SO): Er wird eingetragen.
- **Mehrere Orte** (z. B. 6900 → Paradiso, Lugano, Massagno): Der erste wird eingetragen, die anderen stehen im Ort-Feld zur Auswahl.
- **Selbst eingetragene Orte** werden nie überschrieben. Ein automatisch gesetzter Ort wird bei einer neuen PLZ angepasst.
- **Liechtenstein** (9485–9498) wird auch erkannt, wenn als Land «Schweiz» gewählt ist.
- **Daten:** `data/plz.json`, rund 3400 PLZ, etwa 30 KB komprimiert. Die Datei wird nur auf Kasse, Warenkorb und Mein Konto geladen.

Ortsdaten: [GeoNames](https://www.geonames.org/), Lizenz CC BY 4.0, Stand Juli 2026. Postfach-Zusätze sind entfernt («Aarau 1» → «Aarau»).
