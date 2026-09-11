<?php
declare(strict_types=1);

/**
 * Proactive Vacation Brain orchestration.
 *
 * The engine evaluates already-saved trip intelligence and a deliberately narrow
 * projection of itinerary/reservation timing data. There is no provider refresh
 * in this service. It may create Next Moves and start specialist research, but an
 * agent proposal still flows through TripAgentExecutionService's explicit approval
 * boundary before any trip change can be applied.
 */
final class ProactiveTravelService
{
    private const SEVERITY_SCORE = [
        'info' => 0,
        'low' => 1,
        'medium' => 2,
        'high' => 3,
        'critical' => 4,
    ];

    private const AGENTS = ['overview','weather','flights','events','local','itinerary','budget'];
    private const TABS = ['overview','weather','flights','events','local','itinerary','budget'];

    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('proactive_travel_preferences')
            && db_table_exists('trip_proactive_issues')
            && db_table_exists('trip_proactive_briefings');
    }

    public function preferences(int $userId): array
    {
        $defaults = [
            'proactive_enabled' => 1,
            'urgent_alerts' => 1,
            'opportunity_alerts' => 1,
            'auto_research' => 1,
            'morning_briefing' => 1,
            'minimum_severity' => 'medium',
            'quiet_start' => null,
            'quiet_end' => null,
        ];
        if (!$this->ready()) return $defaults;

        $stmt = $this->pdo->prepare(
            'SELECT proactive_enabled,urgent_alerts,opportunity_alerts,auto_research,morning_briefing,minimum_severity,quiet_start,quiet_end
             FROM proactive_travel_preferences WHERE user_id=? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ? array_merge($defaults, $row) : $defaults;
    }

    public function savePreferences(int $userId, array $input): array
    {
        $this->requireReady();
        $minimum = strtolower(trim((string)($input['minimum_severity'] ?? 'medium')));
        if (!in_array($minimum, ['low','medium','high'], true)) $minimum = 'medium';

        $values = [
            'proactive_enabled' => !empty($input['proactive_enabled']) ? 1 : 0,
            'urgent_alerts' => !empty($input['urgent_alerts']) ? 1 : 0,
            'opportunity_alerts' => !empty($input['opportunity_alerts']) ? 1 : 0,
            'auto_research' => !empty($input['auto_research']) ? 1 : 0,
            'morning_briefing' => !empty($input['morning_briefing']) ? 1 : 0,
            'minimum_severity' => $minimum,
            'quiet_start' => $this->timeOrNull((string)($input['quiet_start'] ?? '')),
            'quiet_end' => $this->timeOrNull((string)($input['quiet_end'] ?? '')),
        ];

        $sql = 'INSERT INTO proactive_travel_preferences
            (user_id,proactive_enabled,urgent_alerts,opportunity_alerts,auto_research,morning_briefing,minimum_severity,quiet_start,quiet_end)
            VALUES (?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              proactive_enabled=VALUES(proactive_enabled),
              urgent_alerts=VALUES(urgent_alerts),
              opportunity_alerts=VALUES(opportunity_alerts),
              auto_research=VALUES(auto_research),
              morning_briefing=VALUES(morning_briefing),
              minimum_severity=VALUES(minimum_severity),
              quiet_start=VALUES(quiet_start),
              quiet_end=VALUES(quiet_end),
              updated_at=NOW()';
        $this->pdo->prepare($sql)->execute([
            $userId,
            $values['proactive_enabled'],
            $values['urgent_alerts'],
            $values['opportunity_alerts'],
            $values['auto_research'],
            $values['morning_briefing'],
            $values['minimum_severity'],
            $values['quiet_start'],
            $values['quiet_end'],
        ]);
        return $values;
    }

    public function runUpcoming(int $limit = 25): array
    {
        if (!$this->ready()) {
            return [
                'checked' => 0,
                'issues' => 0,
                'notifications' => 0,
                'research_started' => 0,
                'briefings' => 0,
                'errors' => 0,
                'upgrade_required' => true,
            ];
        }

        $limit = max(1, min(100, $limit));
        $sql = "SELECT id,user_id
                FROM dream_trips
                WHERE status NOT IN ('abandoned','completed')
                  AND (end_date IS NULL OR end_date>=DATE_SUB(CURDATE(),INTERVAL 1 DAY))
                  AND (start_date IS NULL OR start_date<=DATE_ADD(CURDATE(),INTERVAL 45 DAY))
                ORDER BY COALESCE(start_date,'9999-12-31'),updated_at DESC,id DESC
                LIMIT {$limit}";
        $rows = $this->pdo->query($sql)->fetchAll() ?: [];
        $result = ['checked'=>0,'issues'=>0,'notifications'=>0,'research_started'=>0,'briefings'=>0,'errors'=>0];

        foreach ($rows as $row) {
            $result['checked']++;
            try {
                $tripResult = $this->assessTrip((int)$row['user_id'], (int)$row['id'], true, 'worker');
                $result['issues'] += (int)($tripResult['open_count'] ?? 0);
                $result['notifications'] += (int)($tripResult['notifications'] ?? 0);
                $result['research_started'] += (int)($tripResult['research_started'] ?? 0);
                if (!empty($tripResult['briefing_created'])) $result['briefings']++;
            } catch (Throwable $e) {
                $result['errors']++;
                error_log('Proactive Vacation Brain assessment failed for trip '.(int)$row['id'].': '.$this->clip($e->getMessage(), 500));
            }
        }
        return $result;
    }

    public function assessTrip(
        int $userId,
        int $tripId,
        bool $persist = true,
        string $trigger = 'view'
    ): array {
        if ($userId < 1 || $tripId < 1) throw new InvalidArgumentException('Trip is required.');
        $prefs = $this->preferences($userId);
        if (!$this->ready()) {
            return [
                'ready'=>false,
                'enabled'=>(bool)$prefs['proactive_enabled'],
                'issues'=>[],
                'open_count'=>0,
                'risk_score'=>0,
                'risk_label'=>'Upgrade required',
                'notifications'=>0,
                'research_started'=>0,
                'briefing_created'=>false,
                'preferences'=>$prefs,
            ];
        }

        $dashboard = (new TripIntelligenceService($this->pdo))->dashboard($userId, $tripId);
        $trip = is_array($dashboard['trip'] ?? null) ? $dashboard['trip'] : [];
        if ((int)($trip['id'] ?? 0) !== $tripId) throw new OutOfBoundsException('Trip not found.');
        $trip['user_id'] = $userId;

        $health = (new TripLiveIntelligenceService($this->pdo))->health($dashboard);
        $bookings = $this->safeBookings($userId, $tripId);
        $items = $this->safeItems($tripId);
        $traits = $this->effectiveTraits($userId);
        $detected = [];

        if (!empty($prefs['proactive_enabled'])) {
            $detected = array_merge($detected, $this->readinessIssues($trip, $bookings));
            $detected = array_merge($detected, $this->flightIssues($trip, $dashboard, $items, $traits, $health));
            $detected = array_merge($detected, $this->weatherIssues($dashboard, $items, $health));
            $detected = array_merge($detected, $this->itineraryIssues($bookings, $items));
            $detected = array_merge($detected, $this->budgetIssues($dashboard));
            $detected = array_merge($detected, $this->freshnessIssues($trip, $health));
            if (!empty($prefs['opportunity_alerts'])) {
                $detected = array_merge($detected, $this->opportunityIssues($userId, $tripId, $dashboard, $health));
            }
        }

        $byKey = [];
        foreach ($detected as $row) {
            $issue = $this->normalizeIssue($row);
            if ($issue !== null) $byKey[$issue['issue_key']] = $issue;
        }
        $detected = array_values($byKey);

        $notifications = 0;
        $researchStarted = 0;
        $briefingCreated = false;

        if ($persist) {
            $this->persistIssues($userId, $tripId, $detected);
            $this->syncNextMoves($userId, $tripId, $detected);
            $issues = $this->issuesForTrip($userId, $tripId, 50, true);
            if (!empty($prefs['proactive_enabled'])) {
                $notifications = $this->notifyIssues($userId, $tripId, $issues, $prefs);
                $researchStarted = $this->startResearch($userId, $tripId, $issues, $prefs);
                $briefingCreated = $this->maybeBrief($userId, $tripId, $trip, $issues, $health, $prefs, $trigger);
            }
        } else {
            $issues = $detected;
        }

        [$riskScore, $riskLabel] = $this->riskScore($issues);
        return [
            'ready'=>true,
            'enabled'=>(bool)$prefs['proactive_enabled'],
            'generated_at'=>date(DATE_ATOM),
            'trip'=>$this->publicTrip($trip),
            'issues'=>$issues,
            'open_count'=>count($issues),
            'risk_score'=>$riskScore,
            'risk_label'=>$riskLabel,
            'health'=>$health,
            'notifications'=>$notifications,
            'research_started'=>$researchStarted,
            'briefing_created'=>$briefingCreated,
            'preferences'=>$prefs,
        ];
    }

    public function issuesForTrip(int $userId, int $tripId, int $limit = 50, bool $openOnly = false): array
    {
        if (!$this->ready()) return [];
        $limit = max(1, min(100, $limit));
        $sql = 'SELECT * FROM trip_proactive_issues WHERE user_id=? AND dream_trip_id=?'
            .($openOnly ? " AND status='open'" : '')
            ." ORDER BY FIELD(severity,'critical','high','medium','low','info'),
                       FIELD(issue_type,'risk','conflict','readiness','opportunity'),
                       last_seen_at DESC,id DESC LIMIT {$limit}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, $tripId]);
        $rows = $stmt->fetchAll() ?: [];
        return array_map(fn(array $row): array => $this->publicIssue($row), $rows);
    }

    public function dashboardSummary(int $userId, int $limit = 8): array
    {
        if (!$this->ready()) return ['ready'=>false,'issues'=>[],'briefings'=>[],'critical'=>0,'high'=>0];
        $limit = max(1, min(20, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT i.*,dt.name trip_name,dt.start_date
             FROM trip_proactive_issues i
             JOIN dream_trips dt ON dt.id=i.dream_trip_id
             WHERE i.user_id=? AND i.status='open' AND dt.status NOT IN ('abandoned','completed')
             ORDER BY FIELD(i.severity,'critical','high','medium','low','info'),
                      COALESCE(dt.start_date,'9999-12-31'),i.last_seen_at DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$userId]);
        $issues = [];
        $critical = 0;
        $high = 0;
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $issue = $this->publicIssue($row);
            $issue['trip_name'] = (string)$row['trip_name'];
            $issue['start_date'] = $row['start_date'] ?? null;
            $issue['url'] = app_url('proactive-trip.php?id='.(int)$row['dream_trip_id'].'#issue-'.(int)$row['id']);
            $issues[] = $issue;
            if ($issue['severity'] === 'critical') $critical++;
            if ($issue['severity'] === 'high') $high++;
        }

        $brief = $this->pdo->prepare(
            'SELECT b.id,b.dream_trip_id,b.briefing_date,b.briefing_kind,b.headline,b.body,b.created_at,dt.name trip_name
             FROM trip_proactive_briefings b
             JOIN dream_trips dt ON dt.id=b.dream_trip_id
             WHERE b.user_id=?
             ORDER BY b.briefing_date DESC,b.id DESC LIMIT 3'
        );
        $brief->execute([$userId]);
        return [
            'ready'=>true,
            'issues'=>$issues,
            'briefings'=>$brief->fetchAll() ?: [],
            'critical'=>$critical,
            'high'=>$high,
        ];
    }

    public function dismissIssue(int $userId, int $tripId, int $issueId): void
    {
        $this->requireReady();
        $lookup = $this->pdo->prepare(
            "SELECT issue_key,fingerprint FROM trip_proactive_issues
             WHERE id=? AND user_id=? AND dream_trip_id=? AND status='open' LIMIT 1"
        );
        $lookup->execute([$issueId, $userId, $tripId]);
        $row = $lookup->fetch();
        if (!$row) throw new OutOfBoundsException('Proactive issue not found.');

        $this->pdo->prepare(
            "UPDATE trip_proactive_issues
             SET status='dismissed',resolved_at=NOW(),updated_at=NOW()
             WHERE id=? AND user_id=? AND dream_trip_id=? AND status='open'"
        )->execute([$issueId, $userId, $tripId]);
        $this->supersedeMove($userId, $tripId, (string)$row['issue_key'], (string)$row['fingerprint']);
    }

    public function recentBriefings(int $userId, int $tripId, int $limit = 7): array
    {
        if (!$this->ready()) return [];
        $limit = max(1, min(30, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT id,briefing_date,briefing_kind,headline,body,payload_json,created_at,updated_at
             FROM trip_proactive_briefings
             WHERE user_id=? AND dream_trip_id=?
             ORDER BY briefing_date DESC,id DESC LIMIT {$limit}"
        );
        $stmt->execute([$userId, $tripId]);
        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as &$row) {
            $row['payload'] = json_decode((string)($row['payload_json'] ?? ''), true) ?: [];
            unset($row['payload_json']);
        }
        unset($row);
        return $rows;
    }

    public function agentContext(int $userId, int $tripId, int $limit = 6): string
    {
        if (!$this->ready()) return '';
        $issues = $this->issuesForTrip($userId, $tripId, $limit, true);
        if (!$issues) return '';

        $lines = [];
        foreach ($issues as $issue) {
            $lines[] = '- '.strtoupper((string)$issue['severity']).' '.(string)$issue['title'].': '
                .(string)$issue['body'].' [source '.(string)$issue['source_state']
                .', confidence '.(int)$issue['confidence'].'%]';
        }
        return "PROACTIVE TRIP STATE (derived from safe saved trip data; no provider refresh):\n"
            .implode("\n", $lines)
            ."\nTreat these as risk/opportunity signals, not certainty. Research is allowed; changing itinerary, reservations, purchases, or bookings still requires the user's approval.";
    }

    private function readinessIssues(array $trip, array $bookings): array
    {
        $out = [];
        $days = $this->daysUntil($trip['start_date'] ?? null);
        $readiness = (int)($trip['booking_readiness'] ?? 0);

        if ($days !== null && $days >= 0) {
            if ($days <= 2 && $readiness < 90) {
                $out[] = $this->issue(
                    'readiness:departure','readiness','critical',
                    'Trip readiness is too low for departure',
                    'Your trip starts in '.$days.' day'.($days === 1 ? '' : 's').' but booking readiness is only '.$readiness.'%. Clear the highest-priority missing requirement before travel day.',
                    'manual',92,'overview','overview',
                    [['label'=>'Review booking readiness','agent'=>'overview','tab'=>'overview','kind'=>'readiness','priority'=>98]]
                );
            } elseif ($days <= 7 && $readiness < 80) {
                $out[] = $this->issue(
                    'readiness:week','readiness','high',
                    'Trip readiness needs attention',
                    'Departure is '.$days.' days away and booking readiness is '.$readiness.'%. Finish the remaining essentials before they become travel-day problems.',
                    'manual',90,'overview','overview',
                    [['label'=>'Research the readiness gaps','agent'=>'overview','tab'=>'overview','kind'=>'readiness','priority'=>90]]
                );
            } elseif ($days <= 14 && $readiness < 60) {
                $out[] = $this->issue(
                    'readiness:two-weeks','readiness','medium',
                    'Booking readiness is lagging',
                    'The trip is '.$days.' days away and readiness is '.$readiness.'%. This is still fixable without panic, which is the preferred genre.',
                    'manual',88,'overview','overview',
                    [['label'=>'Build a readiness plan','agent'=>'overview','tab'=>'overview','kind'=>'readiness','priority'=>76]]
                );
            }
        }

        $multiDay = !empty($trip['start_date']) && !empty($trip['end_date'])
            && strtotime((string)$trip['end_date']) > strtotime((string)$trip['start_date']);
        $hasLodging = false;
        foreach ($bookings as $booking) {
            if (($booking['booking_type'] ?? '') === 'lodging'
                && in_array((string)($booking['status'] ?? ''), ['booked','confirmed','changed'], true)) {
                $hasLodging = true;
                break;
            }
        }
        if ($multiDay && $days !== null && $days >= 0 && $days <= 7 && !$hasLodging) {
            $out[] = $this->issue(
                'readiness:no-lodging','readiness',$days <= 2 ? 'critical' : 'high',
                'No confirmed lodging is recorded',
                'Vacation Brain cannot find a confirmed lodging booking for this multi-day trip. Live search results are not reservations.',
                'manual',96,'overview','overview',
                [['label'=>'Research lodging now','agent'=>'itinerary','tab'=>'overview','kind'=>'lodging','priority'=>$days <= 2 ? 99 : 92]]
            );
        }
        return $out;
    }

    private function flightIssues(array $trip, array $dashboard, array $items, array $traits, array $health): array
    {
        $out = [];
        $payload = is_array($dashboard['snapshots']['flights']['payload'] ?? null)
            ? $dashboard['snapshots']['flights']['payload'] : [];
        $source = $this->sourceState($health, 'flights');

        foreach ((array)($payload['booked_statuses'] ?? []) as $row) {
            if (!is_array($row)) continue;
            $bookingId = (int)($row['booking_id'] ?? 0);
            $flight = trim((string)($row['flight_number'] ?? '')) ?: 'Booked flight';
            $status = strtolower((string)($row['status'] ?? 'unknown'));
            $departure = is_array($row['departure'] ?? null) ? $row['departure'] : [];
            $arrival = is_array($row['arrival'] ?? null) ? $row['arrival'] : [];
            $key = 'flight:'.($bookingId > 0 ? (string)$bookingId : $this->slug($flight, 40));

            if (in_array($status, ['cancelled','diverted','incident'], true)) {
                $out[] = $this->issue(
                    $key.':status','risk','critical',
                    $flight.' needs immediate attention',
                    $flight.' is currently reported as '.str_replace('_',' ', $status).'. Research replacement options before changing or purchasing anything.',
                    $source,98,'flights','flights',
                    [['label'=>'Research recovery options','agent'=>'flights','tab'=>'flights','kind'=>'recovery','priority'=>100]]
                );
            }

            $delay = is_numeric($departure['delay'] ?? null) ? (int)$departure['delay'] : 0;
            if ($delay >= 60) {
                $out[] = $this->issue(
                    $key.':delay','risk','high',
                    $flight.' has a substantial delay',
                    'The saved live snapshot reports about '.$delay.' minutes of departure delay. Recheck downstream plans and connections.',
                    $source,95,'flights','flights',
                    [['label'=>'Research delay recovery','agent'=>'flights','tab'=>'flights','kind'=>'recovery','priority'=>94]]
                );
            } elseif ($delay >= 20) {
                $out[] = $this->issue(
                    $key.':delay','risk','medium',
                    $flight.' is running late',
                    'The saved live snapshot reports about '.$delay.' minutes of departure delay. Keep airport and arrival plans flexible.',
                    $source,92,'flights','flights',
                    [['label'=>'Check the downstream impact','agent'=>'flights','tab'=>'flights','kind'=>'recovery','priority'=>76]]
                );
            }

            $arrivalText = (string)($arrival['estimated'] ?? $arrival['actual'] ?? $arrival['scheduled'] ?? '');
            $arrivalTs = $arrivalText !== '' ? strtotime($arrivalText) : false;
            if ($arrivalTs && (int)date('G', $arrivalTs) >= 18) {
                $date = date('Y-m-d', $arrivalTs);
                $evening = array_values(array_filter($items, static fn(array $item): bool =>
                    (string)($item['scheduled_date'] ?? '') === $date
                    && (string)($item['daypart'] ?? '') === 'evening'
                ));
                if ($evening) {
                    $out[] = $this->issue(
                        $key.':late-arrival','conflict','high',
                        'Arrival may collide with evening plans',
                        $flight.' is estimated to arrive at '.date('g:i A', $arrivalTs).' while '.count($evening).' evening plan'.(count($evening) === 1 ? ' is' : 's are').' scheduled that day.',
                        $source,90,'itinerary','itinerary',
                        [['label'=>'Rework arrival-night plans','agent'=>'itinerary','tab'=>'itinerary','kind'=>'reschedule','priority'=>90]]
                    );
                }
            }
        }

        $morningTolerance = (int)($traits['morning_tolerance']['score'] ?? 50);
        if ($morningTolerance <= 35) {
            foreach ($this->safeFlights((int)$trip['user_id'], (int)$trip['id']) as $booking) {
                $ts = !empty($booking['starts_at']) ? strtotime((string)$booking['starts_at']) : false;
                if ($ts && (int)date('G', $ts) < 7) {
                    $out[] = $this->issue(
                        'personal:early-flight:'.(int)$booking['id'],'readiness','low',
                        'This departure fights your learned morning preference',
                        'Your traveler profile strongly dislikes early starts, and '.(string)$booking['title'].' is scheduled for '.date('g:i A', $ts).'. Build extra buffer the night before.',
                        'inferred',82,'flights','flights',
                        [['label'=>'Plan the early departure','agent'=>'itinerary','tab'=>'itinerary','kind'=>'personalization','priority'=>54]]
                    );
                }
            }
        }
        return $out;
    }

    private function weatherIssues(array $dashboard, array $items, array $health): array
    {
        $out = [];
        $weather = is_array($dashboard['snapshots']['weather']['payload'] ?? null)
            ? $dashboard['snapshots']['weather']['payload'] : [];
        if (empty($weather['ok'])) return $out;
        $source = $this->sourceState($health, 'weather');

        $alerts = (array)($weather['alerts'] ?? []);
        if ($alerts) {
            $first = is_array($alerts[0] ?? null) ? $alerts[0] : [];
            $headline = trim((string)($first['headline'] ?? $first['event'] ?? 'Weather alert'));
            $out[] = $this->issue(
                'weather:alert','risk','high','Weather alert for the trip',
                $headline !== '' ? $headline : 'A provider weather alert is active for the destination.',
                $source,94,'weather','weather',
                [['label'=>'Build a weather recovery plan','agent'=>'weather','tab'=>'weather','kind'=>'recovery','priority'=>92]]
            );
        }

        foreach (array_slice((array)($weather['days'] ?? []), 0, 14) as $day) {
            if (!is_array($day)) continue;
            $date = (string)($day['date'] ?? '');
            if ($date === '') continue;
            $scheduled = array_values(array_filter($items, static fn(array $item): bool =>
                (string)($item['scheduled_date'] ?? '') === $date
            ));
            if (!$scheduled) continue;

            $rain = $day['precip_probability'] ?? null;
            $high = $day['high'] ?? null;
            if (is_numeric($rain) && (float)$rain >= 65) {
                $out[] = $this->issue(
                    'weather:rain:'.$date,'conflict','medium',
                    'Rain risk may affect scheduled plans',
                    count($scheduled).' saved plan'.(count($scheduled) === 1 ? ' is' : 's are').' scheduled on '.$this->dayLabel($date).' with about '.round((float)$rain).'% precipitation probability. This is a planning risk, not proof the activities are outdoors.',
                    $source,78,'weather','itinerary',
                    [['label'=>'Find an indoor backup','agent'=>'itinerary','tab'=>'itinerary','kind'=>'weather_backup','priority'=>78]]
                );
            }
            if (is_numeric($high) && (float)$high >= 105) {
                $out[] = $this->issue(
                    'weather:heat:'.$date,'risk','medium',
                    'Extreme heat may change the pace',
                    $this->dayLabel($date).' is currently around '.round((float)$high).'° at the high with '.count($scheduled).' saved plan'.(count($scheduled) === 1 ? '' : 's').'. Consider shifting strenuous or exposed activities away from peak heat.',
                    $source,86,'weather','itinerary',
                    [['label'=>'Rebalance the hot day','agent'=>'itinerary','tab'=>'itinerary','kind'=>'weather_backup','priority'=>76]]
                );
            }
        }
        return $out;
    }

    private function itineraryIssues(array $bookings, array $items): array
    {
        $out = [];
        $blocks = [];
        foreach ($items as $item) {
            $date = (string)($item['scheduled_date'] ?? '');
            if ($date === '') continue;
            $daypart = (string)($item['daypart'] ?? 'anytime');
            $blocks[$date.'|'.$daypart][] = $item;
        }
        foreach ($blocks as $block => $rows) {
            if (count($rows) < 3) continue;
            [$date, $daypart] = explode('|', $block, 2);
            $out[] = $this->issue(
                'itinerary:packed:'.$date.':'.$daypart,'conflict','medium',
                'One itinerary block is overloaded',
                count($rows).' saved items are stacked into '.($daypart === 'anytime' ? 'the same day' : $daypart).' on '.$this->dayLabel($date).'. Transit and real-world delays may make that brittle.',
                'manual',88,'itinerary','itinerary',
                [['label'=>'Optimize this day','agent'=>'itinerary','tab'=>'itinerary','kind'=>'reschedule','priority'=>78]]
            );
        }

        $timed = [];
        foreach ($bookings as $booking) {
            $start = !empty($booking['starts_at']) ? strtotime((string)$booking['starts_at']) : false;
            $end = !empty($booking['ends_at']) ? strtotime((string)$booking['ends_at']) : false;
            if (!$start || !$end || $end <= $start) continue;
            $timed[] = ['id'=>(int)$booking['id'],'title'=>(string)$booking['title'],'start'=>$start,'end'=>$end];
        }
        usort($timed, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);
        $count = count($timed);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($timed[$j]['start'] >= $timed[$i]['end']) break;
                $overlap = min($timed[$i]['end'], $timed[$j]['end']) - max($timed[$i]['start'], $timed[$j]['start']);
                if ($overlap < 900) continue;
                $a = $timed[$i];
                $b = $timed[$j];
                $out[] = $this->issue(
                    'itinerary:booking-overlap:'.$a['id'].':'.$b['id'],'conflict','high',
                    'Two booked items overlap',
                    $a['title'].' overlaps '.$b['title'].' by about '.max(15, (int)round($overlap / 60)).' minutes. Confirm time zones and reservation times before changing anything.',
                    'manual',95,'itinerary','itinerary',
                    [['label'=>'Research the booking conflict','agent'=>'itinerary','tab'=>'itinerary','kind'=>'conflict','priority'=>94]]
                );
            }
        }
        return $out;
    }

    private function budgetIssues(array $dashboard): array
    {
        $budget = is_array($dashboard['budget'] ?? null) ? $dashboard['budget'] : [];
        $target = $budget['target'] ?? null;
        if (!is_numeric($target) || (float)$target <= 0) return [];
        $projected = (float)($budget['projected'] ?? 0);
        $percent = ($projected / (float)$target) * 100;

        if ($percent > 100) {
            return [$this->issue(
                'budget:over','risk','high','Projected trip cost is over budget',
                'Saved and live estimated trip costs are about '.round($percent).'% of the target budget. Live airfare/lodging estimates can change and are not confirmed charges.',
                'mixed',88,'budget','budget',
                [['label'=>'Find lower-cost alternatives','agent'=>'budget','tab'=>'budget','kind'=>'budget','priority'=>92]]
            )];
        }
        if ($percent >= 90) {
            return [$this->issue(
                'budget:tight','risk','medium','The trip budget is getting tight',
                'Projected costs are about '.round($percent).'% of the current target. Leave room for taxes, transit, food and price movement.',
                'mixed',84,'budget','budget',
                [['label'=>'Stress-test the budget','agent'=>'budget','tab'=>'budget','kind'=>'budget','priority'=>74]]
            )];
        }
        return [];
    }

    private function freshnessIssues(array $trip, array $health): array
    {
        $days = $this->daysUntil($trip['start_date'] ?? null);
        if ($days === null || $days < 0 || $days > 3) return [];

        $needed = ['weather'];
        if (trim((string)($trip['origin_iata'] ?? $trip['origin_name'] ?? '')) !== '') $needed[] = 'flights';
        if (!empty($trip['start_date']) && !empty($trip['end_date'])
            && strtotime((string)$trip['end_date']) > strtotime((string)$trip['start_date'])) {
            $needed[] = 'lodging';
        }

        $out = [];
        foreach ($needed as $type) {
            $state = (string)($health[$type]['state'] ?? 'waiting');
            if (!in_array($state, ['stale','error','setup','waiting'], true)) continue;
            $tab = $type === 'lodging' ? 'overview' : $type;
            $agent = $type === 'lodging' ? 'itinerary' : $type;
            $out[] = $this->issue(
                'freshness:'.$type,'readiness',$days <= 1 ? 'high' : 'medium',
                ucfirst($type).' data needs a fresh check',
                'Departure is close, but '.$type.' intelligence is currently '.str_replace('_',' ', $state).'. Refresh the provider snapshot before relying on it.',
                $this->sourceState($health, $type),90,$tab,$agent,
                [['label'=>'Refresh and reassess '.ucfirst($type),'agent'=>$agent,'tab'=>$tab,'kind'=>'refresh','priority'=>$days <= 1 ? 90 : 74]]
            );
        }
        return $out;
    }

    private function opportunityIssues(int $userId, int $tripId, array $dashboard, array $health): array
    {
        $out = [];
        if (db_table_exists('travel_watch_events') && db_table_exists('travel_watches')) {
            $stmt = $this->pdo->prepare(
                "SELECT e.id,e.data_type,e.event_type,e.title,e.body,e.created_at
                 FROM travel_watch_events e
                 JOIN travel_watches w ON w.id=e.watch_id
                 WHERE e.user_id=? AND w.dream_trip_id=?
                   AND e.created_at>=DATE_SUB(NOW(),INTERVAL 36 HOUR)
                   AND e.event_type IN ('fare_drop','lodging_drop','new_events','local_change')
                 ORDER BY e.created_at DESC,e.id DESC LIMIT 6"
            );
            $stmt->execute([$userId, $tripId]);
            foreach ($stmt->fetchAll() ?: [] as $event) {
                $kind = (string)$event['event_type'];
                $tab = match ($kind) {
                    'fare_drop' => 'flights',
                    'lodging_drop' => 'overview',
                    'new_events' => 'events',
                    default => 'local',
                };
                $agent = $tab === 'overview' ? 'itinerary' : $tab;
                $out[] = $this->issue(
                    'opportunity:watch:'.(int)$event['id'],'opportunity','low',
                    (string)$event['title'],(string)$event['body'],
                    $this->sourceState($health, (string)$event['data_type']),86,$tab,$agent,
                    [['label'=>'Explore this opportunity','agent'=>$agent,'tab'=>$tab,'kind'=>'opportunity','priority'=>58]]
                );
            }
        }

        $weather = is_array($dashboard['snapshots']['weather']['payload'] ?? null)
            ? $dashboard['snapshots']['weather']['payload'] : [];
        foreach (array_slice((array)($weather['days'] ?? []), 0, 7) as $day) {
            if (!is_array($day)) continue;
            $date = (string)($day['date'] ?? '');
            $rain = $day['precip_probability'] ?? null;
            $high = $day['high'] ?? null;
            if ($date !== '' && is_numeric($rain) && is_numeric($high)
                && (float)$rain <= 20 && (float)$high >= 70 && (float)$high <= 90) {
                $out[] = $this->issue(
                    'opportunity:weather:'.$date,'opportunity','low',
                    'A strong outdoor window is emerging',
                    $this->dayLabel($date).' currently looks comfortable around '.round((float)$high).'° with only '.round((float)$rain).'% precipitation probability. Forecasts can still move.',
                    $this->sourceState($health, 'weather'),78,'weather','itinerary',
                    [['label'=>'Use the good-weather window','agent'=>'itinerary','tab'=>'itinerary','kind'=>'opportunity','priority'=>52]]
                );
                break;
            }
        }
        return $out;
    }

    private function persistIssues(int $userId, int $tripId, array $issues): void
    {
        $activeKeys = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($issues as $issue) {
                $activeKeys[] = $issue['issue_key'];
                $existing = $this->rawIssue($userId, $tripId, $issue['issue_key']);
                $same = $existing && hash_equals((string)$existing['fingerprint'], (string)$issue['fingerprint']);
                $status = $existing && (string)$existing['status'] === 'dismissed' && $same ? 'dismissed' : 'open';
                $notifiedAt = $same ? ($existing['notified_at'] ?? null) : null;
                $researchAt = $same ? ($existing['auto_research_started_at'] ?? null) : null;

                $sql = 'INSERT INTO trip_proactive_issues
                    (user_id,dream_trip_id,issue_key,issue_type,severity,title,body,source_type,source_state,confidence,target_tab,agent_type,recommended_actions_json,metadata_json,fingerprint,status,notified_at,auto_research_started_at,last_seen_at,resolved_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NULL)
                    ON DUPLICATE KEY UPDATE
                      issue_type=VALUES(issue_type),severity=VALUES(severity),title=VALUES(title),body=VALUES(body),
                      source_type=VALUES(source_type),source_state=VALUES(source_state),confidence=VALUES(confidence),
                      target_tab=VALUES(target_tab),agent_type=VALUES(agent_type),
                      recommended_actions_json=VALUES(recommended_actions_json),metadata_json=VALUES(metadata_json),
                      fingerprint=VALUES(fingerprint),status=VALUES(status),notified_at=VALUES(notified_at),
                      auto_research_started_at=VALUES(auto_research_started_at),last_seen_at=NOW(),resolved_at=NULL,updated_at=NOW()';
                $this->pdo->prepare($sql)->execute([
                    $userId,$tripId,$issue['issue_key'],$issue['issue_type'],$issue['severity'],$issue['title'],$issue['body'],
                    $issue['source_type'],$issue['source_state'],$issue['confidence'],$issue['target_tab'],$issue['agent_type'],
                    $this->json($issue['actions']),$this->json($issue['metadata']),$issue['fingerprint'],$status,$notifiedAt,$researchAt,
                ]);
            }

            if ($activeKeys) {
                $marks = implode(',', array_fill(0, count($activeKeys), '?'));
                $params = array_merge([$userId, $tripId], $activeKeys);
                $this->pdo->prepare(
                    "UPDATE trip_proactive_issues
                     SET status='resolved',resolved_at=NOW(),updated_at=NOW()
                     WHERE user_id=? AND dream_trip_id=? AND status='open' AND issue_key NOT IN ({$marks})"
                )->execute($params);
            } else {
                $this->pdo->prepare(
                    "UPDATE trip_proactive_issues
                     SET status='resolved',resolved_at=NOW(),updated_at=NOW()
                     WHERE user_id=? AND dream_trip_id=? AND status='open'"
                )->execute([$userId, $tripId]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function syncNextMoves(int $userId, int $tripId, array $issues): void
    {
        if (!db_table_exists('trip_agent_actions')) return;
        $activeSources = [];

        foreach ($issues as $issue) {
            if ($issue['severity'] === 'info') continue;
            $source = $this->moveSource((string)$issue['issue_key'], (string)$issue['fingerprint']);
            $activeSources[$source] = true;
            $existing = $this->actionBySource($userId, $tripId, $source);
            $status = $existing ? (string)$existing['status'] : 'open';
            if (in_array($status, ['dismissed','completed','superseded'], true)) $status = 'open';

            $primary = $issue['actions'][0] ?? [];
            $priority = max(0, min(100, (int)($primary['priority'] ?? $this->issuePriority($issue['severity']))));
            $agent = $this->agent((string)($primary['agent'] ?? $issue['agent_type']));
            $tab = $this->tab((string)($primary['tab'] ?? $issue['target_tab']));
            $kind = $this->slug((string)($primary['kind'] ?? $issue['issue_type']), 32) ?: 'planning';
            $metadata = [
                'source'=>'proactive_travel',
                'issue_key'=>$issue['issue_key'],
                'issue_fingerprint'=>$issue['fingerprint'],
                'issue_type'=>$issue['issue_type'],
                'severity'=>$issue['severity'],
                'source_state'=>$issue['source_state'],
                'confidence'=>$issue['confidence'],
                'approval_required_for_changes'=>true,
            ];
            $suggestion = 'proactive-'.substr(hash('sha256', $issue['issue_key'].'|'.$issue['fingerprint']), 0, 48);
            $sql = "INSERT INTO trip_agent_actions
                    (user_id,dream_trip_id,batch_id,suggestion_key,source_key,agent_type,action_kind,title,body,priority,target_tab,status,metadata_json)
                    VALUES (?,?,NULL,?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                      suggestion_key=VALUES(suggestion_key),agent_type=VALUES(agent_type),action_kind=VALUES(action_kind),
                      title=VALUES(title),body=VALUES(body),priority=VALUES(priority),target_tab=VALUES(target_tab),
                      status=VALUES(status),metadata_json=VALUES(metadata_json),
                      accepted_at=IF(VALUES(status)='open',NULL,accepted_at),
                      dismissed_at=IF(VALUES(status)='open',NULL,dismissed_at),
                      completed_at=IF(VALUES(status)='open',NULL,completed_at),updated_at=NOW()";
            $this->pdo->prepare($sql)->execute([
                $userId,$tripId,$suggestion,$source,$agent,$kind,$issue['title'],$issue['body'],$priority,$tab,$status,$this->json($metadata),
            ]);
        }

        $stmt = $this->pdo->prepare(
            "SELECT id,source_key FROM trip_agent_actions
             WHERE user_id=? AND dream_trip_id=? AND status='open' AND source_key LIKE 'proactive:%'"
        );
        $stmt->execute([$userId, $tripId]);
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $source = (string)$row['source_key'];
            if (!isset($activeSources[$source])) {
                $this->pdo->prepare(
                    "UPDATE trip_agent_actions SET status='superseded',updated_at=NOW()
                     WHERE id=? AND user_id=? AND dream_trip_id=? AND status='open'"
                )->execute([(int)$row['id'], $userId, $tripId]);
            }
        }
    }

    private function notifyIssues(int $userId, int $tripId, array $issues, array $prefs): int
    {
        if (empty($prefs['urgent_alerts']) || $this->inQuietHours($prefs)) return 0;
        $minimum = (string)($prefs['minimum_severity'] ?? 'medium');
        $count = 0;

        foreach ($issues as $issue) {
            if (!empty($issue['notified_at'])) continue;
            if ($issue['issue_type'] === 'opportunity') {
                if (empty($prefs['opportunity_alerts']) || $minimum === 'high') continue;
            } elseif (!$this->meetsMinimum((string)$issue['severity'], $minimum)) {
                continue;
            }

            $url = app_url('proactive-trip.php?id='.$tripId.'#issue-'.(int)$issue['id']);
            $unique = 'proactive-issue:'.(int)$issue['id'].':'.substr((string)$issue['fingerprint'], 0, 32);
            (new NotificationService($this->pdo))->create(
                $userId,'proactive_trip',(string)$issue['title'],(string)$issue['body'],$url,null,null,$unique
            );
            $this->pdo->prepare(
                'UPDATE trip_proactive_issues SET notified_at=NOW() WHERE id=? AND user_id=? AND notified_at IS NULL'
            )->execute([(int)$issue['id'], $userId]);
            $count++;
        }
        return $count;
    }

    private function startResearch(int $userId, int $tripId, array $issues, array $prefs): int
    {
        if (empty($prefs['auto_research']) || !db_table_exists('trip_agent_action_executions')) return 0;
        $execution = new TripAgentExecutionService($this->pdo);
        if (!$execution->ready()) return 0;
        $started = 0;

        foreach ($issues as $issue) {
            if (!in_array((string)$issue['severity'], ['high','critical'], true)) continue;
            if ($issue['issue_type'] === 'opportunity' || !empty($issue['auto_research_started_at'])) continue;

            $source = $this->moveSource((string)$issue['issue_key'], (string)$issue['fingerprint']);
            $action = $this->actionBySource($userId, $tripId, $source);
            if (!$action || (string)$action['status'] !== 'open') continue;

            try {
                $execution->acceptAndStart($userId, $tripId, (int)$action['id']);
                $this->pdo->prepare(
                    'UPDATE trip_proactive_issues SET auto_research_started_at=NOW() WHERE id=? AND user_id=?'
                )->execute([(int)$issue['id'], $userId]);
                $started++;
            } catch (DomainException) {
                // A matching execution may already be active; never duplicate it.
            } catch (Throwable $e) {
                error_log('Proactive research start failed: '.$this->clip($e->getMessage(), 500));
            }
        }
        return $started;
    }

    private function maybeBrief(
        int $userId,
        int $tripId,
        array $trip,
        array $issues,
        array $health,
        array $prefs,
        string $trigger
    ): bool {
        if (empty($prefs['morning_briefing'])) return false;
        $today = date('Y-m-d');
        $start = (string)($trip['start_date'] ?? '');
        $end = (string)($trip['end_date'] ?? '');
        $travelDay = $start !== '' && $start <= $today && ($end === '' || $end >= $today);
        $hour = (int)date('G');
        if (!$travelDay && ($hour < 5 || $hour > 11)) return false;

        $days = $this->daysUntil($start ?: null);
        if (!$travelDay && ($days === null || $days < 0 || $days > 14)) return false;
        $kind = $travelDay ? 'travel_day' : 'morning';
        $top = array_slice($issues, 0, 5);
        $important = count(array_filter($issues, static fn(array $issue): bool =>
            in_array((string)$issue['severity'], ['critical','high'], true)
        ));

        $headline = $travelDay
            ? 'Travel day · '.(string)($trip['name'] ?? 'Your trip')
            : 'Morning trip brief · '.(string)($trip['name'] ?? 'Your trip');
        $lines = [];
        if (!$top) $lines[] = '• No high-value risk or conflict is currently open.';
        foreach ($top as $issue) {
            $lines[] = '• '.strtoupper((string)$issue['severity']).' — '.(string)$issue['title'].': '.(string)$issue['body'];
        }

        $fresh = [];
        foreach (['flights','weather','lodging','events','places'] as $type) {
            if (isset($health[$type])) $fresh[] = ucfirst($type).' '.(string)($health[$type]['label'] ?? 'unknown');
        }
        $body = ($important > 0 ? $important.' important item'.($important === 1 ? '' : 's').' need attention. ' : '')
            .'Data: '.implode(' · ', $fresh)."\n".implode("\n", $lines);
        $payload = [
            'issue_ids'=>array_values(array_map(static fn(array $issue): int => (int)($issue['id'] ?? 0), $top)),
            'risk_count'=>$important,
            'trigger'=>$trigger,
            'data_health'=>array_map(
                static fn(array $h): array => [
                    'state'=>$h['state'] ?? '',
                    'label'=>$h['label'] ?? '',
                    'freshness'=>$h['freshness'] ?? '',
                ],
                array_intersect_key($health, array_flip(['flights','weather','lodging','events','places']))
            ),
            'approval_note'=>'Vacation Brain may research recovery options automatically. It will not apply itinerary, reservation, purchase, or booking changes without approval.',
        ];
        $fingerprint = hash('sha256', $kind.'|'.$today.'|'.$body);

        $existing = $this->pdo->prepare(
            'SELECT id,fingerprint,notified_at FROM trip_proactive_briefings
             WHERE user_id=? AND dream_trip_id=? AND briefing_date=? AND briefing_kind=? LIMIT 1'
        );
        $existing->execute([$userId, $tripId, $today, $kind]);
        $old = $existing->fetch();

        $sql = 'INSERT INTO trip_proactive_briefings
                (user_id,dream_trip_id,briefing_date,briefing_kind,headline,body,payload_json,fingerprint)
                VALUES (?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE headline=VALUES(headline),body=VALUES(body),payload_json=VALUES(payload_json),
                  fingerprint=VALUES(fingerprint),updated_at=NOW()';
        $this->pdo->prepare($sql)->execute([
            $userId,$tripId,$today,$kind,$headline,$body,$this->json($payload),$fingerprint,
        ]);

        if (!$this->inQuietHours($prefs) && (!$old || empty($old['notified_at']))) {
            $row = $this->pdo->prepare(
                'SELECT id FROM trip_proactive_briefings
                 WHERE user_id=? AND dream_trip_id=? AND briefing_date=? AND briefing_kind=? LIMIT 1'
            );
            $row->execute([$userId, $tripId, $today, $kind]);
            $id = (int)$row->fetchColumn();
            (new NotificationService($this->pdo))->create(
                $userId,'trip_briefing',$headline,$this->clip(str_replace("\n", ' ', $body), 600),
                app_url('proactive-trip.php?id='.$tripId.'#briefings'),null,null,'trip-briefing:'.$id
            );
            $this->pdo->prepare(
                'UPDATE trip_proactive_briefings SET notified_at=NOW() WHERE id=? AND user_id=?'
            )->execute([$id, $userId]);
        }
        return !$old;
    }

    private function safeBookings(int $userId, int $tripId): array
    {
        if (!db_table_exists('trip_bookings')) return [];
        $fields = ['id','booking_type','title','provider_name','status','starts_at','ends_at'];
        foreach (['operational_status','terminal','gate','location_name','flight_number','departure_iata','arrival_iata'] as $column) {
            if (db_column_exists('trip_bookings', $column)) $fields[] = $column;
        }
        $sql = 'SELECT '.implode(',', $fields)." FROM trip_bookings
                WHERE user_id=? AND dream_trip_id=? AND status<>'cancelled'
                ORDER BY COALESCE(starts_at,'9999-12-31'),id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, $tripId]);
        return $stmt->fetchAll() ?: [];
    }

    private function safeFlights(int $userId, int $tripId): array
    {
        return array_values(array_filter(
            $this->safeBookings($userId, $tripId),
            static fn(array $booking): bool => (string)($booking['booking_type'] ?? '') === 'flight'
        ));
    }

    private function safeItems(int $tripId): array
    {
        if (!db_table_exists('dream_trip_items')) return [];
        $fields = ['id','item_type','title','price'];
        foreach (['scheduled_date','daypart'] as $column) {
            if (db_column_exists('dream_trip_items', $column)) $fields[] = $column;
        }
        $sql = 'SELECT '.implode(',', $fields)." FROM dream_trip_items
                WHERE dream_trip_id=? ORDER BY COALESCE(scheduled_date,'9999-12-31'),id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tripId]);
        return $stmt->fetchAll() ?: [];
    }

    private function effectiveTraits(int $userId): array
    {
        try {
            $profile = (new VacationProfileService($this->pdo))->snapshot($userId);
            $traits = is_array($profile['traits'] ?? null) ? $profile['traits'] : [];
            if (class_exists('TravelerMemoryGraphService')) {
                $graph = new TravelerMemoryGraphService($this->pdo);
                if ($graph->ready()) $traits = $graph->augmentTraits($userId, $traits);
            }
            return $traits;
        } catch (Throwable) {
            return [];
        }
    }

    private function issue(
        string $key,
        string $type,
        string $severity,
        string $title,
        string $body,
        string $sourceState,
        int $confidence,
        string $target,
        string $agent,
        array $actions,
        array $metadata = []
    ): array {
        return [
            'issue_key'=>$key,
            'issue_type'=>$type,
            'severity'=>$severity,
            'title'=>$title,
            'body'=>$body,
            'source_type'=>$sourceState === 'manual' ? 'user_trip_data' : 'vacation_brain',
            'source_state'=>$sourceState,
            'confidence'=>$confidence,
            'target_tab'=>$target,
            'agent_type'=>$agent,
            'actions'=>$actions,
            'metadata'=>$metadata,
        ];
    }

    private function normalizeIssue(array $issue): ?array
    {
        $key = $this->slug((string)($issue['issue_key'] ?? ''), 160);
        $type = (string)($issue['issue_type'] ?? 'risk');
        $severity = (string)($issue['severity'] ?? 'medium');
        if ($key === '' || !in_array($type, ['risk','conflict','readiness','opportunity'], true)
            || !isset(self::SEVERITY_SCORE[$severity])) return null;

        $issue['issue_key'] = $key;
        $issue['title'] = $this->clip((string)($issue['title'] ?? ''), 180);
        $issue['body'] = $this->clip((string)($issue['body'] ?? ''), 700);
        if ($issue['title'] === '') return null;
        $issue['source_type'] = $this->clip((string)($issue['source_type'] ?? 'vacation_brain'), 48);
        $issue['source_state'] = in_array((string)($issue['source_state'] ?? ''),
            ['live','cached','indicative','manual','inferred','historical','mixed'], true)
            ? (string)$issue['source_state'] : 'inferred';
        $issue['confidence'] = max(0, min(100, (int)($issue['confidence'] ?? 70)));
        $issue['target_tab'] = $this->tab((string)($issue['target_tab'] ?? 'overview'));
        $issue['agent_type'] = $this->agent((string)($issue['agent_type'] ?? $issue['target_tab']));
        $issue['actions'] = is_array($issue['actions'] ?? null) ? array_slice($issue['actions'], 0, 3) : [];
        $issue['metadata'] = is_array($issue['metadata'] ?? null) ? $issue['metadata'] : [];
        $issue['fingerprint'] = hash('sha256', $this->json([
            $issue['issue_key'],$issue['issue_type'],$issue['severity'],$issue['title'],$issue['body'],$issue['source_state'],$issue['actions'],
        ]));
        return $issue;
    }

    private function rawIssue(int $userId, int $tripId, string $key): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM trip_proactive_issues WHERE user_id=? AND dream_trip_id=? AND issue_key=? LIMIT 1'
        );
        $stmt->execute([$userId, $tripId, $key]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function publicIssue(array $row): array
    {
        $actions = json_decode((string)($row['recommended_actions_json'] ?? ''), true);
        if (!is_array($actions)) $actions = [];
        $metadata = json_decode((string)($row['metadata_json'] ?? ''), true);
        if (!is_array($metadata)) $metadata = [];
        $tab = $this->tab((string)$row['target_tab']);
        return [
            'id'=>(int)$row['id'],
            'trip_id'=>(int)$row['dream_trip_id'],
            'issue_key'=>(string)$row['issue_key'],
            'issue_type'=>(string)$row['issue_type'],
            'severity'=>(string)$row['severity'],
            'title'=>(string)$row['title'],
            'body'=>(string)$row['body'],
            'source_type'=>(string)$row['source_type'],
            'source_state'=>(string)$row['source_state'],
            'confidence'=>(int)$row['confidence'],
            'target_tab'=>$tab,
            'agent_type'=>$this->agent((string)$row['agent_type']),
            'actions'=>$actions,
            'metadata'=>$metadata,
            'fingerprint'=>(string)$row['fingerprint'],
            'status'=>(string)$row['status'],
            'notified_at'=>$row['notified_at'] ?? null,
            'auto_research_started_at'=>$row['auto_research_started_at'] ?? null,
            'first_seen_at'=>(string)$row['first_seen_at'],
            'last_seen_at'=>(string)$row['last_seen_at'],
            'resolved_at'=>$row['resolved_at'] ?? null,
            'url'=>app_url('dream-trip.php?id='.(int)$row['dream_trip_id'].'&tab='.rawurlencode($tab).'#next-moves'),
        ];
    }

    private function actionBySource(int $userId, int $tripId, string $source): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM trip_agent_actions WHERE user_id=? AND dream_trip_id=? AND source_key=? LIMIT 1'
        );
        $stmt->execute([$userId, $tripId, $source]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function moveSource(string $issueKey, string $fingerprint): string
    {
        return 'proactive:'.substr(hash('sha256', $issueKey.'|'.$fingerprint), 0, 48);
    }

    private function supersedeMove(int $userId, int $tripId, string $issueKey, string $fingerprint): void
    {
        $source = $this->moveSource($issueKey, $fingerprint);
        $this->pdo->prepare(
            "UPDATE trip_agent_actions SET status='superseded',updated_at=NOW()
             WHERE user_id=? AND dream_trip_id=? AND source_key=? AND status='open'"
        )->execute([$userId, $tripId, $source]);
    }

    private function sourceState(array $health, string $type): string
    {
        $state = (string)($health[$type]['state'] ?? 'waiting');
        return match ($state) {
            'live' => 'live',
            'indicative' => 'indicative',
            'historical' => 'historical',
            'stale', 'limited' => 'cached',
            'setup', 'waiting', 'error' => 'inferred',
            default => 'inferred',
        };
    }

    private function riskScore(array $issues): array
    {
        $score = 0;
        foreach ($issues as $issue) {
            if (($issue['issue_type'] ?? '') === 'opportunity') continue;
            $score = max($score, match ((string)($issue['severity'] ?? 'info')) {
                'critical' => 100,
                'high' => 82,
                'medium' => 58,
                'low' => 30,
                default => 0,
            });
        }
        $label = $score >= 90 ? 'Critical attention'
            : ($score >= 75 ? 'High attention'
            : ($score >= 45 ? 'Watch closely'
            : ($score > 0 ? 'Low risk' : 'Under control')));
        return [$score, $label];
    }

    private function issuePriority(string $severity): int
    {
        return match ($severity) {
            'critical' => 100,
            'high' => 90,
            'medium' => 72,
            'low' => 50,
            default => 35,
        };
    }

    private function meetsMinimum(string $severity, string $minimum): bool
    {
        if ($severity === 'critical') return true;
        return (self::SEVERITY_SCORE[$severity] ?? 0) >= (self::SEVERITY_SCORE[$minimum] ?? 2);
    }

    private function inQuietHours(array $prefs): bool
    {
        $start = (string)($prefs['quiet_start'] ?? '');
        $end = (string)($prefs['quiet_end'] ?? '');
        if ($start === '' || $end === '' || $start === $end) return false;
        $now = date('H:i:s');
        return $start < $end ? ($now >= $start && $now < $end) : ($now >= $start || $now < $end);
    }

    private function timeOrNull(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $value)) {
            throw new InvalidArgumentException('Quiet hours must use a valid time.');
        }
        return strlen($value) === 5 ? $value.':00' : $value;
    }

    private function daysUntil(mixed $date): ?int
    {
        $date = trim((string)$date);
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
        $ts = strtotime($date);
        return $ts === false ? null : (int)floor(($ts - strtotime(date('Y-m-d'))) / 86400);
    }

    private function dayLabel(string $date): string
    {
        $ts = strtotime($date);
        return $ts ? date('D, M j', $ts) : $date;
    }

    private function publicTrip(array $trip): array
    {
        return [
            'id'=>(int)($trip['id'] ?? 0),
            'name'=>(string)($trip['name'] ?? ''),
            'destination_name'=>(string)($trip['destination_name'] ?? ''),
            'start_date'=>$trip['start_date'] ?? null,
            'end_date'=>$trip['end_date'] ?? null,
            'operational_state'=>(string)($trip['operational_state'] ?? 'planning'),
            'booking_readiness'=>(int)($trip['booking_readiness'] ?? 0),
        ];
    }

    private function tab(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, self::TABS, true) ? $value : 'overview';
    }

    private function agent(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === 'lodging') $value = 'itinerary';
        return in_array($value, self::AGENTS, true) ? $value : 'overview';
    }

    private function slug(string $value, int $max): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9:_-]+/', '-', $value) ?? '';
        return substr(trim($value, '-'), 0, $max);
    }

    private function clip(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private function json(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
        return $json === false ? '{}' : $json;
    }

    private function requireReady(): void
    {
        if (!$this->ready()) throw new RuntimeException('Run System Upgrade for Proactive Vacation Brain.');
    }
}
