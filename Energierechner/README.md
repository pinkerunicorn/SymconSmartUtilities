# Energierechner

Berechnet den Energieverbrauch und die anfallenden Kosten auf Tages-, Wochen-, Monats- und Jahresbasis anhand einer geloggten Zählervariable.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

### 1. Funktionsumfang

* Berechnet Verbrauch (in kWh) und Kosten (in €) basierend auf einer geloggten Zählervariable.
* Berücksichtigt dynamische Variablen für den Grundpreis (€/Jahr) und Arbeitspreis (Cent/kWh).
* Optionale Einberechnung des Grundpreises.
* Einstellbare Anzeige der Zeiträume: Berechnungen für Woche, Monat und Jahr können einzeln zu- oder abgeschaltet werden.
* Intervallbasierte, automatische Aktualisierung der Werte.

### 2. Voraussetzungen

* IP-Symcon ab Version 9.0
* Die ausgewählte Verbrauchsvariable (Gesamtverbrauch) muss im Archive Control (als Zähler) geloggt werden.

### 3. Installation

* Über den Module Store das Modul `Energierechner` installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/pinkerunicorn/SymconSmartUtilities`

### 4. Konfiguration

* **Gesamtverbrauch (SourceVariable)**: Die Variable, in der der fortlaufende Stromzählerstand (in kWh) geloggt wird.
* **Grundpreis (€/Jahr) (BasePriceVariable)**: Eine Variable, die den aktuellen jährlichen Grundpreis enthält.
* **Arbeitspreis (Cent/kWh) (EnergyPriceVariable)**: Eine Variable, die den aktuellen Arbeitspreis in Cent pro kWh enthält.
* **Grundpreis in die Kosten einrechnen (IncludeBasePrice)**: Wenn aktiviert, wird der anteilige Grundpreis zu den Kosten addiert.
* **Verbrauch/Kosten für Woche/Monat/Jahr berechnen**: Aktiviert oder deaktiviert die jeweiligen Auswertungszeiträume.
* **Aktualisierungs-Intervall (Minuten) (UpdateInterval)**: Bestimmt, wie oft die Berechnungen automatisch aktualisiert werden.

### 5. Statusvariablen und Profile

| Ident | Name | Typ | Beschreibung |
|:---|:---|:---|:---|
| ConsumptionDay | Verbrauch (Heute) | Float | Der berechnete Verbrauch des heutigen Tages in kWh. |
| CostDay | Kosten (Heute) | Float | Die berechneten Kosten des heutigen Tages in €. |
| ConsumptionWeek | Verbrauch (Woche) | Float | Der berechnete Verbrauch der aktuellen Woche in kWh. |
| CostWeek | Kosten (Woche) | Float | Die berechneten Kosten der aktuellen Woche in €. |
| ConsumptionMonth | Verbrauch (Monat) | Float | Der berechnete Verbrauch des aktuellen Monats in kWh. |
| CostMonth | Kosten (Monat) | Float | Die berechneten Kosten des aktuellen Monats in €. |
| ConsumptionYear | Verbrauch (Jahr) | Float | Der berechnete Verbrauch des aktuellen Jahres in kWh. |
| CostYear | Kosten (Jahr) | Float | Die berechneten Kosten des aktuellen Jahres in €. |

### 6. PHP-Befehlsreferenz

```php
EC_UpdateCalculator(int $InstanceID);
```
Führt die Berechnung von Verbrauch und Kosten sofort manuell aus und aktualisiert die Statusvariablen.
