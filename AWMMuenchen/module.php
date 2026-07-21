<?php

declare(strict_types=1);

class AWMMuenchen extends IPSModuleStrict
{
    public function Create(): void{
        parent::Create();

        // Properties
        $this->RegisterPropertyString('CalendarUrl', '');
        $this->RegisterPropertyInteger('UpdateInterval', 4);

        // Timer
        $this->RegisterTimer('UpdateTimer', 0, 'AWM_UpdateCalendar($_IPS[\'TARGET\']);');

        // Heutige Abholungen
        $this->RegisterVariableBoolean('RestmuellHeute', 'Restmülltonne (Heute)', '', 10);
        IPS_SetIcon($this->GetIDForIdent('RestmuellHeute'), 'Clock');
        $this->RegisterVariableBoolean('PapierHeute', 'Papiertonne (Heute)', '', 20);
        IPS_SetIcon($this->GetIDForIdent('PapierHeute'), 'Clock');
        $this->RegisterVariableBoolean('BioHeute', 'Biotonne (Heute)', '', 30);
        IPS_SetIcon($this->GetIDForIdent('BioHeute'), 'Clock');

        // Heute: Einzelne String-Variable als Zusammenfassung
        $this->RegisterVariableString('Heute', 'Heute', '', 4);
        IPS_SetIcon($this->GetIDForIdent('Heute'), 'Clock');
        $this->RegisterVariableString('VestaboardMessage', 'Vestaboard Nachricht', '', 5);
        IPS_SetIcon($this->GetIDForIdent('VestaboardMessage'), 'Clock');

        // Variablen für Wochentage (Wochenübersicht)
        $this->RegisterVariableString('Montag', 'Montag', '', 11);
        IPS_SetIcon($this->GetIDForIdent('Montag'), 'Clock');
        $this->RegisterVariableString('Dienstag', 'Dienstag', '', 12);
        IPS_SetIcon($this->GetIDForIdent('Dienstag'), 'Clock');
        $this->RegisterVariableString('Mittwoch', 'Mittwoch', '', 13);
        IPS_SetIcon($this->GetIDForIdent('Mittwoch'), 'Clock');
        $this->RegisterVariableString('Donnerstag', 'Donnerstag', '', 14);
        IPS_SetIcon($this->GetIDForIdent('Donnerstag'), 'Clock');
        $this->RegisterVariableString('Freitag', 'Freitag', '', 15);
        IPS_SetIcon($this->GetIDForIdent('Freitag'), 'Clock');
        $this->RegisterVariableString('Samstag', 'Samstag', '', 16);
        IPS_SetIcon($this->GetIDForIdent('Samstag'), 'Clock');
    }

    public function ApplyChanges(): void{
        parent::ApplyChanges();

        
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('RestmuellHeute'), [
            'PRESENTATION'=> VARIABLE_PRESENTATION_SWITCH
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('PapierHeute'), [
            'PRESENTATION'=> VARIABLE_PRESENTATION_SWITCH
        ]);
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('BioHeute'), [
            'PRESENTATION'=> VARIABLE_PRESENTATION_SWITCH
        ]);

        $interval = $this->ReadPropertyInteger('UpdateInterval');
        if ($interval > 0) {
            $this->SetTimerInterval('UpdateTimer', $interval * 3600 * 1000);
        } else {
            $this->SetTimerInterval('UpdateTimer', 0);
        }

        // Einmaliges Ausführen bei Übernehmen
        if (!empty($this->ReadPropertyString('CalendarUrl'))) {
            $this->UpdateCalendar();
        }
    }

    public function UpdateCalendar(): void
    {
        $url = $this->ReadPropertyString('CalendarUrl');
        if (empty($url)) {
            echo "Keine ICS URL konfiguriert.";
            return;
        }

        // Ersetze hardcodiertes Jahr durch aktuelles Jahr
        $currentYear = date('Y');
        $url = preg_replace('/tx_awmabfuhrkalender_abfuhrkalender(%5B|\[)year(%5D|\])=\d{4}/', 'tx_awmabfuhrkalender_abfuhrkalender$1year$2='. $currentYear, $url);

        $events = $this->parseICS($url);
        if (empty($events)) {
            $msg = "Fehler beim Abrufen des Abfuhrkalenders für das Jahr $currentYear. Möglicherweise ist der generierte Link (cHash) abgelaufen. Bitte generiere auf der AWM Webseite einen neuen Link für das aktuelle Jahr und trage ihn in die Instanz ein.";
            $this->LogMessage($msg, KL_ERROR);
            echo $msg;
            return;
        }

        // Heute 00:00:00 Uhr
        $todayTs = strtotime('today');
        $restToday = false;
        $papierToday = false;
        $bioToday = false;

        // Montag dieser Woche finden
        $mondayTs = strtotime('monday this week', $todayTs);
        $weekdays = [
            'Montag'=> $mondayTs,
            'Dienstag'=> strtotime('+1 day', $mondayTs),
            'Mittwoch'=> strtotime('+2 days', $mondayTs),
            'Donnerstag'=> strtotime('+3 days', $mondayTs),
            'Freitag'=> strtotime('+4 days', $mondayTs),
            'Samstag'=> strtotime('+5 days', $mondayTs)
        ];

        $todaySummary = [
            'RestmuellHeute'=> false,
            'PapierHeute'=> false,
            'BioHeute'=> false
        ];
        $heuteListe = [];
        $weekSummary = [];

        foreach ($events as $e) {
            $summary = strtolower($e['summary']);
            $type = '';
            
            if (strpos($summary, 'rest') !== false) $type = 'Restmüll';
            if (strpos($summary, 'papier') !== false) $type = 'Papier';
            if (strpos($summary, 'bio') !== false) $type = 'Bio';
            
            if (!$type) continue;
            
            // Check Today
            if ($this->isEventActiveOnDay($e, $todayTs)) {
                if ($type == 'Restmüll') $todaySummary['RestmuellHeute'] = true;
                if ($type == 'Papier') $todaySummary['PapierHeute'] = true;
                if ($type == 'Bio') $todaySummary['BioHeute'] = true;
                $heuteListe[] = $type;
            }
            
            // Check Weekdays
            foreach ($weekdays as $dayName => $ts) {
                if ($this->isEventActiveOnDay($e, $ts)) {
                    if (!isset($weekSummary[$dayName])) $weekSummary[$dayName] = [];
                    if (!in_array($type, $weekSummary[$dayName])) {
                        $weekSummary[$dayName][] = $type;
                    }
                }
            }
        }

        $this->SetValue('RestmuellHeute', $todaySummary['RestmuellHeute']);
        $this->SetValue('PapierHeute', $todaySummary['PapierHeute']);
        $this->SetValue('BioHeute', $todaySummary['BioHeute']);

        $emojiMap = [
            'Restmüll'=> '🗑 Restmüll',
            'Papier'  => '📦 Papier',
            'Bio'     => '🍂 Bio'
        ];

        // Formatiere Heute-Liste
        $heuteListeFormatted = array_map(function($t) use ($emojiMap) { return isset($emojiMap[$t]) ? $emojiMap[$t] : $t; }, $heuteListe);
        $heuteStr = empty($heuteListeFormatted) ? "✅ Keine Leerung": implode(", ", $heuteListeFormatted);
        $this->SetValue('Heute', $heuteStr);
        
        $heuteVesta = empty($heuteListe) ? '' : implode(', ', $heuteListe);
        $this->SetValue('VestaboardMessage', $heuteVesta);

        // Wochen-Variablen setzen
        foreach ($weekdays as $dayName => $ts) {
            $varName = str_replace('ü', 'ue', $dayName); // Nur zur Sicherheit
            $weekSummaryFormatted = [];
            if (!empty($weekSummary[$dayName])) {
                $weekSummaryFormatted = array_map(function($t) use ($emojiMap) { return isset($emojiMap[$t]) ? $emojiMap[$t] : $t; }, $weekSummary[$dayName]);
            }
            $val = empty($weekSummaryFormatted) ? "✅ Keine Leerung": implode(", ", $weekSummaryFormatted);
            $this->SetValue($varName, $val);
        }
        
        $this->SendDebug("AWM", "Kalender erfolgreich aktualisiert.", 0);
    }

    protected function parseICS(string $url): array
    {
        // Sys_GetURLContent is IP-Symcon's robust internal method.
        if (function_exists('Sys_GetURLContent')) {
            $data = @Sys_GetURLContent($url);
        } else {
            // Fallback for tests
            $context = stream_context_create([
                'http'=> [
                    'timeout'=> 15,
                    'header'=> "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n"
                ],
                'ssl'=> [
                    'verify_peer'=> false,
                    'verify_peer_name'=> false
                ]
            ]);
            $data = @file_get_contents($url, false, $context);
        }
        if (!$data) return [];

        $lines = explode("\n", str_replace("\r", "", $data));
        $events = [];
        $currentEvent = null;

        foreach ($lines as $line) {
            if (strpos($line, 'BEGIN:VEVENT') === 0) {
                $currentEvent = ['exdates'=> [], 'rrule'=> [], 'dtend'=> 0];
            } elseif (strpos($line, 'END:VEVENT') === 0) {
                if ($currentEvent && isset($currentEvent['dtstart'])) {
                    $events[] = $currentEvent;
                }
                $currentEvent = null;
            } elseif ($currentEvent !== null) {
                if (strpos($line, 'SUMMARY:') === 0) {
                    $currentEvent['summary'] = substr($line, 8);
                } elseif (strpos($line, 'DTSTART') === 0) {
                    if (preg_match('/:(\d{8})/', $line, $m)) {
                        $currentEvent['dtstart'] = strtotime($m[1] . '00:00:00');
                    }
                } elseif (strpos($line, 'DTEND') === 0) {
                    if (preg_match('/:(\d{8})/', $line, $m)) {
                        $currentEvent['dtend'] = strtotime($m[1] . '00:00:00');
                    }
                } elseif (strpos($line, 'EXDATE') === 0) {
                    if (preg_match('/:(\d{8})/', $line, $m)) {
                        $currentEvent['exdates'][] = strtotime($m[1] . '00:00:00');
                    }
                } elseif (strpos($line, 'RRULE:') === 0) {
                    $parts = explode(';', substr($line, 6));
                    foreach ($parts as $p) {
                        $kv = explode('=', $p);
                        if (count($kv) == 2) {
                            $k = $kv[0];
                            $v = $kv[1];
                            if ($k == 'UNTIL') {
                                $v = strtotime(substr($v, 0, 8) . '00:00:00');
                            }
                            $currentEvent['rrule'][$k] = $v;
                        }
                    }
                }
            }
        }
        return $events;
    }

    private function isEventActiveOnDay(array $event, int $targetTs): bool
    {
        // 1. Ist das Datum eine bekannte Ausnahme (Urlaub, Feiertagsverschiebung)?
        if (isset($event['exdates']) && is_array($event['exdates'])) {
            foreach ($event['exdates'] as $exTs) {
                if ($exTs == $targetTs) {
                    return false;
                }
            }
        }

        // 2. Ohne RRULE (Einzeltermin, oft Feiertags-Ersatz)
        if (empty($event['rrule'])) {
            // AWM setzt oft DTEND auf denselben Tag wie DTSTART. Wir checken einfach auf Gleichheit.
            return ($targetTs == $event['dtstart']);
        }

        // 3. Mit RRULE
        $rrule = $event['rrule'];
        
        // Start und Ende prüfen
        if (isset($rrule['UNTIL']) && $targetTs > $rrule['UNTIL']) return false;
        if ($targetTs < $event['dtstart']) return false;

        // Wochentag prüfen
        $targetDayMap = ['0'=> 'SU', '1'=> 'MO', '2'=> 'TU', '3'=> 'WE', '4'=> 'TH', '5'=> 'FR', '6'=> 'SA'];
        $targetWkday = $targetDayMap[date('w', $targetTs)];
        if (isset($rrule['BYDAY']) && strpos($rrule['BYDAY'], $targetWkday) === false) return false;

        // Intervall prüfen (z.B. alle 2 Wochen)
        $interval = isset($rrule['INTERVAL']) ? (int)$rrule['INTERVAL'] : 1;
        $diffDays = round(($targetTs - $event['dtstart']) / 86400);
        
        // Vergangene volle Wochen seit dem Startdatum berechnen
        $diffWeeks = floor($diffDays / 7);

        if ($diffWeeks % $interval == 0) {
            return true;
        }

        return false;
    }

    private function SLog(string $level, string $message, string $details = ''): void
    {
        $source = static::class;
        $slogInstances = @IPS_GetInstanceListByModuleID('{A1B2C3D4-E5F6-7890-ABCD-EF1234567890}');
        if (is_array($slogInstances) && count($slogInstances) > 0) {
            @SLOG_Log($slogInstances[0], $level, $source, $message, $details);
        } else {
            IPS_LogMessage('SmartVillaKunterbunt', $source . ': ' . $message);
        }
    }

    protected function LogMessage(string $Message, int $Type): bool
    {
        $this->SLog('INFO', $Message);
        IPS_LogMessage('SmartVillaKunterbunt', 'AWMMuenchen: '. $Message);
        return true;
    }

    public function GetConfigurationForm(): string
    {
        return <<<'EOT'
{
    "elements": [
        {
            "type": "Label",
            "caption": "Hier trägst du die Download-URL von deinem AWM München Abfuhrkalender ein. Wähle außerdem aus, alle wie viel Stunden wir die Daten frisch für dich holen sollen."
        },
        {
            "type": "RowLayout",
            "items": [
                {
                    "type": "ValidationTextBox",
                    "name": "CalendarUrl",
                    "caption": "AWM ICS Download URL"
                },
                {
                    "type": "NumberSpinner",
                    "name": "UpdateInterval",
                    "caption": "Update-Intervall (Stunden)",
                    "minimum": 1,
                    "maximum": 24
                }
            ]
        }
    ],
    "actions": [
        {
            "type": "Label",
            "caption": "Wenn du die URL gerade neu eingetragen hast, klick am besten gleich mal hier unten, um die aktuellen Termine zu laden:"
        },
        {
            "type": "Button",
            "label": "Kalender jetzt abrufen",
            "onClick": "AWM_UpdateCalendar($id);"
        }
    ]
}
EOT;
    }
}


