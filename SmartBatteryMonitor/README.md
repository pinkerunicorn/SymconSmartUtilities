# SmartBatteryMonitor

Überwacht zentral den Batteriestatus verschiedener Geräte im Smarthome und schlägt bei niedrigem Batteriestand Alarm.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Zentrale Überwachung einer beliebigen Anzahl an Batterie-Variablen.
* Unterstützt automatische Typerkennung anhand von Variablenprofilen (`~Battery`) oder Identifikatoren.
* Manuelle Konfiguration des Auswertetyps möglich (Boolean, Prozent, Spannung) inklusive individueller Schwellwerte.
* Tägliche, automatische Prüfung zu einer festgelegten Uhrzeit.
* Automatische Generierung von Links unterhalb der Instanz für alle Batterien, die aktuell den Schwellwert unterschreiten (für eine schnelle Übersicht im WebFront/App).
* Stellt Statusvariablen für den übergreifenden Alarmzustand, die Anzahl leerer Batterien und eine detaillierte Textliste zur Verfügung.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0

### 3. Installation

* Über den Module Store das Modul `SmartBatteryMonitor` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartUtilities`

### 4. Konfiguration

* **Überwachte Batterien (BatteryVariables)**: Eine Liste aller einzubeziehenden Batterie-Variablen. 
  * *Name*: Eigener Anzeigename für die Batterie.
  * *Variable*: Die ID der Batterie-Variable.
  * *Typ*: Bestimmt die Auswertelogik (Automatisch, BoolTrue, BoolFalse, Prozent, Spannung).
  * *Schwellwert*: Legt fest, ab welchem Wert (bei Prozent/Spannung) ein Alarm ausgelöst wird.
* **Maximales Alter der Aktualisierung (MaxUpdateAgeHours)**: Maximale erlaubte Inaktivitätszeit in Stunden (Standard: 24h, 0 = Deaktiviert). Wurde eine Batterie-Variable in diesem Zeitraum nicht von Ihrem Sensor aktualisiert, schlägt das Modul Alarm (Status: INAKTIV).
* **Tägliche Ausführungszeit (CheckTime)**: Uhrzeit, zu der die tägliche Batterieprüfung stattfinden soll.

### 5. Statusvariablen und Profile

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| AlarmActive | Batterie Alarm | Boolean | Gibt an, ob mindestens eine Batterie leer oder inaktiv ist. |
| LowBatteryCount | Leere / Inaktive Batterien | Integer | Die Anzahl der aktuell als leer oder inaktiv erkannten Batterien. |
| MonitoredBatteries | Überwachte Batterien (Liste) | String | Detaillierte Zusamenfassung aller überwachten Batterien mit Status (OK/LEER/INAKTIV/LEER & INAKTIV), aktuellem Wert und Zeitpunkt der letzten Aktualisierung. |

### 6. PHP-Befehlsreferenz

```php
SBM_AutoDiscoverBatteries(int $InstanceID);
```
Durchsucht das gesamte IP-Symcon-System nach Batterie-Variablen (anhand von Profilen wie `~Battery`, `~Battery.100`, `~Battery.Reversed` oder Namens-/Ident-Mustern) und fügt neu gefundene Variablen automatisch der Liste der überwachten Batterien hinzu.

```php
SBM_CheckBatteries(int $InstanceID);
```
Startet manuell die Prüfung aller konfigurierten Batterie-Variablen, aktualisiert die Statusvariablen und legt bei Bedarf Links zu leeren Batterien an.
