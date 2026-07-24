<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';

class SmartBatteryMonitor extends IPSModuleStrict
{
    use SmartLog_Trait;
    public function Create(): void
    {
        parent::Create();
        
        $this->RegisterPropertyString('BatteryVariables', '[]');
        $this->RegisterPropertyString('CheckTime', '{"hour":18,"minute":0,"second":0}');
        $this->RegisterPropertyInteger('MaxUpdateAgeHours', 24);
        
        $this->RegisterTimer('DailyCheckTimer', 0, 'SBM_CheckBatteries($_IPS[\'TARGET\']);');
        
        $this->RegisterVariableBoolean('AlarmActive', 'Batterie Alarm', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Warning'
        ], 1);
        $this->RegisterVariableInteger('LowBatteryCount', 'Leere Batterien', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Battery'
        ], 2);
        $this->RegisterVariableInteger('InactiveBatteryCount', 'Inaktive Batterien', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Clock'
        ], 3);
        $this->RegisterVariableString('MonitoredBatteries', 'Überwachte Batterien (Liste)', [
            'ICON' => 'Battery'
        ], 4);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        // --- Auto-generated References ---
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }
        $list_BatteryVariables = json_decode($this->ReadPropertyString('BatteryVariables'), true);
        if (is_array($list_BatteryVariables)) {
            foreach ($list_BatteryVariables as $item) {
                $vid = $item['VariableID'] ?? 0;
                if ($vid > 1 && @IPS_ObjectExists($vid)) {
                    $this->RegisterReference($vid);
                }
            }
        }
        // ---------------------------------
        
        // Custom Presentations now handled inline in Create()
        
        $this->SetDailyTimer();
        $this->CheckBatteries();
    }
    
    private function SetDailyTimer(): void
    {
        $timeStr = $this->ReadPropertyString('CheckTime');
        $timeObj = json_decode($timeStr, true);
        if (is_array($timeObj) && isset($timeObj['hour']) && isset($timeObj['minute']) && isset($timeObj['second'])) {
            $now = time();
            $target = mktime($timeObj['hour'], $timeObj['minute'], $timeObj['second'], (int)date('m', $now), (int)date('d', $now), (int)date('Y', $now));
            
            if ($target <= $now) {
                // If the time has already passed today, set it for tomorrow
                $target += 86400; 
            }
            $diff = ($target - $now) * 1000; // in milliseconds
            $this->SetTimerInterval('DailyCheckTimer', $diff);
        }
    }

    public function CheckBatteries(): void
    {
        // When checking finishes, we recalculate the timer to the next day
        $this->SetDailyTimer();

        $batteryListJson = $this->ReadPropertyString('BatteryVariables');
        $batteryList = json_decode($batteryListJson, true);
        if (!is_array($batteryList)) {
            $batteryList = [];
        }
        
        $maxUpdateAgeHours = $this->ReadPropertyInteger('MaxUpdateAgeHours');

        $problemBatteries = [];
        $allBatteriesLog = [];
        $lowCount = 0;
        $inactiveCount = 0;
        $now = time();
        
        foreach ($batteryList as $item) {
            $varID = (int)($item['VariableID'] ?? 0);
            if ($varID === 0 || !IPS_VariableExists($varID)) {
                continue;
            }
            
            $var = IPS_GetVariable($varID);
            if (!is_array($var)) {
                continue; // Skip if variable metadata is inaccessible
            }
            
            $val = GetValue($varID);
            $type = $item['Type'] ?? 'Auto';
            $threshold = (float)($item['Threshold'] ?? 0);
            $name = !empty($item['Name']) ? $item['Name'] : IPS_GetName($varID);
            
            $isLow = false;
            
            if ($type === 'Auto') {
                $profile = $var['VariableCustomProfile'] != '' ? $var['VariableCustomProfile'] : $var['VariableProfile'];
                $obj = IPS_GetObject($varID);
                $ident = is_array($obj) ? $obj['ObjectIdent'] : '';
                
                if ($profile === '~Battery' || strpos(strtolower($ident), 'low_bat') !== false || strpos(strtolower($ident), 'lowbat') !== false) {
                    if ($val === true || $val === 1) $isLow = true;
                } elseif ($profile === '~Battery.Reversed') {
                    if ($val === false || $val === 0) $isLow = true;
                } elseif ($profile === '~Battery.100') {
                    if ($val !== false && $val <= $threshold) $isLow = true;
                }
            } elseif ($type === 'BoolTrue') {
                if ($val === true || $val === 1) $isLow = true;
            } elseif ($type === 'BoolFalse') {
                if ($val === false || $val === 0) $isLow = true;
            } elseif ($type === 'Percent' || $type === 'Voltage') {
                if ($val !== false && $val <= $threshold) $isLow = true;
            }

            // Check variable last update timestamp
            $lastUpdate = $var['VariableUpdated'] ?? 0;
            $isStale = false;
            if ($maxUpdateAgeHours > 0) {
                if ($lastUpdate === 0 || ($now - $lastUpdate) > ($maxUpdateAgeHours * 3600)) {
                    $isStale = true;
                }
            }
            
            if ($isLow) {
                $lowCount++;
            }
            if ($isStale) {
                $inactiveCount++;
            }

            if ($isLow && $isStale) {
                $statusText = 'LEER & INAKTIV';
            } elseif ($isLow) {
                $statusText = 'LEER';
            } elseif ($isStale) {
                $statusText = 'INAKTIV';
            } else {
                $statusText = 'OK';
            }

            if ($lastUpdate > 0) {
                $diff = $now - $lastUpdate;
                if ($diff < 3600) {
                    $timeAgo = round($diff / 60) . ' Min.';
                } elseif ($diff < 86400) {
                    $timeAgo = round($diff / 3600, 1) . ' Std.';
                } else {
                    $timeAgo = round($diff / 86400, 1) . ' Tage';
                }
            } else {
                $timeAgo = 'nie';
            }
            
            $realValue = GetValueFormatted($varID);
            if ($realValue === false) $realValue = 'Fehler';
            
            $allBatteriesLog[] = "[$statusText] $name ($realValue, Update: $timeAgo)";
            
            if ($isLow || $isStale) {
                // Store the custom name alongside the varID for SyncLinks
                $problemBatteries[$varID] = "$name ($statusText)";
            }
        }
        
        $this->SetValue('MonitoredBatteries', "Gesamtanzahl: " . count($allBatteriesLog) . "\n\n" . implode("\n", $allBatteriesLog));
        
        $this->SetValue('AlarmActive', ($lowCount > 0 || $inactiveCount > 0));
        $this->SetValue('LowBatteryCount', $lowCount);
        $this->SetValue('InactiveBatteryCount', $inactiveCount);
        
        $this->SyncLinks($problemBatteries);
    }
    
    private function SyncLinks(array $lowBatteries): void
    {
        // Fetch all existing links underneath the instance
        $existingLinks = IPS_GetChildrenIDs($this->InstanceID);
        $linkTargets = [];
        
        foreach ($existingLinks as $id) {
            $obj = IPS_GetObject($id);
            if ($obj['ObjectType'] === 6) { // 6 = Link
                $targetID = IPS_GetLink($id)['TargetID'];
                
                // If the link points to a battery that is NO LONGER low, delete it
                if (!array_key_exists($targetID, $lowBatteries)) {
                    IPS_DeleteLink($id);
                } else {
                    $linkTargets[] = $targetID; // Keep track of existing
                    IPS_SetName($id, $lowBatteries[$targetID]); // Update name just in case it was changed in the config
                }
            }
        }
        
        // Add new links for low batteries that don't have a link yet
        foreach ($lowBatteries as $varID => $name) {
            if (!in_array($varID, $linkTargets)) {
                $linkID = IPS_CreateLink();
                IPS_SetParent($linkID, $this->InstanceID);
                IPS_SetName($linkID, $name);
                IPS_SetLinkTargetID($linkID, $varID);
                IPS_SetPosition($linkID, 10);
            }
        }
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        $this->SLog('INFO', $Message);
        IPS_LogMessage('SmartVillaKunterbunt', 'SmartBatteryMonitor: ' . $Message);
        return true;
    }

    public function AutoDiscoverBatteries(): array
    {
        $currentJson = $this->ReadPropertyString('BatteryVariables');
        $currentList = json_decode($currentJson, true);
        if (!is_array($currentList)) {
            $currentList = [];
        }

        $existingIDs = array_column($currentList, 'VariableID');
        $addedCount = 0;

        $allVarIDs = IPS_GetVariableList();
        foreach ($allVarIDs as $varID) {
            // Ignore variables belonging to this instance itself
            $checkID = $varID;
            $isChild = false;
            while ($checkID > 0 && IPS_ObjectExists($checkID)) {
                $parent = IPS_GetParent($checkID);
                if ($parent === $this->InstanceID) {
                    $isChild = true;
                    break;
                }
                $checkID = $parent;
            }
            if ($isChild || $varID === $this->InstanceID) {
                continue;
            }

            // Ignore already added variables
            if (in_array($varID, $existingIDs, true)) {
                continue;
            }

            $var = @IPS_GetVariable($varID);
            if (!is_array($var)) {
                continue;
            }

            $obj = @IPS_GetObject($varID);
            if (!is_array($obj)) {
                continue;
            }

            $profile = $var['VariableCustomProfile'] !== '' ? $var['VariableCustomProfile'] : $var['VariableProfile'];
            $ident = strtolower($obj['ObjectIdent']);
            $name = strtolower($obj['ObjectName']);
            $profileLower = strtolower($profile);

            $isBattery = false;
            $type = 'Auto';
            $threshold = 15.0;

            // Check profile
            if (strpos($profileLower, 'battery') !== false || strpos($profileLower, 'batterie') !== false) {
                $isBattery = true;
                if ($profile === '~Battery.100') {
                    $type = 'Percent';
                    $threshold = 15.0;
                } elseif ($profile === '~Battery.Reversed') {
                    $type = 'BoolFalse';
                } elseif ($profile === '~Battery') {
                    $type = 'BoolTrue';
                }
            }
            // Check Ident & Name
            elseif (
                strpos($ident, 'low_bat') !== false ||
                strpos($ident, 'lowbat') !== false ||
                strpos($ident, 'battery') !== false ||
                strpos($ident, 'batterie') !== false ||
                strpos($name, 'batterie') !== false ||
                strpos($name, 'battery') !== false ||
                strpos($name, 'low bat') !== false
            ) {
                $isBattery = true;
                if ($var['VariableType'] === 0) { // Boolean
                    if (strpos($ident, 'reversed') !== false || strpos($name, 'reversed') !== false) {
                        $type = 'BoolFalse';
                    } else {
                        $type = 'BoolTrue';
                    }
                } elseif ($var['VariableType'] === 1 || $var['VariableType'] === 2) { // Integer or Float
                    $type = 'Percent';
                    $threshold = 15.0;
                }
            }

            if ($isBattery) {
                $parentID = $obj['ParentID'];
                $parentName = ($parentID > 0 && IPS_ObjectExists($parentID)) ? IPS_GetName($parentID) : '';
                $displayName = (!empty($parentName) && $parentName !== '0')
                    ? $parentName . ' (' . $obj['ObjectName'] . ')'
                    : $obj['ObjectName'];

                $currentList[] = [
                    'Name'        => $displayName,
                    'VariableID'  => $varID,
                    'Type'        => $type,
                    'Threshold'   => $threshold
                ];
                $existingIDs[] = $varID;
                $addedCount++;
            }
        }

        if ($addedCount > 0) {
            $newListJson = json_encode($currentList);
            IPS_SetProperty($this->InstanceID, 'BatteryVariables', $newListJson);
            $this->UpdateFormField('BatteryVariables', 'values', json_encode($currentList));
            @IPS_ApplyChanges($this->InstanceID);
            $this->LogMessage("AutoDiscover: $addedCount neue Batterie-Variablen hinzugefügt.", 0);
        }

        return $currentList;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "Label",
            "label": "Batterie-Überwachung (SmartBatteryMonitor)"
        },
        {
            "type": "List",
            "name": "BatteryVariables",
            "caption": "Überwachte Batterien",
            "add": true,
            "delete": true,
            "sort": {
                "column": "Name",
                "direction": "ascending"
            },
            "columns": [
                {
                    "caption": "Name",
                    "name": "Name",
                    "width": "250px",
                    "add": "Neue Batterie",
                    "edit": {
                        "type": "ValidationTextBox"
                    }
                },
                {
                    "caption": "Variable",
                    "name": "VariableID",
                    "width": "auto",
                    "add": 0,
                    "edit": {
                        "type": "SelectVariable"
                    }
                },
                {
                    "caption": "Typ",
                    "name": "Type",
                    "width": "200px",
                    "add": "Auto",
                    "edit": {
                        "type": "Select",
                        "options": [
                            {
                                "label": "Automatisch (Profil/Ident)",
                                "value": "Auto"
                            },
                            {
                                "label": "Boolean (True = Leer)",
                                "value": "BoolTrue"
                            },
                            {
                                "label": "Boolean (False = Leer)",
                                "value": "BoolFalse"
                            },
                            {
                                "label": "Prozent",
                                "value": "Percent"
                            },
                            {
                                "label": "Spannung",
                                "value": "Voltage"
                            }
                        ]
                    }
                },
                {
                    "caption": "Schwellwert",
                    "name": "Threshold",
                    "width": "150px",
                    "add": 15,
                    "edit": {
                        "type": "NumberSpinner",
                        "digits": 2
                    }
                }
            ]
        },
        {
            "type": "Label",
            "caption": "Hier stellst du 'Tägliche Ausführungszeit' und Überwachung der Aktualisierung ein."
        },
        {
            "type": "SelectTime",
            "name": "CheckTime",
            "caption": "Tägliche Ausführungszeit"
        },
        {
            "type": "NumberSpinner",
            "name": "MaxUpdateAgeHours",
            "caption": "Maximales Alter der Aktualisierung (in Stunden, 0 = Deaktivieren)",
            "digits": 0,
            "minimum": 0
        }
    ],
    "actions": [
        {
            "type": "Button",
            "caption": "Batterien automatisch suchen",
            "onClick": "SBM_AutoDiscoverBatteries($id);"
        },
        {
            "type": "Button",
            "caption": "Jetzt prüfen",
            "onClick": "SBM_CheckBatteries($id);"
        }
    ]
}
EOT;
    }
}


