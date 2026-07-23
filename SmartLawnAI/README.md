# SmartLawn AI

KI-gestützte Bewässerungssteuerung für Rasenflächen, die Wetterdaten, Bodenfeuchte und smarte Algorithmen vereint.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* KI-gestützte Berechnung von optimalen Bewässerungszeiten über Google Gemini (via Smart Gemini IO).
* Berücksichtigung von globaler Sensorik (Lufttemperatur, Luftfeuchte, Helligkeit).
* Kostenlose Integration von Open-Meteo Wetterdaten für präzise Regenvorhersagen.
* Verwaltung mehrerer Beregnungskreise (Zonen) mit jeweils zugewiesenen Feuchtesensoren.
* Intelligente Unterstützung von Sickerpausen (Soak) und einer maximalen Bewässerungsdauer.
* Globale Bewässerungssperrzeiten, um Bewässerungen z.B. tagsüber zu verhindern.
* Detailliertes Protokoll (Bewässerungs-Log) und Effizienz-Tracking pro Zone.
* Unterstützt Automatikmodus sowie manuelle Trigger (Force Start).

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0
* Eingerichtete "Smart Gemini IO" Instanz für die KI-Auswertung.

### 3. Installation

* Über den Module Store das Modul `SmartLawn AI` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartUtilities`

### 4. Konfiguration

* **Bewässerungs-Zeitplan (IrrigationSchedule)**: Wie oft die KI pro Tag prüfen soll, ob gewässert werden muss (1x bis 8x täglich).
* **Globale Sensorik**: Zuweisung von Sensoren für Umgebungstemperatur (GlobalAirTempID), Luftfeuchtigkeit (GlobalHumidityID) und Helligkeit (GlobalIlluminanceID).
* **Wetterdaten**: Breitengrad (Latitude) und Längengrad (Longitude) für die Open-Meteo Vorhersage.
* **Hardware-Zuweisung**: Gardena Cloud Splitter für Verbindungs-Überwachung sowie eine Hardware Grace Period.
* **Bewässerungssperre**: Erlaubt das Setzen von Zeiten (ForbiddenStartTime, ForbiddenEndTime), zu denen nicht gewässert werden darf.
* **Beregnungskreise (Zones)**: Liste der Bewässerungszonen mit Namen und zugewiesenem Bodenfeuchtesensor.
* **Sprinkler / Ventile**: Zuweisung der physischen Ventile zu den angelegten Zonen.

### 5. Statusvariablen und Profile

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| SummaryStatus | Aktueller Status | String | Kurze Zusammenfassung des aktuellen Systemzustands. |
| VestaboardMessage | Vestaboard Nachricht | String | Kurznachricht für Vestaboards. |
| LastGeminiResponse | Letzte KI-Antwort | String | Die letzte Rückmeldung von Google Gemini. |
| IrrigationLog | Bewässerungs-Log | String | Protokoll der letzten Bewässerungsvorgänge. |
| AutomaticActive | Automatik aktiv | Boolean | Aktiviert oder deaktiviert den automatischen KI-Betrieb. |
| ForceStart | Manuell Starten | Boolean | Startet manuell eine Bewässerungsprüfung. |
| WateringActive | Bewässerung läuft | Boolean | Zeigt an, ob aktuell bewässert wird. |
| ForecastRainToday | Regen Heute | Float | Erwartete Regenmenge für heute in mm. |
| ForecastRainTomorrow | Regen Morgen | Float | Erwartete Regenmenge für morgen in mm. |
| DefaultZielFeuchte | Bewässerungs-Ziel-Feuchte | Float | Der angestrebte Feuchtigkeitswert für den Boden in %. |
| DefaultStartSchwellwert | Bewässerungs-Trigger-Feuchte | Float | Die Bodenfeuchte, bei der die Bewässerung starten soll. |
| SickerpauseMinuten | Sickerpause | Integer | Dauer der Pause, damit das Wasser versickern kann. |
| GlobalMaxDuration | Maximale Bewässerungsdauer | Integer | Die maximale Laufzeit pro Ventil. |

*(Zusätzlich werden pro definierter Zone spezifische Status-, Feuchte- und Effizienzvariablen angelegt)*

### 6. PHP-Befehlsreferenz

```php
SLAI_SetHouseMode(int $InstanceID, int $mode);
```
Setzt den Hausmodus. Ist der Modus 3 (Party), wird die automatische Bewässerung pausiert, um nasse Gäste zu vermeiden.

```php
SLAI_RunTestCommand(int $InstanceID, int $valveID, string $command);
```
Sendet einen Testbefehl ('START' oder 'STOP') an ein bestimmtes Ventil zur Überprüfung der Hardware-Verbindung.
