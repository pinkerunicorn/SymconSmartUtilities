<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';

class SmartWaterMonitor extends IPSModuleStrict
{
    use SmartLog_Trait;
    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        // Properties
        $this->RegisterPropertyString('MQTTBaseTopic', 'watermeter');
        $this->RegisterPropertyInteger('MaxContinuousFlowMinutes', 45); // 45 minutes default
        $this->RegisterPropertyInteger('IrrigationVariableID', 0);
        
        $this->SetReceiveDataFilter('.*' . preg_quote($this->ReadPropertyString('MQTTBaseTopic')) . '.*');

        // Variables
        $this->RegisterVariableBoolean('Online', 'Online', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Network'
        ]);
        $this->RegisterVariableBoolean('LeakAlarm', 'Leckage-Alarm', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Drops'
        ]);
        $this->RegisterVariableBoolean('WaterRunning', 'Wasser fließt', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => 'Drops'
        ]);
        $this->RegisterVariableFloat('FlowRate', 'Aktueller Durchfluss', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' l/min',
            'ICON'         => 'Speedo'
        ]);
        $this->RegisterVariableFloat('TotalConsumption', 'Gesamtverbrauch', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' m³',
            'ICON'         => 'Drops'
        ]);
        $this->RegisterVariableFloat('TotalConsumptionLiter', 'Gesamtverbrauch (Liter)', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'SUFFIX'       => ' l',
            'ICON'         => 'Drops'
        ]);

        // Variables are read-only

        // Attributes (internal state)
        $this->RegisterAttributeFloat('LastRawTotal', 0.0);

        // Timer for Leak Detection
        $this->RegisterTimer('LeakTimer', 0, 'WATER_LeakTimerTriggered($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        // Register MQTT Filter
        $topic = $this->ReadPropertyString('MQTTBaseTopic');
        $this->SetReceiveDataFilter('.*' . preg_quote($topic) . '.*');

        $onlineOptions = json_encode([
            ['Value' => false, 'Caption' => 'Offline', 'IconValue' => 'Network', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0xFF0000, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF0000],
            ['Value' => true, 'Caption' => 'Online', 'IconValue' => 'Network', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0x00CC00, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00CC00]
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Online'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON' => 'Network',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => $onlineOptions
        ]);

        $leakOptions = json_encode([
            ['Value' => false, 'Caption' => 'OK', 'IconValue' => 'Drops', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0x00CC00, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00CC00],
            ['Value' => true, 'Caption' => 'Leck erkannt!', 'IconValue' => 'Drops', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0xFF0000, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFF0000]
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('LeakAlarm'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON' => 'Drops',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => $leakOptions
        ]);

        $runningOptions = json_encode([
            ['Value' => false, 'Caption' => 'Kein Fluss', 'IconValue' => 'Drops', 'IconActive' => false,
             'ColorActive' => false, 'ColorDisplay' => -1, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => -1],
            ['Value' => true, 'Caption' => 'Laeuft', 'IconValue' => 'Drops', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0x0088FF, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x0088FF]
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('WaterRunning'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON' => 'Drops',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => $runningOptions
        ]);

    }

    public function LeakTimerTriggered(): void
    {
        $irriVar = $this->ReadPropertyInteger('IrrigationVariableID');
        if ($irriVar > 0 && @IPS_VariableExists($irriVar)) {
            if (GetValue($irriVar)) {
                // Bewässerung läuft, also keinen Alarm auslösen!
                IPS_LogMessage('SmartWaterMonitor', 'Maximaler Dauerfluss erreicht, aber Bewässerung ist aktiv. Kein Alarm.');
                return;
            }
        }
        
        // Timer fired -> water running continuously for too long!
        $this->SetTimerInterval('LeakTimer', 0); // Stop timer
        $this->SetValue('LeakAlarm', true);
        $this->SLog('ERROR', 'LECKAGE-ALARM! Wasser fließt ununterbrochen seit ' . $this->ReadPropertyInteger('MaxContinuousFlowMinutes') . ' Minuten!');
    }



    public function ReceiveData(string $JSONString): string
    {
        try {
            $data = json_decode($JSONString);
            if ($data === null && json_last_error() !== JSON_ERROR_NONE) return 'NOK';
            
            if (!isset($data->Topic) || !isset($data->Payload)) {
                return "NOK";
            }
            $topic = $data->Topic;
            $payloadRaw = is_scalar($data->Payload) ? (string)$data->Payload : '';
            $payloadStr = $payloadRaw;
            if (ctype_xdigit($payloadRaw) && strlen($payloadRaw) % 2 === 0) {
                $payloadStr = hex2bin($payloadRaw);
            }
            
            $base = $this->ReadPropertyString('MQTTBaseTopic');

            // Online status (LWT)
            if ($topic === $base . '/status') {
                $isOnline = (strtolower($payloadStr) === 'online');
                $this->SetValue('Online', $isOnline);
                return "OK";
            }

            // Sensor states
            if (strpos($topic, $base) !== false) {
                $value = floatval($payloadStr);
                
                // ESPHome sends 'nan' if a sensor is currently unavailable
                if (!is_finite($value)) {
                    return "OK";
                }
                
                // Flow Rate
                if (strpos($topic, 'flow') !== false || strpos($topic, 'rate') !== false) {
                    $this->SetValue('FlowRate', $value);
                    
                    if ($value > 0) {
                        // Water started running
                        if (!$this->GetValue('WaterRunning')) {
                            $this->SetValue('WaterRunning', true);
                            
                            // Start Leak Timer if configured
                            $maxMinutes = $this->ReadPropertyInteger('MaxContinuousFlowMinutes');
                            if ($maxMinutes > 0) {
                                $this->SetTimerInterval('LeakTimer', $maxMinutes * 60 * 1000);
                            }
                        }
                    } else {
                        // Water stopped running
                        $this->SetValue('WaterRunning', false);
                        $this->SetTimerInterval('LeakTimer', 0); // Stop timer
                        // Optional: Reset Leak Alarm automatically when water stops?
                        // Usually an alarm should be manually acknowledged, but let's reset it for convenience.
                        $this->SetValue('LeakAlarm', false);
                    }
                }
                
                // Total Consumption (ESP sends Liters)
                elseif (strpos($topic, 'total') !== false) {
                    $lastRaw = $this->ReadAttributeFloat('LastRawTotal');
                    $delta = $value - $lastRaw;
                    
                    // If delta is negative, the ESP likely rebooted and started from 0 again.
                    // In this case, the delta is just the new value.
                    if ($delta < 0) {
                        $delta = $value;
                    }
                    
                    $this->WriteAttributeFloat('LastRawTotal', $value);
                    
                    // Add delta to our persistent Symcon variables
                    if ($delta > 0) {
                        $currentLiters = $this->GetValue('TotalConsumptionLiter');
                        $newLiters = $currentLiters + $delta;
                        
                        $this->SetValue('TotalConsumptionLiter', $newLiters);
                        $this->SetValue('TotalConsumption', $newLiters / 1000.0);
                    }
                }
            }
            return "OK";
        } catch (Throwable $e) {
            IPS_LogMessage('SmartWaterMonitor', 'Error in ReceiveData: ' . $e->getMessage());
            return "NOK";
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'TotalConsumption':
                $this->SetValue('TotalConsumption', $Value);
                $this->SetValue('TotalConsumptionLiter', $Value * 1000.0);
                break;
            case 'TotalConsumptionLiter':
                $this->SetValue('TotalConsumptionLiter', $Value);
                $this->SetValue('TotalConsumption', $Value / 1000.0);
                break;
            default:
                throw new Exception("Invalid ident");
        }
    }
}
