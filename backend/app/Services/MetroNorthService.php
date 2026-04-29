<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Google\Transit\Realtime\FeedMessage;

class MetroNorthService
{
    private const MTA_FEED_URL   = 'https://api-endpoint.mta.info/Dataservice/mtagtfsfeeds/mnr%2Fgtfs-mnr';
    private const MTA_ALERTS_URL = 'https://api-endpoint.mta.info/Dataservice/mtagtfsfeeds/camsys%2Fmnr-alerts.json';
    private const CACHE_KEY      = 'metro_north_board';
    private const CACHE_TTL      = 20;
    private const NEW_HAVEN_LINE_ROUTE_ID = '3';
    private const DIR_NEW_HAVEN  = 0;
    private const DIR_NYC        = 1;

    public function getBoard(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            return $this->fetchAndProcess();
        });
    }

    public function getAlerts(): array
    {
        return Cache::remember('metro_north_alerts', self::CACHE_TTL, function () {
            $response = Http::timeout(10)->withoutVerifying()->get(self::MTA_ALERTS_URL);
            return $response->successful() ? $response->json() : [];
        });
    }

    public function refreshCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget('metro_north_alerts');
        $this->getBoard();
        $this->getAlerts();
    }

    private function fetchAndProcess(): array
    {
        $stopId         = (string) env('STRATFORD_STOP_ID', '143');
        $now            = time();
        $cancelledTrips = $this->getCancelledTripIds();
        $scheduleCache  = Cache::get('metro_north_stratford_schedule', []);

        if (empty($scheduleCache)) {
            $scheduleCache = $this->buildStratfordScheduleCache();
        }

        $binaryData = $this->fetchProtobuf();
        if (!$binaryData) {
            return ['to_new_haven' => [], 'to_nyc' => []];
        }

        $feed = new FeedMessage();
        $feed->mergeFromString($binaryData);

        $toNewHaven = [];
        $toNyc      = [];

        foreach ($feed->getEntity() as $entity) {
            if ($entity->getTripUpdate() === null) {
                continue;
            }

            $tripUpdate = $entity->getTripUpdate();
            $trip       = $tripUpdate->getTrip();

            if ($trip->getRouteId() !== self::NEW_HAVEN_LINE_ROUTE_ID) {
                continue;
            }

            $tripId      = $trip->getTripId();
            $vehicleLabel = $tripUpdate->getVehicle()?->getLabel() ?? '';
            $trainNumber  = $vehicleLabel ?: $tripId;

            // Determine direction from stop sequence (MTA doesn't populate direction_id in RT feed)
            $allStops = [];
            foreach ($tripUpdate->getStopTimeUpdate() as $stu) {
                $allStops[] = (int) $stu->getStopId();
            }
            if (count($allStops) < 2) {
                continue;
            }
            $firstStop = $allStops[0];
            $lastStop  = $allStops[count($allStops) - 1];
            if ($firstStop < $lastStop) {
                $directionId = self::DIR_NEW_HAVEN;
            } elseif ($firstStop > $lastStop) {
                $directionId = self::DIR_NYC;
            } else {
                continue;
            }

            $isCancelled = in_array($tripId, $cancelledTrips, true);

            foreach ($tripUpdate->getStopTimeUpdate() as $stopTimeUpdate) {
                if ((string) $stopTimeUpdate->getStopId() !== $stopId) {
                    continue;
                }

                $departure = $stopTimeUpdate->getDeparture();
                if (!$departure) {
                    continue;
                }

                $departureTime = $departure->getTime();
                if ($departureTime <= $now) {
                    continue;
                }

                $delaySeconds = $departure->getDelay() ?? 0;
                $status       = $this->resolveStatus($isCancelled, $delaySeconds);

                $eastern = new \DateTimeZone('America/New_York');
                $dt = (new \DateTime())->setTimestamp($departureTime)->setTimezone($eastern);

                $scheduledTs   = $departureTime - $delaySeconds;
                $scheduledDt   = (new \DateTime())->setTimestamp($scheduledTs)->setTimezone($eastern);
                $scheduledSecs = (int)$scheduledDt->format('G') * 3600
                               + (int)$scheduledDt->format('i') * 60
                               + (int)$scheduledDt->format('s');

                $schedInfo = $scheduleCache[$scheduledSecs] ?? null;

                // Support both old format (string) and new format (array)
                if (is_array($schedInfo)) {
                    $trainName = $schedInfo['name'] ?: $trainNumber;
                    $peak      = $schedInfo['peak'];
                    $stops     = $schedInfo['stops'] ?? [];

                    // Metro North GTFS often has bikes_allowed=0 (unknown).
                    // Derive from peak: peak trains = no bikes, off-peak = bikes ok.
                    $bikes = $schedInfo['bikes'];
                    if ($bikes === null && $peak !== null) {
                        $bikes = !$peak;
                    }
                } else {
                    $trainName = $schedInfo ?: $trainNumber;
                    $peak      = null;
                    $bikes     = null;
                    $stops     = [];
                }

                $entry = [
                    'train'  => $trainName,
                    'time'   => $dt->format('g:i A'),
                    'status' => $status,
                    'peak'   => $peak,
                    'bikes'  => $bikes,
                    'stops'  => $stops,
                ];

                if ($directionId === self::DIR_NEW_HAVEN) {
                    $toNewHaven[] = ['ts' => $departureTime, 'data' => $entry];
                } else {
                    $toNyc[] = ['ts' => $departureTime, 'data' => $entry];
                }

                break;
            }
        }

        usort($toNewHaven, fn($a, $b) => $a['ts'] <=> $b['ts']);
        usort($toNyc,      fn($a, $b) => $a['ts'] <=> $b['ts']);

        return [
            'to_new_haven' => array_column(array_slice($toNewHaven, 0, 3), 'data'),
            'to_nyc'       => array_column(array_slice($toNyc,      0, 3), 'data'),
        ];
    }

    private function fetchProtobuf(): ?string
    {
        try {
            $apiKey   = env('MTA_API_KEY', '');
            $response = Http::timeout(10)
                ->withoutVerifying()
                ->withHeaders(array_filter(['x-api-key' => $apiKey]))
                ->get(self::MTA_FEED_URL);

            if ($response->status() === 403) {
                \Log::warning('MetroNorthService: MTA returned 403 — check MTA_API_KEY in .env');
            }
            return $response->successful() ? $response->body() : null;
        } catch (\Exception $e) {
            \Log::error('MetroNorthService fetch failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getCancelledTripIds(): array
    {
        try {
            $alerts    = $this->getAlerts();
            $cancelled = [];

            foreach ($alerts['entity'] ?? [] as $entity) {
                $alert  = $entity['alert'] ?? [];
                $effect = strtoupper($alert['effect'] ?? '');
                if ($effect !== 'NO_SERVICE') {
                    continue;
                }
                foreach ($alert['informed_entity'] ?? [] as $informed) {
                    if (!empty($informed['trip']['trip_id'])) {
                        $cancelled[] = $informed['trip']['trip_id'];
                    }
                }
            }

            return array_unique($cancelled);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Downloads the Metro North GTFS static zip and builds a schedule cache:
     *   departure_time_seconds_since_midnight => [
     *     'name'  => '1274',
     *     'peak'  => true|false|null,
     *     'bikes' => true|false|null,
     *     'stops' => ['Bridgeport', 'Milford', 'West Haven', 'New Haven'],
     *   ]
     *
     * Cached for 24 hours. Run via: php artisan metro-north:build-schedule
     */
    public function buildStratfordScheduleCache(): array
    {
        $zipUrl  = 'https://rrgtfsfeeds.s3.amazonaws.com/gtfsmnr.zip';
        $tmpFile = tempnam(sys_get_temp_dir(), 'gtfsmnr') . '.zip';

        try {
            $response = Http::timeout(120)->withoutVerifying()->get($zipUrl);
            if (!$response->successful()) {
                \Log::error('buildStratfordScheduleCache: failed to download GTFS zip');
                return [];
            }

            file_put_contents($tmpFile, $response->body());
            $zip = new \ZipArchive();
            if ($zip->open($tmpFile) !== true) {
                \Log::error('buildStratfordScheduleCache: failed to open zip');
                return [];
            }

            // ── 1. trips.txt ─────────────────────────────────────────────────
            $tripsContent = $zip->getFromName('trips.txt');
            $lines        = explode("\n", trim($tripsContent));
            $header       = str_getcsv(array_shift($lines));
            $tidIdx = array_search('trip_id',        $header);
            $snIdx  = array_search('trip_short_name',$header);
            $rIdx   = array_search('route_id',        $header);
            $pkIdx  = array_search('peak_offpeak',    $header); // may not exist
            $bkIdx  = array_search('bikes_allowed',   $header);

            $tripInfo = [];
            foreach ($lines as $line) {
                if (!trim($line)) continue;
                $cols = str_getcsv($line);
                if (($cols[$rIdx] ?? '') !== self::NEW_HAVEN_LINE_ROUTE_ID) continue;
                $tid = $cols[$tidIdx] ?? '';
                $tripInfo[$tid] = [
                    'name'  => $cols[$snIdx] ?? '',
                    'peak'  => ($pkIdx !== false && $pkIdx !== -1)
                                ? ($cols[$pkIdx] ?? '0') === '1'
                                : null,
                    'bikes' => ($bkIdx !== false && $bkIdx !== -1)
                                ? ($cols[$bkIdx] === '1' ? true : ($cols[$bkIdx] === '2' ? false : null))
                                : null,
                ];
            }
            unset($tripsContent, $lines);

            // ── 2. stops.txt → stop_id: stop_name ────────────────────────────
            $stopsContent = $zip->getFromName('stops.txt');
            $sLines       = explode("\n", trim($stopsContent));
            $sHeader      = str_getcsv(array_shift($sLines));
            $sIdIdx       = array_search('stop_id',   $sHeader);
            $sNmIdx       = array_search('stop_name', $sHeader);
            $stopNames = [];
            foreach ($sLines as $line) {
                if (!trim($line)) continue;
                $cols = str_getcsv($line);
                $stopNames[(string)($cols[$sIdIdx] ?? '')] = $cols[$sNmIdx] ?? '';
            }
            unset($stopsContent, $sLines);

            $stratfordStop = (string) env('STRATFORD_STOP_ID', '143');

            // ── 3. stop_times.txt pass 1: find Stratford departure per trip ──
            $stratfordEntries = []; // trip_id => [secs, seq]

            $stream = $zip->getStream('stop_times.txt');
            $stHeader = str_getcsv(trim(fgets($stream)));
            $stTidIdx  = array_search('trip_id',        $stHeader);
            $stDepIdx  = array_search('departure_time', $stHeader);
            $stStopIdx = array_search('stop_id',        $stHeader);
            $stSeqIdx  = array_search('stop_sequence',  $stHeader);

            while (($line = fgets($stream)) !== false) {
                $line = trim($line);
                if (!$line) continue;
                if (strpos($line, $stratfordStop) === false) continue;
                $cols   = str_getcsv($line);
                $stopId = (string)($cols[$stStopIdx] ?? '');
                if ($stopId !== $stratfordStop) continue;
                $tid = $cols[$stTidIdx] ?? '';
                if (!isset($tripInfo[$tid])) continue;
                [$h, $m, $s] = explode(':', $cols[$stDepIdx]);
                $secs = (int)$h * 3600 + (int)$m * 60 + (int)$s;
                $stratfordEntries[$tid] = [
                    'secs' => $secs,
                    'seq'  => (int)($cols[$stSeqIdx] ?? 0),
                ];
            }
            fclose($stream);

            // ── 4. stop_times.txt pass 2: collect subsequent stops ───────────
            $tripStops = []; // trip_id => [seq => stop_name]

            $stream = $zip->getStream('stop_times.txt');
            fgets($stream); // skip header

            while (($line = fgets($stream)) !== false) {
                $line = trim($line);
                if (!$line) continue;
                $cols   = str_getcsv($line);
                $tid    = $cols[$stTidIdx] ?? '';
                if (!isset($stratfordEntries[$tid])) continue;
                $seq        = (int)($cols[$stSeqIdx] ?? 0);
                $stratSeq   = $stratfordEntries[$tid]['seq'];
                if ($seq <= $stratSeq) continue;
                $sid       = (string)($cols[$stStopIdx] ?? '');
                $stopName  = $stopNames[$sid] ?? $sid;
                $tripStops[$tid][$seq] = $stopName;
            }
            fclose($stream);
            $zip->close();

            // ── 5. Build final cache ──────────────────────────────────────────
            $depTimeToInfo = [];
            foreach ($stratfordEntries as $tid => $entry) {
                $info  = $tripInfo[$tid];
                $stops = [];
                if (!empty($tripStops[$tid])) {
                    ksort($tripStops[$tid]);
                    $stops = array_values($tripStops[$tid]);
                }
                $depTimeToInfo[$entry['secs']] = [
                    'name'  => $info['name'],
                    'peak'  => $info['peak'],
                    'bikes' => $info['bikes'],
                    'stops' => $stops,
                ];
            }

            Cache::put('metro_north_stratford_schedule', $depTimeToInfo, 86400);
            \Log::info('buildStratfordScheduleCache: cached ' . count($depTimeToInfo) . ' Stratford departures');
            return $depTimeToInfo;

        } finally {
            if (file_exists($tmpFile)) @unlink($tmpFile);
        }
    }

    private function resolveStatus(bool $isCancelled, int $delaySeconds): string
    {
        if ($isCancelled) {
            return 'Cancelled';
        }
        if ($delaySeconds > 0) {
            $minutes = (int) round($delaySeconds / 60);
            return "Delayed {$minutes} min";
        }
        return 'On Time';
    }
}
