<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Google\Transit\Realtime\FeedMessage;
use Google\Transit\Realtime\TripDescriptor\ScheduleRelationship as TripScheduleRelationship;
use Google\Transit\Realtime\TripUpdate\StopTimeUpdate\ScheduleRelationship as StopTimeScheduleRelationship;

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
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
                return $this->fetchAndProcess();
            });
        } catch (\Throwable $e) {
            \Log::error('MetroNorthService getBoard failed', ['error' => $e->getMessage()]);
            return $this->emptyBoard();
        }
    }

    public function getAlerts(): array
    {
        try {
            return Cache::remember('metro_north_alerts', self::CACHE_TTL, function () {
                $response = Http::timeout(10)->connectTimeout(5)->withoutVerifying()->get(self::MTA_ALERTS_URL);
                return $response->successful() ? $response->json() : [];
            });
        } catch (\Throwable $e) {
            \Log::error('MetroNorthService getAlerts failed', ['error' => $e->getMessage()]);
            return [];
        }
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

        $binaryData = $this->fetchProtobuf();
        if (!$binaryData) {
            return $this->emptyBoard();
        }

        $cancelledTrips = $this->getCancelledTripIds();
        $scheduleCache  = Cache::get('metro_north_stratford_schedule', []);

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

            // Only trips actively operating per the live feed. A trip the
            // agency has pulled from service (CANCELED/DELETED) is not a
            // real upcoming departure and must not appear on the board.
            $tripScheduleRelationship = $trip->getScheduleRelationship();
            if (in_array($tripScheduleRelationship, [
                TripScheduleRelationship::CANCELED,
                TripScheduleRelationship::DELETED,
            ], true)) {
                continue;
            }

            $tripId       = $trip->getTripId();
            $vehicleLabel = $tripUpdate->getVehicle()?->getLabel() ?? '';
            $trainNumber  = $vehicleLabel ?: $tripId;
            $isCancelled  = in_array($tripId, $cancelledTrips, true);

            foreach ($tripUpdate->getStopTimeUpdate() as $stopTimeUpdate) {
                if ((string) $stopTimeUpdate->getStopId() !== $stopId) {
                    continue;
                }

                // SKIPPED means the train passes Stratford without stopping;
                // NO_DATA means the feed has no real prediction for this stop.
                // Either way arrival/departure fields may still be present
                // (they're optional, not forbidden, for these states), so
                // this must be checked explicitly rather than relying on a
                // null departure check alone — otherwise a skipped/express
                // trip can show up as a real departure.
                $stopScheduleRelationship = $stopTimeUpdate->getScheduleRelationship();
                if (in_array($stopScheduleRelationship, [
                    StopTimeScheduleRelationship::SKIPPED,
                    StopTimeScheduleRelationship::NO_DATA,
                ], true)) {
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

                // Direction must come from the static schedule's direction_id
                // (0 = New Haven, 1 = NYC) — MTA's real-time feed always
                // reports direction_id = 0 regardless of actual direction, so
                // TripDescriptor::getDirectionId() can never be trusted, and
                // raw stop_id ordering is not a reliable substitute either.
                // If the live departure can't be matched to a static
                // scheduled time, direction is unknown and the trip is
                // excluded rather than guessed.
                $directionId = is_array($schedInfo) ? ($schedInfo['direction'] ?? null) : null;
                if ($directionId !== self::DIR_NEW_HAVEN && $directionId !== self::DIR_NYC) {
                    continue;
                }

                $trainName = $schedInfo['name'] ?: $trainNumber;
                $peak      = $schedInfo['peak'];
                $stops     = $schedInfo['stops'] ?? [];

                // Metro North GTFS often has bikes_allowed=0 (unknown).
                // Derive from peak: peak trains = no bikes, off-peak = bikes ok.
                $bikes = $schedInfo['bikes'];
                if ($bikes === null && $peak !== null) {
                    $bikes = !$peak;
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
                ->connectTimeout(5)
                ->withoutVerifying()
                ->withHeaders(array_filter(['x-api-key' => $apiKey]))
                ->get(self::MTA_FEED_URL);

            if ($response->status() === 403) {
                \Log::warning('MetroNorthService: MTA returned 403 — check MTA_API_KEY in .env');
            }
            return $response->successful() ? $response->body() : null;
        } catch (\Throwable $e) {
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
     *     'name'      => '1274',
     *     'direction' => 0|1|null,  // 0 = New Haven, 1 = NYC, per trips.txt direction_id
     *     'peak'      => true|false|null,
     *     'bikes'     => true|false|null,
     *     'stops'     => ['Bridgeport', 'Milford', 'West Haven', 'New Haven'],
     *   ]
     *
     * Cached until the next successful rebuild. Run via: php artisan metro-north:build-schedule
     */
    public function buildStratfordScheduleCache(): array
    {
        $zipUrl  = 'https://rrgtfsfeeds.s3.amazonaws.com/gtfsmnr.zip';
        $tmpFile = tempnam(sys_get_temp_dir(), 'gtfsmnr') . '.zip';

        try {
            $response = Http::timeout(30)->connectTimeout(10)->withoutVerifying()->get($zipUrl);
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
            $dirIdx = array_search('direction_id',    $header);

            $tripInfo = [];
            foreach ($lines as $line) {
                if (!trim($line)) continue;
                $cols = str_getcsv($line);
                if (($cols[$rIdx] ?? '') !== self::NEW_HAVEN_LINE_ROUTE_ID) continue;
                $tid = $cols[$tidIdx] ?? '';
                $tripInfo[$tid] = [
                    'name'      => $cols[$snIdx] ?? '',
                    'direction' => ($dirIdx !== false && $dirIdx !== -1 && ($cols[$dirIdx] ?? '') !== '')
                                ? (int) $cols[$dirIdx]
                                : null,
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
                    'name'      => $info['name'],
                    'direction' => $info['direction'],
                    'peak'      => $info['peak'],
                    'bikes'     => $info['bikes'],
                    'stops'     => $stops,
                ];
            }

            Cache::forever('metro_north_stratford_schedule', $depTimeToInfo);
            \Log::info('buildStratfordScheduleCache: cached ' . count($depTimeToInfo) . ' Stratford departures');
            return $depTimeToInfo;

        } catch (\Throwable $e) {
            \Log::error('buildStratfordScheduleCache failed', ['error' => $e->getMessage()]);
            return [];
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

    private function emptyBoard(): array
    {
        return ['to_new_haven' => [], 'to_nyc' => []];
    }
}
