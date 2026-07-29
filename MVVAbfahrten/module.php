<?php

declare(strict_types=1);

class MVVAbfahrten extends IPSModuleStrict
{
    public function Create(): void
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('StationID', '91001930');
        $this->RegisterPropertyString('WantedLine', 'S 2');
        $this->RegisterPropertyString('ExcludeDestinations', 'Petershausen, Altomünster');
        $this->RegisterPropertyInteger('UpdateInterval', 60);

        $this->RegisterTimer('UpdateTimer', 0, 'MVV_Update($_IPS[\'TARGET\']);');

        $this->RegisterVariableString('DepartureTime', 'Abfahrtszeit', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Clock'
        ], 1);
        
        $this->RegisterVariableString('DepartureIn', 'In Minuten', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Hourglass'
        ], 2);
        
        $this->RegisterVariableInteger('DepartureDelay', 'Verspätung', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Warning'
        ], 3);
        
        $this->RegisterVariableString('NextDeparture', 'Nächste Abfahrt (Komplett)', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Distance'
        ], 4);
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->SetTimerInterval('UpdateTimer', $this->ReadPropertyInteger('UpdateInterval') * 1000);

        $this->Update();
    }

    public function Update(): void
    {
        $stationID = $this->ReadPropertyString('StationID');
        $wantedLine = $this->ReadPropertyString('WantedLine');
        $excludeString = $this->ReadPropertyString('ExcludeDestinations');

        if (trim($stationID) === '') {
            return;
        }

        $url = 'https://efa.mvv-muenchen.de/ng/XSLT_DM_REQUEST?outputFormat=JSON&language=de&stateless=1&type_dm=stop&name_dm=' . urlencode($stationID) . '&useRealtime=1&mode=direct&limit=20';
        $content = @file_get_contents($url);

        if ($content === false) {
            $this->SendDebug('Update', 'Fehler beim Abrufen der Daten.', 0);
            return;
        }

        $json = json_decode($content, true);

        $foundDeparture = false;

        $exclude = array_map('trim', explode(',', $excludeString));
        $exclude = array_filter($exclude); // Leere Einträge entfernen

        if (isset($json['departureList']) && is_array($json['departureList'])) {
            foreach ($json['departureList'] as $dep) {
                // 1. Linie prüfen
                $lineRaw = $dep['servingLine']['symbol'] ?? '';
                $lineClean = str_replace(' ', '', $lineRaw);
                $wantedClean = str_replace(' ', '', $wantedLine);

                if ($lineClean !== $wantedClean) {
                    continue;
                }

                // 2. Richtung filtern
                $destination = $dep['servingLine']['direction'] ?? '';
                $isOutbound = false;
                foreach ($exclude as $badDest) {
                    if (stripos($destination, $badDest) !== false) {
                        $isOutbound = true;
                        break;
                    }
                }
                if ($isOutbound) {
                    continue;
                }

                // 3. Zeit berechnen
                $isRealtime = false;
                if (isset($dep['realDateTime'])) {
                    $dt = $dep['realDateTime'];
                    $isRealtime = true;
                } else {
                    $dt = $dep['dateTime'] ?? null;
                }

                if (!$dt) {
                    continue;
                }

                $timestamp = mktime((int)$dt['hour'], (int)$dt['minute'], 0, (int)$dt['month'], (int)$dt['day'], (int)$dt['year']);

                // Abfahrten in der Vergangenheit ignorieren (Puffer 60 Sek)
                if ($timestamp < (time() - 60)) {
                    continue;
                }

                $minutesUntil = (int)round(($timestamp - time()) / 60);
                $clockTime = date('H:i', $timestamp);

                $delayMinutes = 0;
                $delayMsg = '';
                if ($isRealtime && isset($dep['dateTime'])) {
                    $planDt = $dep['dateTime'];
                    $planTs = mktime((int)$planDt['hour'], (int)$planDt['minute'], 0, (int)$planDt['month'], (int)$planDt['day'], (int)$planDt['year']);
                    $diff = ($timestamp - $planTs) / 60;
                    if ($diff > 0) {
                        $delayMinutes = (int)round($diff);
                    }
                    if ($delayMinutes >= 2) {
                        $delayMsg = ' (+' . $delayMinutes . ')';
                    }
                }

                $displayText = $clockTime . ' Uhr in ' . $minutesUntil . ' min' . $delayMsg;

                if (isset($dep['realtimeStatus']) && $dep['realtimeStatus'] == 'TRIP_CANCELLED') {
                    $displayText = 'AUSFALL: ' . $displayText;
                }

                $this->SetValue('DepartureTime', $clockTime . ' Uhr');
                $this->SetValue('DepartureIn', 'In ' . $minutesUntil . ' Minuten');
                $this->SetValue('DepartureDelay', $delayMinutes);
                $this->SetValue('NextDeparture', $displayText);
                $foundDeparture = true;
                break; 
            }
        }

        if (!$foundDeparture) {
            $this->SetValue('DepartureTime', '--');
            $this->SetValue('DepartureIn', '--');
            $this->SetValue('DepartureDelay', 0);
            $this->SetValue('NextDeparture', '--');
        }
    }
}
