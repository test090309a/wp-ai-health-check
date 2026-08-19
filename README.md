# WP AI Health Check

**Prüft den WordPress-Zustand und analysiert ihn über eine lokale Ollama-HTTP-API.**

## Features
*   🧠 KI-gestützte WordPress-Zustandsanalyse mit lokaler Ollama-Instanz.
*   🔒 Keine externen API-Anfragen – deine Daten bleiben auf deinem Server.
*   📊 Übersichtliche Darstellung von Risiken und Empfehlungen im Admin-Bereich.
*   🏠 Dashboard-Widget für schnellen Überblick.
*   ⚙️ Konfigurierbare Modelle und automatische tägliche Analysen (Cron).

## Voraussetzungen
*   WordPress 6.4 oder höher
*   PHP 8.0 oder höher
*   Eine lokal laufende [Ollama](https://ollama.com/)-Instanz mit einem heruntergeladenen Modell (z.B. `qwen2.5:7b`).

## Installation
1.  Lade das Plugin als ZIP herunter oder klone das Repository in dein `/wp-content/plugins/`-Verzeichnis.
2.  Aktiviere das Plugin im WordPress-Admin-Bereich unter "Plugins".
3.  Gehe zu "Werkzeuge" > "KI Health Check" und konfiguriere die Ollama-URL und das Modell.

## Konfiguration
*   **Ollama HTTP-URL**: Die Adresse deiner Ollama-Instanz (z.B. `http://192.168.0.194:11434`).
*   **Modell**: Wähle ein installiertes Modell aus dem Dropdown-Menü.
*   **Automatische Analyse**: Aktiviere den Cron-Job für tägliche, automatische Checks.

## Mitwirken
Beiträge sind willkommen! Bitte erstelle einen Issue oder Pull Request.

## Lizenz
GPL-2.0-or-later