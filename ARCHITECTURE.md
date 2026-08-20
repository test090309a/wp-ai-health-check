# WP AI Health Check – Programmablauf (Mermaid)

## Hauptablauf: Analyse starten

```mermaid
sequenceDiagram
    participant U as 👤 Admin-Benutzer
    participant A as AdminPage
    participant R as RestController
    participant H as HealthCollector
    participant O as OllamaClient
    participant AI as Ollama-Server
    participant D as AnalysisStore (DB)

    U->>A: Klickt "Jetzt analysieren"
    A->>R: POST /wpaic/v1/analyze
    R->>O: Prüfe Ollama-Verfügbarkeit
    O-->>R: ✅ erreichbar

    R->>H: Sammle WordPress-Zustand
    H-->>R: Array (Plugins, Theme, Versionen, DB, …)

    R->>O: Sende Prompt + Daten an Ollama
    O->>AI: POST /api/chat (JSON-Format)
    AI-->>O: KI-Antwort (JSON)
    O-->>R: decodiertes Ergebnis

    R->>D: Speichere Analyse in DB-Tabelle
    R->>R: Setze Transient + Option
    R-->>U: JSON: {success: true, result, duration_ms}
    U->>U: Seite wird neu geladen → Anzeige
```

## Automatischer Cron-Job (täglich)

```mermaid
flowchart LR
    WP[WordPress Cron-Dispatcher] -->|wp-cron.php| CH[Cron::run_check]
    CH --> HC[HealthCollector::collect]
    HC -->|Zustandsdaten| OC[OllamaClient::chat]
    OC -->|Prompt + Daten| OLL[Ollama-Server]
    OLL -->|KI-Analyse JSON| OC
    OC -->|Ergebnis| CH
    CH --> DB[(AnalysisStore)]
    CH --> TR[Transient + Option]
    CH -->|Bei hohen Risiken| EM[📧 E-Mail an Admin]
```

## Dashboard-Widget-Ladung

```mermaid
flowchart TD
    DW[DashboardWidget::render_widget] --> DB[(AnalysisStore::latest)]
    DB -->|Eintrag da?| RES{Ergebnis}
    DB -->|Nein| TR[get_transient]
    TR --> RES
    RES -->|Ja| DISP[Formatierte Anzeige:<br/>Summary, Risiken, Empfehlungen]
    RES -->|Nein| EMPTY[Platzhalter: 'Noch keine Analyse']
    DISP --> BTN[Button: 'Vollständige Analyse'<br/>Button: 'Neu analysieren']
    BTN -->|Neu analysieren| AJAX[POST /wpaic/v1/analyze]
    AJAX -->|Erfolg| RELOAD[location.reload]
```

## Datenpersistenz-Übersicht

```mermaid
flowchart TD
    SUBMIT[Analyse abgeschickt] --> STORE[(wp_wpaic_analyses)]
    SUBMIT --> TRANS[wp_options:<br/>wpaic_last_result<br/>wpaic_last_run]
    STORE -->|SELECT latest| ADM[AdminPage render_page]
    STORE -->|SELECT limit 20| HIST[Historie-Tabelle]
    STORE -->|DELETE| CLEAN[Ajax Delete]
    TRANS -->|get_transient| DASH[DashboardWidget]
    TRANS -->|get_option| ADM
    CLEAN --> STORE
```

## Tabellen-Schema

```mermaid
erDiagram
    wp_wpaic_analyses {
        bigint id PK auto-increment
        datetime run_at
        varchar model
        int duration_ms
        varchar status
        text error_message
        longtext result_json
    }
```
