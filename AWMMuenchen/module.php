<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Trait_SmartLog.php';
require_once __DIR__ . '/../libs/Trait_DeviceAvailability.php';

class AWMMuenchen extends IPSModuleStrict
{
    use SmartLog_Trait;
    use DeviceAvailability_Trait;
    public function Create(): void{
        parent::Create();

        $this->DA_RegisterAvailability(900);

        // Properties
        $this->RegisterPropertyString('CalendarUrl', '');
        $this->RegisterPropertyInteger('UpdateInterval', 4);

        // Timer
        $this->RegisterTimer('UpdateTimer', 0, 'AWM_UpdateCalendar($_IPS[\'TARGET\']);');

        // Heutige Abholungen
        $this->RegisterVariableBoolean('RestmuellHeute', 'Restmülltonne (Heute)', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Trash'
        ], 10);
        $this->RegisterVariableBoolean('PapierHeute', 'Papiertonne (Heute)', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Trash'
        ], 20);
        $this->RegisterVariableBoolean('BioHeute', 'Biotonne (Heute)', [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON' => 'Trash'
        ], 30);

        // Heute: Einzelne String-Variable als Zusammenfassung
        $this->RegisterVariableString('Heute', 'Heute', '', 4);
        $this->RegisterVariableString('VestaboardMessage', 'Vestaboard Nachricht', '', 5);

        // Variablen für Wochentage (Wochenübersicht)
        $this->RegisterVariableString('Montag', 'Montag', '', 11);
        $this->RegisterVariableString('Dienstag', 'Dienstag', '', 12);
        $this->RegisterVariableString('Mittwoch', 'Mittwoch', '', 13);
        $this->RegisterVariableString('Donnerstag', 'Donnerstag', '', 14);
        $this->RegisterVariableString('Freitag', 'Freitag', '', 15);
        $this->RegisterVariableString('Samstag', 'Samstag', '', 16);
    }

    public function ApplyChanges(): void{
        parent::ApplyChanges();

        $url = $this->ReadPropertyString('CalendarUrl');
        if (empty($url)) {
            $this->SetStatus(104);
            return;
        }
        $this->SetStatus(102);

        $this->DA_ApplyPresentation();

        // Clear custom presentations on string variables so IPS_SetIcon works
        // Wait, IPS_SetVariableCustomPresentation([]) didn't clear the Anzeigetyp.
        // We will just manage the CustomPresentation dynamically in UpdateCalendar!
        // No need to clear them here anymore.

        $wasteOptions = json_encode([
            ['Value' => false, 'Caption' => 'Nein', 'IconValue' => 'Trash', 'IconActive' => true,
             'ColorActive' => false, 'ColorDisplay' => -1, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => -1],
            ['Value' => true, 'Caption' => 'Heute!', 'IconValue' => 'Trash', 'IconActive' => true,
             'ColorActive' => true, 'ColorDisplay' => 0xFFCC00, 'ContentColorActive' => false,
             'ContentColorDisplay' => -1, 'ContentColorValue' => -1, 'ColorValue' => 0xFFCC00]
        ]);
        foreach (['RestmuellHeute', 'PapierHeute', 'BioHeute'] as $ident) {
            IPS_SetVariableCustomPresentation($this->GetIDForIdent($ident), [
                'PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
                'ICON' => 'Trash',
                'COLOR' => -1,
                'CONTENT_COLOR' => -1,
                'DISPLAY_TYPE' => 0,
                'PREVIEW_STYLE' => 1,
                'SHOW_PREVIEW' => true,
                'OPTIONS' => $wasteOptions
            ]);
        }

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
            $this->DA_SetAvailable(false, 'Keine ICS URL konfiguriert.');
            $this->SLogError("Keine ICS URL konfiguriert.");
            return;
        }

        // Ersetze hardcodiertes Jahr durch aktuelles Jahr
        $currentYear = date('Y');
        $url = preg_replace('/tx_awmabfuhrkalender_abfuhrkalender(%5B|\[)year(%5D|\])=\d{4}/', 'tx_awmabfuhrkalender_abfuhrkalender$1year$2='. $currentYear, $url);

        $events = $this->parseICS($url);
        if (empty($events)) {
            $msg = "Fehler beim Abrufen des Abfuhrkalenders für das Jahr $currentYear. Möglicherweise ist der generierte Link (cHash) abgelaufen. Bitte generiere auf der AWM Webseite einen neuen Link für das aktuelle Jahr und trage ihn in die Instanz ein.";
            $this->LogMessage($msg, KL_ERROR);
            $this->SLogError($msg);
            $this->DA_SetAvailable(false, 'HTTP Fehler');
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

        // Formatiere Heute-Liste
        $heuteStr = empty($heuteListe) ? "Keine Leerung": implode(", ", $heuteListe);
        $this->SetValue('Heute', $heuteStr);
        
        $heuteIcon = 'ok';
        if (in_array('Restmüll', $heuteListe)) $heuteIcon = 'trash';
        elseif (in_array('Papier', $heuteListe)) $heuteIcon = 'notebook';
        elseif (in_array('Bio', $heuteListe)) $heuteIcon = 'leaf';
        IPS_SetVariableCustomPresentation($this->GetIDForIdent('Heute'), ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => $heuteIcon, 'DISPLAY_TYPE' => 1]);
        
        $heuteVesta = empty($heuteListe) ? '' : implode(', ', $heuteListe);
        $this->SetValue('VestaboardMessage', $heuteVesta);

        // Wochen-Variablen setzen
        foreach ($weekdays as $dayName => $ts) {
            $varName = str_replace('ü', 'ue', $dayName); // Nur zur Sicherheit
            
            $dayList = $weekSummary[$dayName] ?? [];
            $val = empty($dayList) ? "Keine Leerung": implode(", ", $dayList);
            $this->SetValue($varName, $val);
            
            $dayIcon = 'ok';
            if (in_array('Restmüll', $dayList)) $dayIcon = 'trash';
            elseif (in_array('Papier', $dayList)) $dayIcon = 'notebook';
            elseif (in_array('Bio', $dayList)) $dayIcon = 'leaf';
            IPS_SetVariableCustomPresentation($this->GetIDForIdent($varName), ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => $dayIcon, 'DISPLAY_TYPE' => 1]);
        }
        
        $this->SendDebug("AWM", "Kalender erfolgreich aktualisiert.", 0);
        $this->DA_SetAvailable(true);
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
        if (!$data) {
            $this->SLog('ERROR', 'AWM HTTP-Anfrage fehlgeschlagen', error_get_last()['message'] ?? 'Unbekannt');
            return [];
        }

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
    "status": [
        { "code": 102, "icon": "active", "caption": "Aktiv" },
        { "code": 104, "icon": "inactive", "caption": "Inaktiv (Konfiguration unvollständig)" },
        { "code": 200, "icon": "error", "caption": "Fehler" },
        { "code": 201, "icon": "error", "caption": "Verbindungsfehler" }
    ],
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


