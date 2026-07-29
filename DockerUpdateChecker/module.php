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

        $this->RegisterVariableString('LocalVersion', 'Aktuelle Version', '', 1);
        $this->RegisterVariableInteger('LocalBuild', 'Lokales Build-Datum', '~UnixTimestamp', 2);
        $this->RegisterVariableInteger('DockerVersion', 'Neueste Docker Version', '~UnixTimestamp', 3);
        $this->RegisterVariableBoolean('UpdateAvailable', 'Update verfügbar?', '', 4);
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->SetTimerInterval('UpdateTimer', $this->ReadPropertyInteger('UpdateInterval') * 1000);

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
