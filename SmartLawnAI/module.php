<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/Trait_Weather.php';
require_once __DIR__ . '/libs/Trait_AI.php';
require_once __DIR__ . '/libs/Trait_Logic.php';
require_once __DIR__ . '/libs/Trait_Helpers.php';
require_once __DIR__ . '/../libs/Trait_CentralStateAware.php';
require_once __DIR__ . '/../libs/Trait_SmartLog.php';

class SmartLawnAI extends IPSModuleStrict {
    use SmartLog_Trait;
    use SmartLawnAI_Weather;
    use SmartLawnAI_AI;
    use SmartLawnAI_Logic;
    use SmartLawnAI_Helpers;
    use CentralStateAware_Trait;

    public function Create(): void {
        parent::Create();

        // Globale Defaults (jetzt als Variablen statt Properties)
        $this->RegisterVariableFloat('DefaultZielFeuchte', '🎯 Bewässerungs-Ziel-Feuchte', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON' => 'Drops',
            'SUFFIX' => '%',
            'MIN' => 0,
            'MAX' => 100,
            'STEP' => 5
        ], 10);
        $this->RegisterVariableFloat('DefaultStartSchwellwert', 'Bewässerungs-Trigger-Feuchte', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON' => 'Drops',
            'SUFFIX' => '%',
            'MIN' => 0,
            'MAX' => 100,
            'STEP' => 5
        ], 11);
        $this->RegisterVariableInteger('SickerpauseMinuten', '⏳ Sickerpause', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON' => 'Clock',
            'SUFFIX' => 'Min',
            'MIN' => 0,
            'MAX' => 180,
            'STEP' => 5
        ], 12);
        $this->RegisterVariableInteger('GlobalMaxDuration', '⏱ Maximale Bewässerungsdauer', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON' => 'Clock',
            'SUFFIX' => 'Min',
            'MIN' => 0,
            'MAX' => 180,
            'STEP' => 5
        ], 13);

        // Summenstatus Variable (fürs Webfront)
        $this->RegisterVariableString('SummaryStatus', '🤖 Aktueller Status', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Information'], 0);
        $this->RegisterVariableString('VestaboardMessage', 'Vestaboard Nachricht', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Information'], 1);
        $this->RegisterVariableString('LastGeminiResponse', '🧠 Letzte KI-Antwort', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Information'], 2);
        $this->RegisterVariableString('IrrigationLog', '📝 Bewässerungs-Log', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Drop'], 3);

        // Status/Trigger Variablen
        $this->RegisterVariableBoolean('AutomaticActive', '⚙ Automatik aktiv', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'Gear'
        ], 0);
        $this->RegisterVariableBoolean('ForceStart', '▶ Manuell Starten', [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH,
            'ICON' => 'Play'
        ], 0);


        // Gemini AI Konfiguration: API-Key und Modell werden jetzt zentral
        // über die SmartGeminiIO Instanz konfiguriert (kein API-Key hier nötig).

        // Globale Sensoren (Thermodynamik & Boden)
        $this->RegisterPropertyInteger('GlobalAirTempID', 0);
        $this->RegisterPropertyInteger('GlobalHumidityID', 0);
        $this->RegisterPropertyInteger('GlobalIlluminanceID', 0);
        $this->RegisterPropertyFloat('Latitude', 0.0);
        $this->RegisterPropertyFloat('Longitude', 0.0);
        
        // NEU: Hardware Grace Period konfigurierbar
        $this->RegisterPropertyInteger('HardwareGracePeriod', 90);
        $this->RegisterPropertyInteger('GardenaSplitterID', 0);
        
        // NEU: Globale Bewässerungssperre
        $this->RegisterPropertyString('ForbiddenStartTime', '10:00');
        $this->RegisterPropertyString('ForbiddenEndTime', '17:00');
        
        // Water Monitor
        $this->RegisterPropertyInteger('WaterMonitorInstanceID', 0);
        
        $this->RegisterVariableBoolean('WateringActive', 'Bewässerung läuft', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Drop'], 4);

        // Wasserverbrauch-Variablen
        $this->RegisterVariableFloat('WaterLastSession', 'Letzte Beregnung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' L',
            'ICON' => 'Drops'
        ], 20);
        $this->RegisterVariableFloat('WaterToday',       'Heute',            [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' L',
            'ICON' => 'Drops'
        ], 21);
        $this->RegisterVariableFloat('WaterThisWeek',    'Diese Woche',      [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' L',
            'ICON' => 'Drops'
        ], 22);
        $this->RegisterVariableFloat('WaterThisMonth',   'Dieser Monat',     [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX' => ' L',
            'ICON' => 'Drops'
        ], 23);
        
        $this->SetVisualizationType(1);

        // Wetter/Regen
        $this->RegisterVariableFloat('ForecastRainToday', '🌧 Regen Heute', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Cloud'], 5);
        $this->RegisterVariableFloat('ForecastRainTomorrow', '🌧 Regen Morgen', ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Cloud'], 6);

        // Zonen (Hardware)
        $this->RegisterPropertyString('Zones', '[]');
        $this->RegisterPropertyString('Sprinklers', '[]');
        
        // Zeitplan (1=06:00, 2=06+18, 4=06+10+14+18)
        $this->RegisterPropertyInteger('IrrigationSchedule', 2);

        // Timer für die 60-Sekunden-Taktung (Zustandsmaschine)
        $this->RegisterTimer('LawnAITimer', 0, 'SLAI_ProcessLogic($_IPS[\'TARGET\']);');
        
        // NEU: Gemini Retry Timer
        $this->RegisterTimer('GeminiRetryTimer', 0, 'SLAI_ProcessGeminiRetry($_IPS[\'TARGET\']);');
        
    }

    public function RequestAction(string $Ident, mixed $Value): void {
        if (in_array($Ident, ['DefaultZielFeuchte', 'DefaultStartSchwellwert', 'SickerpauseMinuten', 'GlobalMaxDuration'])) {
            $this->SetValue($Ident, $Value);
        } else if ($Ident === 'AutomaticActive') {
            $this->SetValue($Ident, $Value);
            $this->MaintainScheduleEvents($Value);
            
            if (!$Value) {
                $this->SetTimerInterval('LawnAITimer', 0);
                $this->resetAllZones(false);
            } else {
                $this->SetTimerInterval('LawnAITimer', 1000);
                $this->SetBuffer('LastPlanCalculation', '0');
                $this->AddLogEvent("System: Bereit", "Automatik aktiviert. Zeitpläne aktiv.", '#4CAF50');
                $this->ProcessLogic();
            }
        } else if ($Ident === 'ForceStart') {
            if ($Value) {
                $this->SetValue($Ident, true);
                $this->triggerManualStart();
                IPS_Sleep(500);
                $this->SetValue($Ident, false);
            }
        }
    }

    public function ApplyChanges(): void {
        parent::ApplyChanges();
        // --- Auto-generated References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $ref_GlobalAirTempID = $this->ReadPropertyInteger('GlobalAirTempID');
        if ($ref_GlobalAirTempID > 1 && @IPS_ObjectExists($ref_GlobalAirTempID)) {
            $this->RegisterReference($ref_GlobalAirTempID);
        }
        $ref_GlobalHumidityID = $this->ReadPropertyInteger('GlobalHumidityID');
        if ($ref_GlobalHumidityID > 1 && @IPS_ObjectExists($ref_GlobalHumidityID)) {
            $this->RegisterReference($ref_GlobalHumidityID);
        }
        $ref_GlobalIlluminanceID = $this->ReadPropertyInteger('GlobalIlluminanceID');
        if ($ref_GlobalIlluminanceID > 1 && @IPS_ObjectExists($ref_GlobalIlluminanceID)) {
            $this->RegisterReference($ref_GlobalIlluminanceID);
        }
        $ref_GardenaSplitterID = $this->ReadPropertyInteger('GardenaSplitterID');
        if ($ref_GardenaSplitterID > 1 && @IPS_ObjectExists($ref_GardenaSplitterID)) {
            $this->RegisterReference($ref_GardenaSplitterID);
        }
        $ref_WaterMonitorID = $this->ReadPropertyInteger('WaterMonitorInstanceID');
        if ($ref_WaterMonitorID > 1 && @IPS_ObjectExists($ref_WaterMonitorID)) {
            $this->RegisterReference($ref_WaterMonitorID);
        }
        $list_Zones = json_decode($this->ReadPropertyString('Zones'), true);
        if (is_array($list_Zones)) {
            foreach ($list_Zones as $item) {
                $vid = $item['SensorID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        // ---------------------------------
        $this->SubscribeToCentralStates(['PresenceMode', 'ActivityMode']);

        // Timer aktivieren (alle 1.000 ms = 1 Sekunde)
        // Status/Trigger Variablen
        $this->EnableAction('AutomaticActive');
         
        if (!IPS_VariableExists($this->GetIDForIdent('AutomaticActive')) || (GetValue($this->GetIDForIdent('AutomaticActive')) === false && IPS_GetVariable($this->GetIDForIdent('AutomaticActive'))['VariableUpdated'] == 0)) {
            $this->SetValue('AutomaticActive', true); // Default true
            $this->SetTimerInterval('LawnAITimer', 1000);
            $this->MaintainScheduleEvents(true);
        } else {
            $active = GetValue($this->GetIDForIdent('AutomaticActive'));
            $this->MaintainScheduleEvents($active);
            if ($active) {
                $this->SetTimerInterval('LawnAITimer', 1000);
            } else {
                $this->SetTimerInterval('LawnAITimer', 0);
            }
        }
        $this->EnableAction('ForceStart');
         
        $this->SetValue('ForceStart', false);

        $this->EnableAction('DefaultZielFeuchte');
        IPS_SetName($this->GetIDForIdent('DefaultZielFeuchte'), 'Bewässerungs-Ziel-Feuchte');
        if (GetValue($this->GetIDForIdent('DefaultZielFeuchte')) == 0) { $this->SetValue('DefaultZielFeuchte', 55.0); }
         
        
        $this->EnableAction('DefaultStartSchwellwert');
        IPS_SetName($this->GetIDForIdent('DefaultStartSchwellwert'), 'Bewässerungs-Trigger-Feuchte');
        if (GetValue($this->GetIDForIdent('DefaultStartSchwellwert')) == 0) { $this->SetValue('DefaultStartSchwellwert', 20.0); }
         
        
        $this->EnableAction('SickerpauseMinuten');
        IPS_SetName($this->GetIDForIdent('SickerpauseMinuten'), 'Sickerpause');
        if (GetValue($this->GetIDForIdent('SickerpauseMinuten')) == 0) { $this->SetValue('SickerpauseMinuten', 15); }
         
        
        $this->EnableAction('GlobalMaxDuration');
        IPS_SetName($this->GetIDForIdent('GlobalMaxDuration'), 'Maximale Bewässerungsdauer');
        if (GetValue($this->GetIDForIdent('GlobalMaxDuration')) == 0) { $this->SetValue('GlobalMaxDuration', 30); }
         

        $splitterID = $this->ReadPropertyInteger('GardenaSplitterID');
        if ($splitterID > 0 && IPS_InstanceExists($splitterID)) {
            $this->RegisterMessage($splitterID, IM_CHANGESTATUS);
        }

        $wateringOptions = json_encode([
            ['Value' => false, 'Caption' => 'Inaktiv', 'IconValue' => 'Drops', 'IconActive' => false,
             'ColorActive' => false, 'ColorDisplay' => -1, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => -1],
            ['Value' => true, 'Caption' => 'Bewaessert', 'IconValue' => 'Drops', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0x0088FF, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x0088FF]
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('WateringActive'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON' => 'Drops',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => $wateringOptions
        ]);
         
        // Removed presentation for IrrigationLog per user request

        if (GetValue($this->GetIDForIdent('IrrigationLog')) === '') {
            $this->SetValue('IrrigationLog', "Noch keine Bewässerungsvorgänge protokolliert.");
        }

        // Wasserverbrauch Archiv
        $waterVars = [
            'WaterLastSession',
            'WaterToday',
            'WaterThisWeek',
            'WaterThisMonth',
        ];
        foreach ($waterVars as $ident) {
            $this->EnableArchive($this->GetIDForIdent($ident));
        }

        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        if (is_array($zones)) {
            foreach ($zones as $zone) {
                $sid = $zone['SensorID'];
                
                // Aus Objektbaum entfernen
                $this->UnregisterVariable('Status_'. $sid);
                $this->UnregisterVariable('Effizienz_'. $sid);
                $this->UnregisterVariable('SickerpauseStart_'. $sid);
                $this->UnregisterVariable('WateringStart_'. $sid);
                $this->UnregisterVariable('StartFeuchte_'. $sid);
                $this->UnregisterVariable('Dauer_'. $sid);
                $this->UnregisterVariable('CurrentSprinklerIndex_'. $sid);
            }
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void {
        if ($this->HandleCentralStateMessage($SenderID, $Message, $Data)) return;

        if ($Message == IM_CHANGESTATUS) {
            $splitterID = $this->ReadPropertyInteger('GardenaSplitterID');
            if ($SenderID == $splitterID) {
                $status = $Data[0]; // Neuer Instanz-Status
                if ($status >= 200) {
                    $this->LogAndDebug('Gardena', "Gardena Splitter Verbindungsfehler! (Status: $status)", 0);
                    $this->SetSummaryStatus('Gardena Cloud Verbindung getrennt');
                } else if ($status == 102) {
                    $this->LogAndDebug('Gardena', 'Gardena Splitter Verbindung wiederhergestellt.', 0);
                    $this->SetSummaryStatus('Bereit');
                }
            }
        }
    }

    public function RunTestCommand(int $valveID, string $command): void {
        $res = $this->ResolveSprinklerObject($valveID);
        if ($command === 'START') {
            if ($res['DurationID'] > 0) {
                $this->SafeRequestAction($res['DurationID'], 5); // 5 Minuten
            }
            if ($res['ValveID'] > 0) {
                $this->SafeRequestAction($res['ValveID'], 'START_SECONDS_TO_OVERRIDE');
            }
            echo "START Befehl (5 Min) gesendet an ". $valveID . "(DurationID: ". $res['DurationID'] . ", ActionID: ". $res['ValveID'] . ")\n";
        } elseif ($command === 'STOP') {
            if ($res['ValveID'] > 0) {
                if (IPS_VariableExists($res['ValveID']) && in_array(strtolower(IPS_GetObject($res['ValveID'])['ObjectIdent']), ['action', 'valvecontrol', 'control'])) {
                    $this->SafeRequestAction($res['ValveID'], 'STOP_UNTIL_NEXT_TASK');
                } else {
                    $this->SafeRequestAction($res['ValveID'], false);
                }
            }
            echo "STOP Befehl gesendet an ". $valveID . "\n";
        }
    }
    
    private function OnCentralStateChanged(string $stateName, mixed $newValue): void {
        if ($this->IsParty()) {
            // Party Mode -> Turn off automatic watering to prevent wet guests
            if ($this->GetValue('AutomaticActive')) {
                $this->RequestAction('AutomaticActive', false);
                $this->LogAndDebug('SmartLawnAI', 'Party-Modus aktiv: Bewässerungsautomatik pausiert.', 0);
            }
        } else {
            // We do not automatically turn it back on, because we don't know if the user manually turned it off before.
            // But we could log that it's no longer blocked by Party Mode.
            $this->LogAndDebug('SmartLawnAI', "Zentraler Status gewechselt: $stateName=$newValue. (Bewässerung bleibt aus, falls sie zuvor im Party-Modus deaktiviert wurde).", 0);
        }
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        $level = match(true) {
            $Type >= IS_EBASE => 'ERROR',
            $Type >= IS_WBASE => 'WARNING',
            default           => 'INFO',
        };
        $this->SLog($level, $Message);
        return true;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "Label",
            "caption": "Willkommen bei SmartLawnAI! Lass uns deine smarte Bewässerung einrichten."
        },
        {
            "type": "ExpansionPanel",
            "caption": "⚙ Gemini AI Konfiguration",
            "items": [
                {
                    "type": "Label",
                    "caption": "API-Key und Modell werden zentral über die 'Smart Gemini IO' Instanz konfiguriert.\nBitte dort einmalig deinen Google Gemini API-Key und das gewünschte Modell hinterlegen.\nAlle KI-Module finden die Instanz dann automatisch."
                }
            ]
        },
                {
                    "type": "Label",
                    "caption": "Bewässerungs-Zeitplan"
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "Select",
                            "name": "IrrigationSchedule",
                            "caption": "Prüfungs-Intervalle (KI fragt nur zu diesen Zeiten)",
                            "options": [
                                {
                                    "caption": "1x täglich (06:00)",
                                    "value": 1
                                },
                                {
                                    "caption": "2x täglich (06:00, 18:00)",
                                    "value": 2
                                },
                                {
                                    "caption": "4x täglich (alle 6 Stunden)",
                                    "value": 4
                                },
                                {
                                    "caption": "6x täglich (alle 4 Stunden)",
                                    "value": 6
                                },
                                {
                                    "caption": "8x täglich (alle 3 Stunden)",
                                    "value": 8
                                }
                            ]
                        }
                    ]
                },
                {
                    "type": "Label",
                    "caption": "Globale Sensorik (Thermodynamik & Wetter)"
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "SelectVariable",
                            "name": "GlobalAirTempID",
                            "caption": "Umgebungstemperatur-Sensor (ID Lufttemperatur in °C)"
                        },
                        {
                            "type": "SelectVariable",
                            "name": "GlobalHumidityID",
                            "caption": "Luftfeuchtigkeits-Sensor (ID relative Feuchte in %)"
                        }
                    ]
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "SelectVariable",
                            "name": "GlobalIlluminanceID",
                            "caption": "Helligkeitssensor (ID in Lux)"
                        }
                    ]
                },
                {
                    "type": "Label",
                    "caption": "Automatische Wetterdaten über Open-Meteo (Kostenlos, ohne API-Key)"
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "NumberSpinner",
                            "name": "Latitude",
                            "caption": "Breitengrad (Latitude)",
                            "digits": 6,
                            "minimum": -90,
                            "maximum": 90
                        },
                        {
                            "type": "NumberSpinner",
                            "name": "Longitude",
                            "caption": "Längengrad (Longitude)",
                            "digits": 6,
                            "minimum": -180,
                            "maximum": 180
                        }
                    ]
                },
                {
                    "type": "Label",
                    "caption": "Zonen & Hardware-Zuweisung (0 = nutzt globales Default)"
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "SelectInstance",
                            "name": "GardenaSplitterID",
                            "caption": "Gardena Cloud Splitter / IO (für Verbindungs-Überwachung)"
                        },
                        {
                            "type": "NumberSpinner",
                            "name": "HardwareGracePeriod",
                            "caption": "Cloud / Hardware Verzögerung (Grace Period in Sekunden)",
                            "minimum": 0,
                            "maximum": 300
                        }
                    ]
                },
                {
                    "type": "Label",
                    "caption": "Bewässerungssperre (Verbotene Zeiten)"
                },
                {
                    "type": "RowLayout",
                    "items": [
                        {
                            "type": "SelectTime",
                            "name": "ForbiddenStartTime",
                            "caption": "Sperrzeit Start"
                        },
                        {
                            "type": "SelectTime",
                            "name": "ForbiddenEndTime",
                            "caption": "Sperrzeit Ende"
                        }
                    ]
                },
                {
                    "type": "Label",
                    "caption": "Water Monitor Integration"
                },
                {
                    "type": "SelectInstance",
                    "name": "WaterMonitorInstanceID",
                    "caption": "SmartWaterMonitor Instanz (für Wasserverbrauchsmessung)",
                    "moduleID": "{09A99311-87CD-480B-A7B8-6DC226136CFB}"
                },
        {
            "type": "Label",
            "caption": "Hier definierst du deine Beregnungskreise. Gib jedem Kreis einen Namen und verknüpfe ihn mit einem passenden Bodenfeuchtesensor."
        },

        {
            "type": "List",
            "name": "Zones",
            "caption": "Beregnungskreise",
            "rowCount": 5,
            "add": true,
            "delete": true,
            "changeOrder": true,
            "columns": [
                {
                    "caption": "Gruppen-Name",
                    "name": "GroupName",
                    "width": "auto",
                    "add": "",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Feuchte-Sensor",
                    "name": "SensorID",
                    "width": "250px",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                }
            ]
        },
        {
            "type": "Label",
            "caption": "Weise hier deine physischen Sprinkler oder Ventile den angelegten Kreisen zu."
        },
        {
            "type": "List",
            "name": "Sprinklers",
            "caption": "Sprinkler / Ventile",
            "rowCount": 10,
            "add": true,
            "delete": true,
            "changeOrder": true,
            "columns": [
                {
                    "caption": "Zu Kreis (Name)",
                    "name": "ZoneName",
                    "width": "150px",
                    "add": "",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Sprinkler Name",
                    "name": "SprinklerName",
                    "width": "auto",
                    "add": "",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Ventil (Instanz/Variable)",
                    "name": "ValveID",
                    "width": "250px",
                    "add": 0,
                    "edit": {
                        "type": "SelectObject"
                    }
                }
            ]
        }
    ],
    "actions": [
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "Button",
                    "caption": "Test: Start Ventil 25027 (5 Min)",
                    "onClick": "echo 'Sende Start-Befehl...'; SLAI_RunTestCommand($id, 25027, 'START');",
                    "icon": "Play"
                },
                {
                    "type": "Button",
                    "caption": "Test: Stop Ventil 25027",
                    "onClick": "echo 'Sende Stop-Befehl...'; SLAI_RunTestCommand($id, 25027, 'STOP');",
                    "icon": "Stop"
                }
            ]
        }
    ]
}
EOT;
    }
}


