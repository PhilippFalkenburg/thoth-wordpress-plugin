# Thoth WordPress Plugin (Phase 1.1)

Dieses Verzeichnis enthält das Phase-1-Grundgerüst für ein WordPress-Plugin, das auf `thoth-client-php` als Referenz- und Client-Bibliothek aufsetzt.

## Struktur

- `thoth-wordpress-plugin.php`: Plugin-Bootstrap für WordPress
- `src/Plugin.php`: zentrale Plugin-Initialisierung
- `src/Admin/SettingsPage.php`: Admin-Einstellungsseite unter **Einstellungen > Thoth Data** mit manuellem Sync
- `src/PostType/PublicationPostType.php`: Registrierung von `thoth_publication`
- `src/Import/WorkImporter.php`: Importservice (Upsert von Works aus Thoth)
- `src/Sync/SyncManager.php`: geplanter Sync (WP-Cron), Cursor-Offset und Sync-Log
- `src/Metadata/NestedMetadataMapper.php`: Normalisierung verschachtelter Metadaten
- `src/Admin/PublicationMetaBox.php`: editierbare Metabox mit wiederholbaren Feldern für verschachtelte Metadaten
- `composer.json`: Einbindung des Referenz-Clients über das offizielle Git-Repository
- `bin/smoke.php`: kleiner CLI-Smoke-Test für Autoload/Klassen

## Lokales Setup

```bash
cd /Users/phil/code/THOTH_Plugin/thoth-wordpress-plugin
composer install
composer run smoke
```

Es ist kein zusätzlicher benachbarter Ordner `thoth-client-php` mehr erforderlich.

## In WordPress verwenden

1. Plugin-Ordner `thoth-wordpress-plugin` in `wp-content/plugins/` bereitstellen.
2. Plugin **Thoth Data Connector** aktivieren.
3. Unter **Einstellungen > Thoth Data** die Basis-Konfiguration setzen.
4. Optional den manuellen Import über **Jetzt synchronisieren** starten.

## REST Exposition

- Der CPT `thoth_publication` ist über die WordPress REST API verfügbar:
  - `/wp-json/wp/v2/thoth_publication`
- Die registrierten `_thoth_*` Meta-Felder sind öffentlich lesbar (REST read), damit Frontends die Metadaten direkt konsumieren können.
- Schreibzugriffe auf Inhalte bleiben weiterhin an Edit-Rechte im WordPress-Backend gebunden.

Für `thoth_publication` ist der Standard-Blockeditor deaktiviert. Die manuelle Pflege erfolgt über Metaboxen (Basisfelder + Nested Metadata).
Das native obere Titel-Feld (`Add title`) ist für diesen Post Type ebenfalls deaktiviert; die Titelpflege läuft über `Full Title` im Abschnitt **Basic Metadata**.

Unter **Einstellungen > Thoth Data** können Felder und Bereiche für die Editor-UI ein-/ausgeblendet werden.
Wichtig: Diese Option beeinflusst nur die Darstellung; ausgeblendete Felder behalten ihren vorhandenen Datenbestand.
Pflichtfelder bleiben immer aktiv, und abhängige Felder werden nur als Gruppe geschaltet (z. B. `First Page` + `Last Page` als `Page Range`).

Die Basisfelder decken jetzt einen erweiterten Thoth-Work-Kern ab (u. a. Work Type/Status, Imprint, DOI, Publication-Date, License, Landing Page, Counts, Notes).
Feldlabels und Eingabefelder enthalten englische Mouseover-Tooltips (`title`), um die Thoth-Bedeutung direkt in der UI sichtbar zu machen.

Zusätzlich gibt es einen Bereich **Related Works** mit:

- `relatedWorkId` (UUID)
- `relationType` (Thoth-Relationstyp)
- `relationOrdinal` (Reihenfolge)

Der Bereich **Related Works** unterstützt zusätzlich einen lokalen Lookup über vorhandene `thoth_publication`-Datensätze:

- Suche nach Titel oder Work ID direkt in der Zeile.
- Auswahl übernimmt automatisch `relatedWorkId`.
- Direkter Link **Open related work** öffnet den verknüpften Datensatz im Bearbeitungsmodus.
- Optionale Checkbox **Automatically maintain inverse relations** setzt beim Speichern Gegenrelationen (z. B. `is_part_of` ↔ `has_part`).
- Duplikat-Schutz verhindert doppelte Gegenrelationen auf Ziel-Datensätzen.
- Nach dem Speichern erscheint eine Info-Notice mit Zählern (`added`/`removed`) für angewendete Gegenrelationen.

Weitere ergänzte Bereiche/Felder:

- **Languages** (`languageCode`, `languageRelation`, `mainLanguage`)
- **Fundings** um `jurisdiction` erweitert
- **Publications** (`publicationType`, `isbn`, `width`, `height`, `depth`, `weight`)
- **Issues** (`issueId`, `issueNumber`, `issueOrdinal`)
- **Series** (`seriesId`, `seriesName`, `seriesOrdinal`)
- **Contributor Affiliations** (`contributionId`, `contributorId`, `institutionId`, `institutionName`, `position`, `affiliationOrdinal`)

## Was Phase 1 bereits kann

- Registriert den Custom Post Type `thoth_publication`
- Importiert Works über `ThothApi\GraphQL\Client::works()`
- Führt Upserts aus (neu/aktualisiert/übersprungen)
- Speichert das vollständige Thoth-Payload als JSON in `_thoth_payload`
- Speichert technische Metadaten: `_thoth_work_id`, `_thoth_doi`, `_thoth_checksum`, `_thoth_last_synced_at`

## Was Phase 1.1 ergänzt

- Plant einen stündlichen WP-Cron-Job bei Plugin-Aktivierung
- Führt Syncs mit Cursor-Offset aus (windowed Import über `limit` + `offset`)
- Zeigt im Admin den Zeitpunkt der letzten Synchronisierung
- Zeigt den nächsten geplanten Cron-Lauf
- Speichert und zeigt ein Sync-Log (letzte Läufe mit Kennzahlen)

## Phase-2-Auftakt: verschachtelte Metadaten

Der Import speichert jetzt zusätzlich strukturierte Felder für spätere Edit-/Exportlogik:

- `_thoth_nested_contributors` (JSON)
- `_thoth_nested_subjects` (JSON)
- `_thoth_nested_fundings` (JSON)
- `_thoth_nested_issues` (JSON)
- `_thoth_nested_series` (JSON)
- `_thoth_contributor_names` (flattened Index-String)
- `_thoth_subject_codes` (flattened Index-String)
- `_thoth_funding_programs` (flattened Index-String)

Hinweis: Diese Felder werden robust aus dem Payload extrahiert, falls die entsprechenden Arrays vorhanden sind. Das kanonische Payload in `_thoth_payload` bleibt weiterhin die primäre Quelle.

Die Metabox unterstützt jetzt zusätzlich einen optionalen Advanced-Modus, um technische Felder (z. B. IDs) pro Bereich ein- oder auszublenden.

Zusätzlich enthält die Metabox jetzt visuelle Abschnittsboxen, responsives Verhalten für kleinere Screens und Feldhinweise (z. B. ORCID/UUID-Format).

Bei Formatfehlern zeigt die Metabox direkt Inline-Feedback pro Feld und verhindert das Speichern, bis die markierten Eingaben korrigiert sind.

## Konfliktmodus (Import vs. manuelle Bearbeitung)

Unter **Einstellungen > Thoth Data** kann die Konfliktstrategie gewählt werden:

- **Manuelle Änderungen schützen (Standard):** Datensätze mit manueller Änderung werden beim Import nicht überschrieben.
- **Import überschreibt manuelle Änderungen:** Import kann lokale Anpassungen ersetzen.

Verwendete technische Flags:

- `_thoth_manual_override`
- `_thoth_manual_updated_at`
- `_thoth_sync_state`
- `_thoth_import_payload`
- `_thoth_import_checksum`

Das Sync-Log zeigt zusätzlich, wie viele Datensätze wegen manuellem Schutz übersprungen wurden.

The metabox now shows the current conflict state:

- **Synchronisiert**
- **Manuelle Änderungen vorhanden**
- **Konflikt: Remote-Update verfügbar**

Wenn ein Import-Snapshot vorhanden ist, kann der Datensatz per Button auf den letzten Importstand zurückgesetzt werden.

Zusätzlich gibt es im Editor eine zweite Aktion:

- **Override-Flag löschen:** entfernt den manuellen Schutz, ohne lokale Felder sofort zu verwerfen. Beim nächsten Import kann der Datensatz wieder aktualisiert werden.
- **Auf letzten Importstand zurücksetzen:** verwirft lokale Änderungen und stellt die zuletzt importierten Metadaten direkt wieder her.

Nach beiden Aktionen erscheint oben auf der Bearbeitungsseite (`wp-admin/post.php`) eine Admin-Notice mit dem Ergebnis.

## Conditional Rules (Work Type / Status)

Die Metabox nutzt jetzt eine zentrale Rule-Matrix für feldabhängige Sichtbarkeit und Speichern:

- UI blendet bestimmte Felder/Abschnitte abhängig von `workType` ein/aus.
- Serverseitig werden dieselben Regeln beim Speichern erzwungen.
- Nicht erlaubte Werte werden verworfen (z. B. `publications` bei `book_chapter`).
- Pflichtfelder werden abgesichert; wenn möglich wird ein bestehender Meta-Wert beibehalten.

Aktuell umgesetzt:

- First/last page (`firstPage`/`lastPage`) nur für `book_chapter` sichtbar/zulässig.
- Page breakdown (`pageBreakdown`) nur für nicht-`book_chapter`-Work-Types sichtbar/zulässig.
- Publications (`publications`) nur für `monograph`, `edited_book`, `textbook`, `journal_issue`, `book_set`.
- Issues (`issues`) nur für `journal_issue` sichtbar/zulässig.
- Series (`series`) nur für nicht-`book_chapter`-Work-Types sichtbar/zulässig.
- Bei `workStatus = active` wird `publicationDate` als Pflichtfeld behandelt.

Erweiterte Nested-Section-Regeln:

- Bei `workStatus = active` wird für sichtbare Work Types mindestens eine Publication-Zeile erwartet.
- Bei `workType = journal_issue` wird mindestens eine Issue-Zeile erwartet.
- Für `workType = book_set` wird mindestens eine Series-Zeile erwartet.
- Wenn ein Pflichtbereich leer gespeichert würde, wird (falls vorhanden) auf bestehende Meta-Werte zurückgefallen.

Wenn Regeln beim Speichern eingreifen, erscheint eine Info-Notice im Editor.

## Cover Image via WordPress Media

Im Bereich **Basic Metadata** kann das Cover jetzt direkt über die WordPress-Medienbibliothek gewählt oder hochgeladen werden:

- Button **Select / Upload Cover** öffnet den WP Media Picker (nur Bilder).
- Gewähltes Bild wird sofort als Vorschau angezeigt.
- Der Button **Remove Cover** entfernt Bild-URL und Attachment-Verknüpfung.
- Weiterhin kann alternativ manuell eine `coverUrl` eingetragen werden.

Technische Speicherung:

- `_thoth_cover_url` bleibt die führende URL für Thoth-Payload/Export.
- `_thoth_cover_attachment_id` speichert optional die lokale WP-Attachment-ID.



## Nächste Schritte (Phase 2+)

- Delta-Sync über WP-Cron
- Export-Logik zurück zu Thoth

## Third-Party Licenses

- Bundled dependency: `thoth-pub/thoth-client-php` (Apache-2.0). Their license and NOTICE files are included under `vendor/` where present. When redistributing, keep those license/NOTICE files intact to preserve attribution.

Proudly vibe coded!
