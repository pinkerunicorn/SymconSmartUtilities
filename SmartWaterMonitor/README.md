# SmartWaterMonitor

Überwacht den Wasserverbrauch via MQTT, erfasst Durchfluss sowie Gesamtverbrauch und schlägt bei ungewöhnlich langem Dauerfluss (Leckage) automatisch Alarm.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Empfängt und verarbeitet MQTT-Daten (Status, Durchfluss, inkrementeller Gesamtverbrauch) von einem kompatiblen Wasserzähler-Sensor (z.B. via ESPHome).
* Speichert den Gesamtverbrauch fortlaufend in Litern und Kubikmetern, resistent gegen Neustarts des Sensors.
* Integrierte Leckage-Erkennung: Löst einen Alarm aus, wenn das Wasser ohne Unterbrechung länger als konfiguriert fließt.
* Intelligente Alarm-Unterdrückung: Durch die Angabe einer Bewässerungs-Variable (z.B. SmartLawnAI) wird der Leckage-Alarm automatisch ausgesetzt, solange der Garten bewässert wird.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0
* Eingerichtetes MQTT-Server Modul in IP-Symcon, welches die Daten des Sensors empfängt.

### 3. Installation

* Über den Module Store das Modul `SmartWaterMonitor` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartUtilities`

### 4. Konfiguration

* **MQTT Base Topic (MQTTBaseTopic)**: Das Basis-Topic, unter dem der Sensor seine Daten sendet (z.B. `watermeter`).
* **Maximaler Dauerfluss (MaxContinuousFlowMinutes)**: Zeitspanne in Minuten, nach der bei ununterbrochenem Wasserfluss ein Leckage-Alarm ausgelöst werden soll (Standard: 45 Minuten).
* **Bewässerungs-Variable (IrrigationVariableID)**: (Optional) Eine Boolean-Variable, die `true` ist, während die Gartenbewässerung läuft. Ist diese Variable aktiv, wird kein Leckage-Alarm generiert.

### 5. Statusvariablen und Profile

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| Online | Online | Boolean | Zeigt den aktuellen Verbindungsstatus (LWT) des Sensors an. |
| LeakAlarm | Leckage-Alarm | Boolean | Meldet, ob der erlaubte Dauerfluss überschritten wurde. |
| WaterRunning | Wasser fließt | Boolean | Zeigt an, ob aktuell Wasser durch den Zähler fließt. |
| FlowRate | Aktueller Durchfluss | Float | Die momentane Fließgeschwindigkeit in l/min. |
| TotalConsumption | Gesamtverbrauch | Float | Der fortlaufende Gesamtverbrauch in Kubikmetern (m³). |
| TotalConsumptionLiter | Gesamtverbrauch (Liter) | Float | Der fortlaufende Gesamtverbrauch in Litern (l). |

### 6. PHP-Befehlsreferenz

(Keine direkten PHP-Befehle verfügbar. Der Gesamtverbrauch kann jedoch manuell im WebFront/App über die entsprechenden Variablen korrigiert werden.)
