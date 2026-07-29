<?php

declare(strict_types=1);

class DockerUpdateChecker extends IPSModuleStrict
{
    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('Channel', 'stable');
        $this->RegisterPropertyInteger('UpdateInterval', 21600); // Standardmäßig alle 6 Stunden prüfen

        $this->RegisterTimer('UpdateTimer', 0, 'SDU_Update($_IPS[\'TARGET\']);');

        $this->RegisterVariableString('LocalVersion', 'Aktuelle Version', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Information'
        ], 1);
        
        $this->RegisterVariableInteger('LocalBuild', 'Lokales Build-Datum', [
            'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME,
            'ICON' => 'Clock'
        ], 2);
        
        $this->RegisterVariableInteger('DockerVersion', 'Neueste Docker Version', [
            'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME,
            'ICON' => 'Network'
        ], 3);
        
        $this->RegisterVariableBoolean('UpdateAvailable', 'Update verfügbar?', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Warning'
        ], 4);
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->SetTimerInterval('UpdateTimer', $this->ReadPropertyInteger('UpdateInterval') * 1000);

        $updateOptions = json_encode([
            ['Value' => false, 'Caption' => 'Aktuell', 'IconValue' => 'Warning', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0x00CC00, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0x00CC00],
            ['Value' => true, 'Caption' => 'Update verfügbar!', 'IconValue' => 'Warning', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0xFFA500, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFFA500]
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('UpdateAvailable'), [
            'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
            'ICON' => 'Warning',
            'COLOR' => -1,
            'CONTENT_COLOR' => -1,
            'DISPLAY_TYPE' => 0,
            'PREVIEW_STYLE' => 1,
            'SHOW_PREVIEW' => true,
            'OPTIONS' => $updateOptions
        ]);

        // Den Variablennamen passend zum Channel anpassen
        IPS_SetName($this->GetIDForIdent('DockerVersion'), 'Neueste Docker \'' . $this->ReadPropertyString('Channel') . '\' Version');

        $this->Update();
    }

    public function Update(): void
    {
        $localTimestamp = IPS_GetKernelDate();
        $localVersion = IPS_GetKernelVersion();
        $localRevision = IPS_GetKernelRevision();

        $channel = $this->ReadPropertyString('Channel');
        $url = 'https://hub.docker.com/v2/repositories/symcon/symcon/tags/' . urlencode($channel);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Symcon-Update-Checker');
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response !== false && $httpCode == 200) {
            $data = json_decode((string)$response, true);
            
            $dockerTimestamp = 0;
            
            // Die tatsächlichen Images prüfen, da der Tag-Timestamp ('last_updated') 
            // sich durch Manifest-Änderungen verschieben kann, ohne dass es ein neues Image gibt.
            if (isset($data['images']) && is_array($data['images'])) {
                foreach ($data['images'] as $img) {
                    if (isset($img['last_pushed'])) {
                        $ts = strtotime($img['last_pushed']);
                        if ($ts > $dockerTimestamp) {
                            $dockerTimestamp = $ts;
                        }
                    }
                }
            }
            
            // Fallback auf 'last_updated'
            if ($dockerTimestamp === 0 && isset($data['last_updated'])) {
                $dockerTimestamp = strtotime($data['last_updated']);
            }
            
            if ($dockerTimestamp > 0) {
                // Toleranz 2 Stunden (7200 Sekunden) wegen leichtem Zeitversatz
                $updateAvailable = ($dockerTimestamp > ($localTimestamp + 7200));

                $this->SetValue('LocalVersion', $localVersion . ' (Rev: ' . $localRevision . ')');
                $this->SetValue('LocalBuild', $localTimestamp);
                $this->SetValue('DockerVersion', $dockerTimestamp);
                $this->SetValue('UpdateAvailable', $updateAvailable);
            } else {
                $this->SendDebug('Update', 'Fehler: Weder "images.last_pushed" noch "last_updated" im JSON gefunden.', 0);
            }
        } else {
            $this->SendDebug('Update', 'Fehler bei der API-Abfrage. HTTP-Code: ' . $httpCode, 0);
        }
    }
}
