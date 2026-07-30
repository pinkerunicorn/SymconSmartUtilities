<?php

trait SmartLawnAI_Logic {

    public function ScheduledEvaluation(): void {
        $active = GetValue($this->GetIDForIdent('AutomaticActive'));
        if (!$active) return;
        
        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        if (!is_array($zones) || empty($zones)) return;
        
        $sprinklersJson = $this->ReadPropertyString('Sprinklers');
        $sprinklers = json_decode($sprinklersJson, true);
        if (!is_array($sprinklers)) $sprinklers = [];
        
        // Prüfen, ob bereits ein Ventil aktiv ist
        foreach ($zones as $zone) {
            $status = $this->GetZoneStatus($zone['SensorID']);
            if ($status === 'WATERING'|| $status === 'QUEUED') {
                $this->LogAndDebug('Planer', 'Zyklusprüfung übersprungen: Ein Ventil ist bereits aktiv oder in Warteschlange.', 0);
                return;
            }
        }
        
        // Prüfen, ob wir uns in der Sperrzeit befinden
        $fStart = $this->GetTimeAsString('ForbiddenStartTime');
        $fEnd = $this->GetTimeAsString('ForbiddenEndTime');
        if ($fStart !== $fEnd) {
            $now = time();
            $start = strtotime($fStart);
            $end = strtotime($fEnd);
            
            // Falls Endzeit am nächsten Tag liegt (z.B. 22:00 bis 06:00)
            if ($end < $start) {
                if ($now >= $start || $now <= $end) {
                    $this->LogAndDebug('Planer', 'Zyklusprüfung übersprungen: Aktuelle Uhrzeit liegt innerhalb der Sperrzeit.', 0);
                    $this->AddLogEvent("Zyklusprüfung", "Sperrzeit aktiv. Keine automatische Bewässerung.", '#FF9800');
                    return;
                }
            } else {
                if ($now >= $start && $now <= $end) {
                    $this->LogAndDebug('Planer', 'Zyklusprüfung übersprungen: Aktuelle Uhrzeit liegt innerhalb der Sperrzeit.', 0);
                    $this->AddLogEvent("Zyklusprüfung", "Sperrzeit aktiv. Keine automatische Bewässerung.", '#FF9800');
                    return;
                }
            }
        }

        $defaultStart = GetValue($this->GetIDForIdent('DefaultStartSchwellwert'));
        $needsWater = false;
        foreach ($zones as $zone) {
            if (!IPS_VariableExists($zone['SensorID'])) continue;
            $aktuelleFeuchte = GetValue($zone['SensorID']);
            if ($aktuelleFeuchte <= $defaultStart) {
                $needsWater = true;
                break;
            }
        }

        if (!$needsWater) {
            $this->LogAndDebug('Planer', 'Zyklusprüfung: Boden ist ausreichend feucht. Keine Bewässerung nötig.', 0);
            $this->AddLogEvent("Zyklusprüfung", "Boden ist ausreichend feucht. Keine Bewässerung nötig.", '#4CAF50');
            // Wir setzen den Zeitstempel für das Webfront neu, um zu zeigen, dass wir geprüft haben
            $this->SetBuffer('LastPlanCalculation', (string)time());
            $this->ProcessLogic(); // Update Heartbeat
            return;
        }

        $this->LogAndDebug('Planer', 'Zyklusprüfung: Boden ist trocken. Hole Wetter und berechne Laufzeiten...', 0);
        $this->AddLogEvent("Zyklusprüfung", "Boden lokal trocken. Hole Wetter und frage KI...", '#9E9E9E');
        $this->UpdateWeather();
        
        $airTempID = $this->ReadPropertyInteger('GlobalAirTempID');
        $humidityID = $this->ReadPropertyInteger('GlobalHumidityID');
        $illuminanceID = $this->ReadPropertyInteger('GlobalIlluminanceID');
        $t = ($airTempID > 0) ? (float)GetValue($airTempID) : 20.0;
        $rh = ($humidityID > 0) ? (float)GetValue($humidityID) : 50.0;
        $lux = ($illuminanceID > 0) ? (float)GetValue($illuminanceID) : 0.0;
        $es = 0.6108 * exp((17.27 * $t) / ($t + 237.3));
        $vpd = $es * (1 - ($rh / 100.0));

        $this->SetBuffer('LastPlanCalculation', (string)time());
        $this->CalculateAndApplyPlan($zones, $sprinklers, false, $vpd, $lux);
        
        $this->ProcessLogic(); // Update Heartbeat und Starte Zonen-Durchlauf
    }

    public function ProcessLogic(): void {
        $defaultZiel  = GetValue($this->GetIDForIdent('DefaultZielFeuchte'));
        $defaultStart = GetValue($this->GetIDForIdent('DefaultStartSchwellwert'));
        
        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        
        $sprinklersJson = $this->ReadPropertyString('Sprinklers');
        $sprinklers = json_decode($sprinklersJson, true);
        if (!is_array($sprinklers)) $sprinklers = [];
        
        if (!is_array($zones) || empty($zones)) {
            return; 
        }

        // 1. Prüfen, ob bereits ein Ventil aktiv ist oder Zonen in der Warteschlange stehen
        $einVentilIstAktiv = false;
        $anyQueued = false;
        foreach ($zones as $zone) {
            $status = $this->GetZoneStatus($zone['SensorID']);
            if ($status === 'WATERING') {
                $einVentilIstAktiv = true;
                $this->LogAndDebug('Sequencer', 'Ein anderes Ventil blockiert die Sequenz ('. $status . 'bei Zone '. $zone['SensorID'] . '). Warte...', 0);
            }
            if ($status === 'QUEUED') {
                $anyQueued = true;
            }
        }
        
        $wasActive = $this->GetValue('WateringActive');
        $this->SetValue('WateringActive', $einVentilIstAktiv);
        if ($wasActive !== $einVentilIstAktiv && function_exists('SHC_SetIrrigationActive')) {
            SHC_SetIrrigationActive($einVentilIstAktiv);
        }

        // 2. Thermodynamik (VPD) für alle Zonen vorbereiten
        $airTempID = $this->ReadPropertyInteger('GlobalAirTempID');
        $humidityID = $this->ReadPropertyInteger('GlobalHumidityID');
        $illuminanceID = $this->ReadPropertyInteger('GlobalIlluminanceID');

        $t = ($airTempID > 0) ? (float)GetValue($airTempID) : 20.0;
        $rh = ($humidityID > 0) ? (float)GetValue($humidityID) : 50.0;
        $lux = ($illuminanceID > 0) ? (float)GetValue($illuminanceID) : 0.0;

        $es = 0.6108 * exp((17.27 * $t) / ($t + 237.3));
        $vpd = $es * (1 - ($rh / 100.0));

        // 3. Laufzeit-Steuerung des Timers
        $active = GetValue($this->GetIDForIdent('AutomaticActive'));
        if ($active) {
            $this->SetTimerInterval('LawnAITimer', 60000);
        } else {
            $this->SetTimerInterval('LawnAITimer', 0);
        }

        // Manueller Start
        $isManualStart = ($this->GetBuffer('CalculatePlanPending') === 'true');
        if ($isManualStart) {
            $this->SetBuffer('CalculatePlanPending', '');
            $this->LogAndDebug('Planer', 'Neuer Bewässerungszyklus (manuell) initiiert. Berechne Laufzeiten...', 0);
            
            // Wenn keine Koordinaten, UpdateWeather hat keinen Effekt, aber sicherheitshalber aufrufen
            $this->UpdateWeather();
            
            $this->CalculateAndApplyPlan($zones, $sprinklers, true, $vpd, $lux);
            
            $einVentilIstAktiv = false;
            foreach ($zones as $zone) {
                $status = $this->GetZoneStatus($zone['SensorID']);
                if ($status === 'WATERING') {
                    $einVentilIstAktiv = true;
                }
            }
        }

        // 4. Zonen-Durchlauf (State Machine)
        foreach ($zones as $zone) {
            $zielWert  = $defaultZiel;
            $startWert = $defaultStart;
            
            $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $zone['SensorID'];
            $zoneSprinklers = [];
            foreach ($sprinklers as $s) {
                if ($s['ZoneName'] === $zoneName) {
                    $zoneSprinklers[] = $s;
                }
            }

            if (!IPS_VariableExists($zone['SensorID'])) continue;
            $aktuelleFeuchte = GetValue($zone['SensorID']);
            $aktuellerStatus = $this->GetZoneStatus($zone['SensorID']);
            if (empty($aktuellerStatus)) {
                $aktuellerStatus = 'IDLE';
            }
            $this->SendDebug('ProcessLogic', 'Bearbeite Zone '. $zone['SensorID'] . '(Aktueller Status: '. $aktuellerStatus . ')', 0);

            if (empty($zoneSprinklers)) {
                $this->LogAndDebug('ProcessLogic', 'Zone '. $zone['SensorID'] . 'hat keine zugeordneten Sprinkler. Überspringe.', 0);
                continue;
            }

            // Gardena Not-Aus Check (prüfe alle Sprinkler dieser Zone)
            $hardwareFehler = false;
            $fehlerhafterSprinklerName = '';
            foreach ($zoneSprinklers as $s) {
                $res = $this->ResolveSprinklerObject((int)@$s['ValveID']);
                if ($res['HardwareStatusID'] > 0) {
                    $hwStatus = GetValue($res['HardwareStatusID']);
                    $hwStr = strtoupper((string)$hwStatus);
                    if (in_array($hwStr, ['ERROR', 'WARNING', 'OFFLINE', 'DEFECT', 'FAULT'])) {
                        $sName = isset($s['SprinklerName']) && !empty($s['SprinklerName']) ? $s['SprinklerName'] : 'Sprinkler '. $s['ValveID'];
                        $this->LogAndDebug('Hardware-Check', 'Zone '. $zone['SensorID'] . ''. $sName . 'meldet Fehler: '. $hwStr, 0);
                        $hardwareFehler = true;
                        $fehlerhafterSprinklerName = $sName;
                        break;
                    }
                }
            }
            if ($hardwareFehler) {
                $this->SLog('ERROR', 'HARDWARE_FEHLER', 'Zone: ' . $zone['SensorID'] . ' | Sprinkler: ' . $fehlerhafterSprinklerName . ' meldet Defekt');
                $this->SetZoneStatus($zone['SensorID'], 'HARDWARE_FEHLER');
                continue; 
            }

            $currentIndex = $this->GetZoneCurrentSprinklerIndex($zone['SensorID']);
            if (!isset($zoneSprinklers[$currentIndex])) {
                $currentIndex = 0;
            }
            $currentSprinkler = $zoneSprinklers[$currentIndex];
            $currentSprinklerName = isset($currentSprinkler['SprinklerName']) && !empty($currentSprinkler['SprinklerName']) ? $currentSprinkler['SprinklerName'] : 'Sprinkler '. $currentSprinkler['ValveID'];

            switch ($aktuellerStatus) {
                case 'IDLE':
                case 'QUEUED':
                    $sollStarten = ($aktuellerStatus === 'QUEUED');

                    if ($sollStarten) {
                        if ($einVentilIstAktiv) {
                            $this->LogAndDebug('Sequencer', 'Zone '. $zone['SensorID'] . 'bleibt QUEUED, da ein anderes Ventil aktiv ist.', 0);
                            $this->SetZoneStatus($zone['SensorID'], 'QUEUED');
                        } else {
                            $this->LogAndDebug('Sequencer', 'Startbedingung erfüllt. Bereite Befehl vor...', 0);
                            
                            $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $zone['SensorID'];
                            
                            // Berechnete Laufzeit aus Puffer lesen
                            $berechneteMinuten = $this->GetZoneDauer($zone['SensorID']);
                            if ($berechneteMinuten <= 0) {
                                $this->LogAndDebug('Sequencer', 'Zone '. $zone['SensorID'] . 'hat keine gültige Dauer. Überspringe.', 0);
                                $this->SetZoneStatus($zone['SensorID'], 'IDLE');
                                continue 2;
                            }

                            $res = $this->ResolveSprinklerObject((int)@$currentSprinkler['ValveID']);
                            // Gardena Hardware-Watchdog: Dauer setzen
                            if ($res['DurationID'] > 0) {
                                $this->SafeRequestAction($res['DurationID'], $berechneteMinuten);
                            }

                            // Start-Befehl senden (Gardena spezifisch)
                            $startErfolgreich = false;
                            if ($res['ValveID'] > 0) {
                                if (IPS_VariableExists($res['ValveID']) && in_array(strtolower(IPS_GetObject($res['ValveID'])['ObjectIdent']), ['action', 'valvecontrol', 'control'])) {
                                    $startErfolgreich = $this->SafeRequestAction($res['ValveID'], 'START_SECONDS_TO_OVERRIDE');
                                } else {
                                    $startErfolgreich = $this->SafeRequestAction($res['ValveID'], true);
                                }
                            }
                            
                            if ($startErfolgreich) {
                                $this->SLog('INFO', 'Bewässerungs-Startbefehl gesendet', 'Zone: ' . $zone['SensorID'] . ' | Sprinkler: ' . $currentSprinklerName);
                                $this->SetZoneStatus($zone['SensorID'], 'WAITING_FOR_OPEN');
                                $this->SetZoneWateringStart($zone['SensorID'], time());
                                $this->SetZoneCurrentSprinklerIndex($zone['SensorID'], $currentIndex);
                                $this->AddLogEvent("{$zoneName}: Starte Bewässerung", "Sprinkler: {$currentSprinklerName}", '#2196F3');
                                
                                // Zwischenspeichern für den Lern-Algorithmus später
                                $this->SetZoneStartFeuchte($zone['SensorID'], $aktuelleFeuchte);
                                $this->SetZoneDauer($zone['SensorID'], $berechneteMinuten);
                                
                                // Wasserzähler-Startwert merken (nur beim Start der Zone oder wenn Buffer leer)
                                $wLiterID = $this->GetWaterMeterLiterVarID();
                                if ($wLiterID > 0) {
                                    $existingBuffer = $this->GetBuffer('WaterMeterStart_' . $zone['SensorID']);
                                    if ($existingBuffer === '' || $currentIndex === 0) {
                                        $this->SetBuffer('WaterMeterStart_' . $zone['SensorID'], (string)GetValue($wLiterID));
                                    }
                                }
                                
                                $einVentilIstAktiv = true; 
                            } else {
                                $this->LogAndDebug('Sequencer', 'Fehler: Start-Befehl für Zone '. $zone['SensorID'] . 'konnte nicht gesendet werden.', 0);
                                $this->SetZoneStatus($zone['SensorID'], 'HARDWARE_FEHLER');
                                $this->AddLogEvent("{$zoneName}: Hardware Fehler", "API oder Sendefehler beim Starten", '#F44336');
                            } 
                        }
                    } else {
                        $this->SetZoneStatus($zone['SensorID'], 'IDLE');
                    }
                    break;
                    
                case 'WAITING_FOR_OPEN':
                case 'WATERING':
                    // Ventil-Rückkanal von Gardena prüfen
                    $ventilOffen = false;
                    $hwVal = 'UNKNOWN';
                    $res = $this->ResolveSprinklerObject((int)@$currentSprinkler['ValveID']);
                    
                    if ($res['ActivityID'] > 0) {
                        $v = GetValue($res['ActivityID']);
                        if (is_int($v) || is_float($v)) {
                            $act = strtoupper((string)GetValueFormatted($res['ActivityID']));
                        } else {
                            $act = strtoupper((string)$v);
                        }
                        $hwVal = $act;
                        $ventilOffen = (strpos($act, 'WATERING') !== false || strpos($act, 'BEWÄSSERUNG') !== false || strpos($act, 'OPEN') !== false || strpos($act, 'GEÖFFNET') !== false || $act === '1'|| $v == 1 || $v == 2 || $v == 3);
                    }
                    elseif ($res['HardwareStatusID'] > 0) {
                        $hwVal = strtoupper((string)GetValue($res['HardwareStatusID']));
                        $ventilOffen = in_array($hwVal, ['MANUAL_WATERING', 'AUTOMATIC_WATERING', 'WATERING', 'OPEN', 'GEÖFFNET', 'BEWÄSSERUNG']);
                    } else {
                        if ($res['ValveID'] > 0 && IPS_VariableExists($res['ValveID'])) {
                            $v = GetValue($res['ValveID']);
                            $ventilOffen = ($v == 1 || $v === true); // 1 = START_SECONDS_TO_OVERRIDE
                        }
                    }
                    
                    // Fallback: Wenn Sekunden noch > 0 sind, läuft es definitiv noch!
                    if (!$ventilOffen && $res['RemainingSecondsID'] > 0) {
                        if ((int)GetValue($res['RemainingSecondsID']) > 0) {
                            $ventilOffen = true;
                            $hwVal .= '(Kept alive by RemainingSeconds > 0)';
                        }
                    }

                    if ($aktuellerStatus === 'WAITING_FOR_OPEN') {
                        if ($ventilOffen) {
                            $this->LogAndDebug('Sequencer', 'Rückmeldung erhalten: Ventil ist OFFEN. Bewässerung läuft.', 0);
                            $this->SetZoneStatus($zone['SensorID'], 'WATERING');
                            $this->SetZoneWateringStart($zone['SensorID'], time()); // ECHTE Startzeit!
                            // Fallback: Wasserzähler-Startwert erfassen falls bisher leer
                            $wLiterID = $this->GetWaterMeterLiterVarID();
                            if ($wLiterID > 0 && $this->GetBuffer('WaterMeterStart_' . $zone['SensorID']) === '') {
                                $this->SetBuffer('WaterMeterStart_' . $zone['SensorID'], (string)GetValue($wLiterID));
                            }
                            $aktuellerStatus = 'WATERING';
                        } else {
                            $wateringStart = $this->GetZoneWateringStart($zone['SensorID']);
                            if ((time() - $wateringStart) > 180) { // 3 Minuten Timeout!
                                $this->SLog('ERROR', 'TIMEOUT beim Ventil-Start', 'Sprinkler: ' . $currentSprinklerName . ' meldet nicht OPEN nach 3 Minuten');
                                $this->AddLogEvent("Timeout", "{$currentSprinklerName} meldet nicht OPEN.", '#F44336');
                                $aktuellerStatus = 'WATERING'; // force next logic block to finish it
                            } else {
                                $this->LogAndDebug('Sequencer', 'Warte auf Cloud-Rückmeldung (bisher '. (time()-$wateringStart) . 's)', 0);
                                $einVentilIstAktiv = true; // Blockiere andere Zonen
                            }
                        }
                    }
                    
                    if ($aktuellerStatus === 'WATERING') {
                    if ($res['RemainingSecondsID'] > 0) {
                        $remaining = (int)GetValue($res['RemainingSecondsID']);
                    } else {
                        $wStart = $this->GetZoneWateringStart($zone['SensorID']);
                        $dMin = $this->GetZoneDauer($zone['SensorID']);
                        if ($wStart > 0 && $dMin > 0) {
                            $remaining = max(0, ($dMin * 60) - (time() - $wStart));
                        }
                    }
                    if ($remaining > 0) {
                        $m = floor($remaining / 60);
                        $s = $remaining % 60;
                        $remainingText = '(noch '. $m . ':'. str_pad((string)$s, 2, '0', STR_PAD_LEFT) . 'Min)';
                    } else {
                        $remainingText = '';
                    }

                    if (!$ventilOffen && $aktuellerStatus === 'WATERING') {
                        $this->SLog('INFO', 'Bewässerung beendet', 'Sprinkler: ' . $currentSprinklerName . ' in Zone ' . $zone['SensorID'] . ' | Status: ' . $hwVal);
                        
                        $currentIndex++;
                        if ($currentIndex < count($zoneSprinklers)) {
                            // Nächster Sprinkler in dieser Zone
                            $this->SetZoneCurrentSprinklerIndex($zone['SensorID'], $currentIndex);
                            $this->SetZoneStatus($zone['SensorID'], 'QUEUED');
                            $this->LogAndDebug('Sequencer', 'Sprinkler gewechselt. Nächster Index: '. $currentIndex, 0);
                            
                            $nextSprinklerName = isset($zoneSprinklers[$currentIndex]['SprinklerName']) && !empty($zoneSprinklers[$currentIndex]['SprinklerName']) ? $zoneSprinklers[$currentIndex]['SprinklerName'] : 'Sprinkler '. ($currentIndex + 1);
                            $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $zone['SensorID'];
                            $this->AddLogEvent("{$zoneName}: Sprinklerwechsel", "Nächster Sprinkler: {$nextSprinklerName}", '#2196F3');
                        } else {
                            // Alle Sprinkler der Zone fertig → Wasserverbrauch berechnen
                            $wLiterID = $this->GetWaterMeterLiterVarID();
                            if ($wLiterID > 0) {
                                $wStartRaw = $this->GetBuffer('WaterMeterStart_' . $zone['SensorID']);
                                $wStart = (float)$wStartRaw;
                                $wEnd   = (float)GetValue($wLiterID);
                                
                                if ($wStartRaw !== '' && $wStart > 0 && $wEnd >= $wStart) {
                                    $consumed = round($wEnd - $wStart, 1);
                                    if ($consumed > 0 && $consumed < 5000) {
                                        $this->SetValue('WaterLastSession', $consumed);
                                        $this->SetValue('WaterToday',     round($this->GetValue('WaterToday')     + $consumed, 1));
                                        $this->SetValue('WaterThisWeek',  round($this->GetValue('WaterThisWeek')  + $consumed, 1));
                                        $this->SetValue('WaterThisMonth', round($this->GetValue('WaterThisMonth') + $consumed, 1));
                                        $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $zone['SensorID'];
                                        $this->AddLogEvent("{$zoneName}: Verbrauch", "{$consumed} L verbraucht", '#03A9F4');
                                        $this->SLog('INFO', 'Wasserverbrauch Zone ' . ($zone['GroupName'] ?? $zone['SensorID']), $consumed . ' L');
                                    } else {
                                        $this->SLog('WARNING', 'Wasserverbrauch unplausibel, ignoriert: ' . $consumed . ' L (Start: ' . $wStart . ' L, Ende: ' . $wEnd . ' L)');
                                    }
                                } else {
                                    $this->SLog('WARNING', 'Wasserzähler-Startwert ungültig oder nicht vorhanden (Start: ' . $wStartRaw . ' L, Ende: ' . $wEnd . ' L)');
                                }
                                $this->SetBuffer('WaterMeterStart_' . $zone['SensorID'], '');
                            }

                            $this->SetZoneCurrentSprinklerIndex($zone['SensorID'], 0); // Reset
                            $this->SetZoneStatus($zone['SensorID'], 'WAITING_FOR_RESULT');
                            $this->SetZoneSickerpauseStart($zone['SensorID'], time());
                            $this->LogAndDebug('Sequencer', 'Alle Sprinkler fertig. Sickerpause gestartet.', 0);
                            
                            $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $zone['SensorID'];
                            $sickerpauseMin = GetValue($this->GetIDForIdent('SickerpauseMinuten'));
                            $this->AddLogEvent("{$zoneName}: Sickerpause", "Warte {$sickerpauseMin} Minuten auf Sensormessung", '#FF9800');
                        }
                    }
                        }
                    break;

                case 'WAITING_FOR_RESULT':
                    $sickerStart = $this->GetZoneSickerpauseStart($zone['SensorID']);
                    // Sickerpause in Sekunden abwarten
                    $sickerpauseSek = GetValue($this->GetIDForIdent('SickerpauseMinuten')) * 60;
                    if ((time() - $sickerStart) > $sickerpauseSek) {
                        
                        // Lernerfolg auswerten via Gemini
                        $startFeuchte = $this->GetZoneStartFeuchte($zone['SensorID']);
                        $dauer = $this->GetZoneDauer($zone['SensorID']);
                        
                        if ($dauer > 0) {
                            $this->EvaluateEfficiencyWithGemini($zone['SensorID'], $startFeuchte, $aktuelleFeuchte, $dauer, $vpd, $lux);
                        }

                        $this->SetZoneStatus($zone['SensorID'], 'IDLE');
                    }
                    break;
            }
        }

        // 5. Wasserzähler Tages-/Wochen-/Monatsreset
        $today = date('Y-m-d');
        $week  = date('oW');   // ISO Jahr + Wochennummer
        $month = date('Y-m');
        $wBuf  = json_decode($this->GetBuffer('WaterResetDates'), true);
        if (!is_array($wBuf)) $wBuf = [];
        if (($wBuf['day']   ?? '') !== $today) { $this->SetValue('WaterToday',     0.0); $wBuf['day']   = $today; }
        if (($wBuf['week']  ?? '') !== $week)  { $this->SetValue('WaterThisWeek',  0.0); $wBuf['week']  = $week; }
        if (($wBuf['month'] ?? '') !== $month) { $this->SetValue('WaterThisMonth', 0.0); $wBuf['month'] = $month; }
        $this->SetBuffer('WaterResetDates', json_encode($wBuf));

        // 6. Heartbeat für die Webfront Anzeige (Zeitstempel aktualisieren)
        $automaticActive = GetValue($this->GetIDForIdent('AutomaticActive'));
        if ($automaticActive) {
            $currentStatus = GetValue($this->GetIDForIdent('SummaryStatus'));
            $baseStatus = preg_replace('/ \(\d{2}:\d{2}\)$/', '', $currentStatus);

            $hwZone = null;
            $waterZone = null;
            $sickerZone = null;
            $queuedZone = null;
            
            foreach ($zones as $zone) {
                $status = $this->GetZoneStatus($zone['SensorID']);
                if ($status === 'HARDWARE_FEHLER') $hwZone = $zone;
                elseif ($status === 'WATERING'|| $status === 'WAITING_FOR_OPEN') $waterZone = $zone;
                elseif ($status === 'WAITING_FOR_RESULT') $sickerZone = $zone;
                elseif ($status === 'QUEUED') $queuedZone = $zone;
            }

            $einVentilIstAktivOderFehler = ($hwZone || $waterZone || $sickerZone || $queuedZone);

            if ($hwZone) {
                $zoneName = isset($hwZone['GroupName']) && !empty($hwZone['GroupName']) ? $hwZone['GroupName'] : 'Zone '. $hwZone['SensorID'];
                $baseStatus = 'HARDWARE-FEHLER: '. $zoneName;
            } elseif ($waterZone) {
                $zoneName = isset($waterZone['GroupName']) && !empty($waterZone['GroupName']) ? $waterZone['GroupName'] : 'Zone '. $waterZone['SensorID'];
                
                $zSprinklers = [];
                foreach ($sprinklers as $s) {
                    if ($s['ZoneName'] === $zoneName) $zSprinklers[] = $s;
                }
                $cIdx = $this->GetZoneCurrentSprinklerIndex($waterZone['SensorID']);
                if (!isset($zSprinklers[$cIdx])) $cIdx = 0;
                
                $remainingText = '';
                $cName = 'Sprinkler';
                if (isset($zSprinklers[$cIdx])) {
                    $cSpr = $zSprinklers[$cIdx];
                    $cName = isset($cSpr['SprinklerName']) && !empty($cSpr['SprinklerName']) ? $cSpr['SprinklerName'] : 'Sprinkler '. $cSpr['ValveID'];
                    
                    $rem = 0;
                    if (isset($cSpr['RemainingSecondsID']) && $cSpr['RemainingSecondsID'] > 0) {
                        $rem = (int)GetValue($cSpr['RemainingSecondsID']);
                    } else {
                        $wStart = $this->GetZoneWateringStart($waterZone['SensorID']);
                        $dMin = $this->GetZoneDauer($waterZone['SensorID']);
                        if ($wStart > 0 && $dMin > 0) {
                            $rem = max(0, ($dMin * 60) - (time() - $wStart));
                        }
                    }
                    if ($rem > 0) {
                        $m = floor($rem / 60);
                        $s = $rem % 60;
                        $remainingText = '(noch '. $m . ':'. str_pad((string)$s, 2, '0', STR_PAD_LEFT) . 'Min)';
                    }
                }
                
                $isWaiting = ($this->GetZoneStatus($waterZone['SensorID']) === 'WAITING_FOR_OPEN');
                if ($isWaiting) {
                    $baseStatus = 'Wartet auf Ventil: '. $zoneName . '('. $cName . ')';
                } else {
                    $baseStatus = 'Bewässert: '. $zoneName . '('. $cName . ')'. $remainingText;
                }
            } elseif ($sickerZone) {
                $zoneName = isset($sickerZone['GroupName']) && !empty($sickerZone['GroupName']) ? $sickerZone['GroupName'] : 'Zone '. $sickerZone['SensorID'];
                $baseStatus = 'Sickerpause: '. $zoneName;
            } elseif (!$einVentilIstAktivOderFehler && strpos($baseStatus, 'Berechne') === false && strpos($baseStatus, 'Manueller Start') === false) {
                $nextTime = $this->GetNextScheduleTime();
                if ($nextTime > 0) {
                    $dayStr = (date('Y-m-d', $nextTime) === date('Y-m-d')) ? 'heute': 'morgen';
                    $baseStatus = 'Bereit (Nächste Ausführung: ' . $dayStr . ' um ' . date('H:i', $nextTime) . ' Uhr)';
                } else {
                    $baseStatus = 'Bereit';
                }
                
                $splitterID = $this->ReadPropertyInteger('GardenaSplitterID');
                if ($splitterID > 0 && IPS_InstanceExists($splitterID)) {
                    $splitterStatus = IPS_GetInstance($splitterID)['InstanceStatus'];
                    if ($splitterStatus >= 200) {
                        $baseStatus = 'Gardena Cloud Verbindung getrennt';
                    }
                }
            }
            
            $this->SetSummaryStatus($baseStatus);

            // Timer-Intervall auf 10s verkürzen, wenn Aktion läuft, sonst 60s
            if ($einVentilIstAktivOderFehler) {
                $this->SetTimerInterval('LawnAITimer', 10000);
            } else {
                $this->SetTimerInterval('LawnAITimer', 60000);
            }
        }
    }

    private function CalculateAndApplyPlan(array $zones, array $sprinklers, bool $isManualStart, float $vpd, float $lux): void {
        $this->SetSummaryStatus('Berechne Bewässerungsplan (Gemini AI)...');

        // SmartGeminiIO auto-discover
        $geminiInstances = IPS_GetInstanceListByModuleID('{4C8B2A6D-9E3F-4A7B-8C5D-1F6E2A3B7C4D}');
        if (empty($geminiInstances)) {
            $this->LogAndDebug('Planer', 'SmartGeminiIO Instanz nicht gefunden! Bitte eine erstellen.', 0);
            $this->SLog('ERROR', 'SmartGeminiIO Instanz nicht gefunden', 'Bitte Instanz konfigurieren');
            $this->SetSummaryStatus('Fehler: SmartGeminiIO nicht konfiguriert');
            return;
        }
        $geminiId = $geminiInstances[0];

        $ambientContext = [
            'airTemperatureCelsius'=> ($this->ReadPropertyInteger('GlobalAirTempID') > 0) ? (float)GetValue($this->ReadPropertyInteger('GlobalAirTempID')) : 20.0,
            'relativeHumidityPercent'=> ($this->ReadPropertyInteger('GlobalHumidityID') > 0) ? (float)GetValue($this->ReadPropertyInteger('GlobalHumidityID')) : 50.0,
            'illuminanceLux'=> $lux,
            'vaporPressureDeficitKpa'=> $vpd,
            'manualStartTriggered'=> $isManualStart,
            'timestamp'=> time()
        ];
        
        $rainToday = GetValue($this->GetIDForIdent('ForecastRainToday'));
        $rainTomorrow = GetValue($this->GetIDForIdent('ForecastRainTomorrow'));
        $ambientContext['weatherForecast'] = "Erwartete Regenmenge: Heute $rainToday mm, Morgen $rainTomorrow mm";

        $defaultZiel = GetValue($this->GetIDForIdent('DefaultZielFeuchte'));
        $defaultStart = GetValue($this->GetIDForIdent('DefaultStartSchwellwert'));

        $zonesContext = [];
        foreach ($zones as $zone) {
            $sid = $zone['SensorID'];
            if (!$this->isZoneHardwareOk($zone, $sprinklers)) {
                $this->LogAndDebug('Planer', 'Zone '. $sid . 'übersprungen (Hardware-Fehler).', 0);
                $this->SetZoneStatus($sid, 'HARDWARE_FEHLER');
                continue;
            }

            $zielWert  = $defaultZiel;
            $startWert = $defaultStart;
            $aktuelleFeuchte = GetValue($sid);

            // ERZWINGE EREIGNISSTEUERUNG:
            // Zone nur beplanen, wenn manueller Start oder Trigger-Schwellwert erreicht!
            if (!$isManualStart && $aktuelleFeuchte > $startWert) {
                $this->LogAndDebug('Planer', 'Zone '. $sid . 'ignoriert. Feuchte ('. $aktuelleFeuchte . '%) liegt über dem Trigger ('. $startWert . '%).', 0);
                $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $sid;
                $this->AddLogEvent("{$zoneName}: Ignoriert", "Feuchte ({$aktuelleFeuchte}%) > Start-Wert ({$startWert}%)", '#4CAF50');
                continue;
            }

            $effizienz = $this->GetZoneEffizienz($sid);
            if ($effizienz <= 0) $effizienz = 1.0;
            $maxDuration = GetValue($this->GetIDForIdent('GlobalMaxDuration'));

            $zonesContext[] = [
                'zoneId'=> (int)$sid,
                'groupName'=> isset($zone['GroupName']) ? $zone['GroupName'] : ('Zone '. $sid),
                'currentMoisturePercent'=> $aktuelleFeuchte,
                'targetMoisturePercent'=> $zielWert,
                'startMoisturePercent'=> $startWert,
                'learnedEfficiencyPercentPerMinute'=> $effizienz,
                'maxDurationMinutes'=> $maxDuration
            ];
        }

        if (empty($zonesContext)) {
            $this->LogAndDebug('Planer', 'Keine betriebsbereiten Zonen gefunden.', 0);
            return;
        }

        // 2. Prompt und Instruktion für Gemini erstellen
        $userPrompt = "Erstelle den optimalen Bewässerungsplan.\n\n";
        $userPrompt .= "UMGEBUNGSDATEN & VORHERSAGE:\n". json_encode($ambientContext, JSON_PRETTY_PRINT) . "\n\n";
        $userPrompt .= "ZONEN MIT SENSORIK UND VENTILEN:\n". json_encode($zonesContext, JSON_PRETTY_PRINT) . "\n\n";
        $userPrompt .= "Berücksichtige bei der Laufzeitberechnung:\n";
        $userPrompt .= "- Ist die Bodentemperatur zu niedrig, kühle den Boden nicht weiter ab.\n";
        $userPrompt .= "- Nutze Helligkeit und Luftfeuchte, um die aktuelle Verdunstungsrate abzuschätzen.\n";
        $userPrompt .= "- Berechne für JEDE 'zoneId'die exakte Laufzeit in Minuten (0 bis maxDurationMinutes).\n";
        
        $systemInstruction = "Du bist ein präzises Steuerungsmodul für Agrarsysteme. Deine Aufgabe ist es, für die übergebenen Zonen-IDs (zoneId) Laufzeiten in Minuten zu berechnen. Antworte ausschließlich im vorgegebenen JSON-Format.";

        // 3. API-Aufruf (Gemini mit striktem JSON Schema)

        $responseSchema = [
            'type'=> 'OBJECT',
            'properties'=> [
                'irrigationPlan'=> [
                    'type'=> 'ARRAY',
                    'description'=> 'Liste der berechneten Bewässerungszeiten pro Ventil.',
                    'items'=> [
                        'type'=> 'OBJECT',
                        'properties'=> [
                            'zoneId'=> [
                                'type'=> 'INTEGER',
                                'description'=> 'Die ID der Zone (Beregnungskreis).'
                            ],
                            'durationMinutes'=> [
                                'type'=> 'INTEGER',
                                'description'=> 'Die exakte Bewässerungsdauer in Minuten (0 falls nicht bewässert werden soll).'
                            ],
                            'reasoning'=> [
                                'type'=> 'STRING',
                                'description'=> 'Kurze agronomische Begründung für diese Entscheidung.'
                            ]
                        ],
                        'required'=> ['zoneId', 'durationMinutes', 'reasoning']
                    ]
                ]
            ],
            'required'=> ['irrigationPlan']
        ];

        $this->LogAndDebug('Planer', 'Gemini Anfrage wird gesendet...', 0);
        $this->LogAndDebug('Planer Prompt', $userPrompt, 0);

        $responseSchema = json_encode($responseSchema);
        $instanceId     = $this->InstanceID;
        $isManualInt    = $isManualStart ? 1 : 0;

        // Async via IPS_RunScriptText — GIO_Query blockiert, daher in Background
        $script = '<?php
            $result = GIO_Query(' . $geminiId . ',
                ' . var_export($userPrompt, true) . ',
                ' . var_export($systemInstruction, true) . ',
                ' . var_export($responseSchema, true) . ',
                0.1
            );
            SLAI_ProcessGeminiPlanResult(' . $instanceId . ', $result, ' . $isManualInt . ');
        ';
        IPS_RunScriptText($script);
    }

    public function ProcessGeminiPlanResult(string $jsonText, int $isManualStartInt): void {
        $isManualStart = (bool)$isManualStartInt;
        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        if (!is_array($zones)) $zones = [];

        $sprinklersJson = $this->ReadPropertyString('Sprinklers');
        $sprinklers = json_decode($sprinklersJson, true);
        if (!is_array($sprinklers)) $sprinklers = [];

        if (empty($jsonText)) {
            $this->LogAndDebug('Planer Fehler', 'SmartGeminiIO lieferte keine Antwort.', 0);
            $this->SLog('ERROR', 'Gemini Plan-Anfrage fehlgeschlagen', 'Leere Antwort');
            $this->SetSummaryStatus('Fehler: Gemini API (keine Antwort)');
            $this->AddLogEvent('API Fehler', 'Keine Antwort von SmartGeminiIO.', '#F44336');
            return;
        }

        $this->LogAndDebug('Planer Antwort', $jsonText, 0);

        $planData = json_decode($jsonText, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($planData['irrigationPlan']) || !is_array($planData['irrigationPlan'])) {
            $this->LogAndDebug('Planer Fehler', 'Plan-JSON konnte nicht geparst werden.', 0);
            $this->SetSummaryStatus('Fehler: Gemini JSON-Parsing fehlgeschlagen');
            return;
        }

        $reasoningText = date('d.m.Y H:i') . "Uhr:\n";
        foreach ($planData['irrigationPlan'] as $item) {
            $zId = isset($item['zoneId']) ? $item['zoneId'] : 'Unbekannt';
            $dur = isset($item['durationMinutes']) ? $item['durationMinutes'] : 0;
            $res = isset($item['reasoning']) ? $item['reasoning'] : '-';
            $reasoningText .= "Zone {$zId} ({$dur} Min): {$res}\n";
        }
        $this->SetValue('LastGeminiResponse', trim($reasoningText));

        // Apply Gemini calculations
        $planByZone = [];
        foreach ($planData['irrigationPlan'] as $item) {
            if (isset($item['zoneId'])) {
                $planByZone[(int)$item['zoneId']] = $item;
            }
        }

        foreach ($zones as $zone) {
            $sid = $zone['SensorID'];
            if (!$this->isZoneHardwareOk($zone, $sprinklers)) {
                continue;
            }

            if (isset($planByZone[$sid])) {
                $zonePlan = $planByZone[$sid];
                $duration = isset($zonePlan['durationMinutes']) ? (int)$zonePlan['durationMinutes'] : 0;
                
                if ($duration <= 0) {
                    // This will be handled in the $duration > 0 check below, so don't continue here
                    // just let it fall through so the reasoning can be logged.
                }

                $reasoning = $zonePlan['reasoning'];
                
                $maxDuration = GetValue($this->GetIDForIdent('GlobalMaxDuration'));
                if ($duration > $maxDuration) {
                    $duration = $maxDuration;
                }

                $this->SetZoneDauer($sid, $duration);
                $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $sid;
                
                if ($duration > 0) {
                    $this->SetZoneStatus($sid, 'QUEUED');
                    $this->LogAndDebug('Planer', 'Zone '. $sid . 'eingereiht (Gemini): '. $duration . 'Minuten. Begründung: '. $reasoning, 0);
                    $this->AddLogEvent("{$zoneName}: Plan berechnet", "Dauer: {$duration} Min. Grund: {$reasoning}", '#673AB7');
                } else {
                    $this->SetZoneStatus($sid, 'IDLE');
                    $this->LogAndDebug('Planer', 'Zone '. $sid . 'nicht eingereiht (Gemini Dauer = 0). Begründung: '. $reasoning, 0);
                    $this->AddLogEvent("{$zoneName}: Ausgesetzt", "KI Dauer: 0 Min. Grund: {$reasoning}", '#9E9E9E');
                }
            } else {
                $this->SetZoneStatus($sid, 'IDLE');
                $this->SetZoneDauer($sid, 0);
                $this->LogAndDebug('Planer', 'Zone '. $sid . 'nicht im Gemini Plan enthalten. Gesetzt auf IDLE.', 0);
            }
        }
        
        $anyQueued = false;
        foreach ($zones as $zone) {
            if ($this->GetZoneStatus($zone['SensorID']) === 'QUEUED') {
                $anyQueued = true;
                break;
            }
        }
        if ($anyQueued) {
            $this->SetSummaryStatus('Plan berechnet. Bewässerung startet gleich.');
        } else {
            $this->SetSummaryStatus('Standby (Boden ausreichend feucht)');
        }
    }

    private function resetAllZones(bool $queueForStart): void {
        $actionName = $queueForStart ? 'ManualStart (Hard Reset)': 'Automatik Off (Hard Stop)';
        $this->LogAndDebug('Reset', $actionName . 'aufgerufen', 0);
        
        if (!$queueForStart) {
            $this->SLog('WARNING', 'Automatik deaktiviert', 'Alle Ventile werden gestoppt und Zonen zurückgesetzt.');
            $this->SetSummaryStatus('Automatik deaktiviert (Zonen gestoppt)');
            $this->AddLogEvent("System: Abbruch", "Automatik deaktiviert, alle Ventile gestoppt.", '#F44336');
        }

        $zonesJson = $this->ReadPropertyString('Zones');
        $zones = json_decode($zonesJson, true);
        $sprinklersJson = $this->ReadPropertyString('Sprinklers');
        $sprinklers = json_decode($sprinklersJson, true);
        if (is_array($sprinklers)) {
            foreach ($sprinklers as $s) {
                $res = $this->ResolveSprinklerObject((int)@$s['ValveID']);
                if ($res['ValveID'] > 0) {
                    if (IPS_VariableExists($res['ValveID']) && in_array(strtolower(IPS_GetObject($res['ValveID'])['ObjectIdent']), ['action', 'valvecontrol', 'control'])) {
                        $this->SafeRequestAction($res['ValveID'], 'STOP_UNTIL_NEXT_TASK');
                    } else {
                        $this->SafeRequestAction($res['ValveID'], false);
                    }
                }
            }
        }

        if (is_array($zones)) {
            foreach ($zones as $zone) {
                $sid = $zone['SensorID'];
                
                $this->SetZoneStartFeuchte($sid, 0.0);
                $this->SetZoneDauer($sid, 0);
                $this->SetZoneCurrentSprinklerIndex($sid, 0);
                $this->SetZoneSickerpauseStart($sid, 0);
                $this->SetZoneWateringStart($sid, 0);
                $this->SetBuffer('WaterMeterStart_' . $sid, '');

                $newStatus = $queueForStart ? 'QUEUED': 'IDLE';
                $this->SetZoneStatus($sid, $newStatus);
                
                if ($queueForStart) {
                    $this->LogAndDebug('Reset', 'Zone '. $sid . 'hart resettet und -> QUEUED.', 0);
                    $this->SLog('INFO', 'Zone manuell zurückgesetzt', 'Zone: ' . $sid . ' in Warteschlange eingereiht');
                } else {
                    $this->LogAndDebug('Reset', 'Zone '. $sid . 'hart resettet und gestoppt -> IDLE.', 0);
                }
            }
        }
        
        // Kurze Pause, damit Gardena die Aus-Befehle sicher verarbeitet hat
        IPS_Sleep(1000);
        
        if ($queueForStart) {
            $this->ProcessLogic();
        }
    }

    private function triggerManualStart(): void {
        $this->SetSummaryStatus('Manueller Start angefordert...');
        $this->LogAndDebug('ManualStart', 'Manueller Start angefordert. Setze Zonen zurück...', 0);
        $this->AddLogEvent("System: Manueller Start", "Bewässerung wird sofort gestartet...", '#2196F3');
        $this->resetAllZones(true); // Nutze true, damit es nicht als Abbruch geloggt wird, aber setze Status später richtig
        $this->SetBuffer('CalculatePlanPending', 'true');
        $this->ProcessLogic();
    }

    private function isZoneHardwareOk(array $zone, array $sprinklers): bool {
        $zoneName = isset($zone['GroupName']) && !empty($zone['GroupName']) ? $zone['GroupName'] : 'Zone '. $zone['SensorID'];
        foreach ($sprinklers as $s) {
            if ($s['ZoneName'] === $zoneName) {
                $res = $this->ResolveSprinklerObject((int)@$s['ValveID']);
                if ($res['HardwareStatusID'] > 0) {
                    $hwStatus = GetValue($res['HardwareStatusID']);
                    $hwStr = strtoupper((string)$hwStatus);
                    if (in_array($hwStr, ['ERROR', 'WARNING', 'OFFLINE', 'DEFECT', 'FAULT'])) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    private function GetTimeAsString(string $propertyName): string {
        $val = $this->ReadPropertyString($propertyName);
        if (empty($val)) return "00:00";
        $data = json_decode($val, true);
        if (is_array($data) && isset($data['hour']) && isset($data['minute'])) {
            return sprintf("%02d:%02d", $data['hour'], $data['minute']);
        }
        // Fallback falls es kein JSON ist (alte Version)
        return substr($val, 0, 5);
    }

    private function IsTimeForbidden(int $timestamp): bool {
        $fStart = $this->GetTimeAsString('ForbiddenStartTime');
        $fEnd = $this->GetTimeAsString('ForbiddenEndTime');
        if ($fStart === $fEnd) return false;
        
        $timeStr = date('H:i', $timestamp);
        if ($fEnd < $fStart) {
            if ($timeStr >= $fStart || $timeStr <= $fEnd) return true;
        } else {
            if ($timeStr >= $fStart && $timeStr <= $fEnd) return true;
        }
        return false;
    }

    private function GetNextScheduleTime(): int {
        $schedule = $this->ReadPropertyInteger('IrrigationSchedule');
        $now = time();
        $today = strtotime('today');
        
        $times = [];
        if ($schedule === 1) {
            $times = [6];
        } else if ($schedule === 2) {
            $times = [6, 18];
        } else if ($schedule === 4) {
            $times = [0, 6, 12, 18];
        } else if ($schedule === 6) {
            $times = [0, 4, 8, 12, 16, 20];
        } else if ($schedule === 8) {
            $times = [0, 3, 6, 9, 12, 15, 18, 21];
        } else {
            $times = [6, 18];
        }
        
        for ($dayOffset = 0; $dayOffset < 7; $dayOffset++) {
            $baseDay = $today + ($dayOffset * 86400);
            foreach ($times as $hour) {
                $t = $baseDay + ($hour * 3600);
                if ($t > $now && !$this->IsTimeForbidden($t)) {
                    return $t;
                }
            }
        }
        
        // Fallback
        return $today + 86400 + ($times[0] * 3600);
    }
}
