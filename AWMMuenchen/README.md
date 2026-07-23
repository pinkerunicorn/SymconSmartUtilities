# AWMMuenchen

Dieses Modul liest den Abfuhrkalender der AWM München aus und stellt die Termine für Restmüll, Papier und Bio übersichtlich für heute und die kommende Woche als Variablen bereit.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Automatisches Herunterladen und Parsen der ICS-Datei der Abfallwirtschaftsbetrieb München (AWM).
* Bereitstellung von Statusvariablen für heutige Abholungen (Restmüll, Papier, Bio).
* Bereitstellung einer Wochenübersicht für die Wochentage Montag bis Samstag.
* Zusammenfassende Text-Variablen für "Heute" und für eine Vestaboard-Nachricht.
* Intervallbasiertes, automatisches Update der Kalenderdaten.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0

### 3. Installation

* Über den Module Store das Modul `AWMMuenchen` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartUtilities`

### 4. Konfiguration

* **CalendarUrl**: Hier wird die Download-URL von deinem AWM München Abfuhrkalender (ICS) eingetragen.
* **UpdateInterval**: Gibt das Intervall in Stunden an, in dem die Kalenderdaten automatisch neu abgerufen werden sollen (1-24 Stunden).

### 5. Statusvariablen und Profile

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| RestmuellHeute | Restmülltonne (Heute) | Boolean | Zeigt an, ob heute Restmüll abgeholt wird. |
| PapierHeute | Papiertonne (Heute) | Boolean | Zeigt an, ob heute Papier abgeholt wird. |
| BioHeute | Biotonne (Heute) | Boolean | Zeigt an, ob heute Bioabfall abgeholt wird. |
| Heute | Heute | String | Zusammenfassende Emoji-Liste der heutigen Leerungen. |
| VestaboardMessage | Vestaboard Nachricht | String | Zusammenfassende Text-Liste der heutigen Leerungen. |
| Montag | Montag | String | Leerungen am Montag dieser Woche. |
| Dienstag | Dienstag | String | Leerungen am Dienstag dieser Woche. |
| Mittwoch | Mittwoch | String | Leerungen am Mittwoch dieser Woche. |
| Donnerstag | Donnerstag | String | Leerungen am Donnerstag dieser Woche. |
| Freitag | Freitag | String | Leerungen am Freitag dieser Woche. |
| Samstag | Samstag | String | Leerungen am Samstag dieser Woche. |

### 6. PHP-Befehlsreferenz

```php
AWM_UpdateCalendar(int $InstanceID);
```
Ruft die Kalenderdaten der konfigurierten URL manuell ab und aktualisiert alle Statusvariablen.
