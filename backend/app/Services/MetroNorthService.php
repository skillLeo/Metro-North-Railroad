<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Google\Transit\Realtime\FeedMessage;

class MetroNorthService
{
    private const MTA_FEED_URL = 'https://api-endpoint.mta.info/Dataservice/mtagtfsfeeds/mnr%2Fgtfs-mnr';
    private const MTA_ALERTS_URL = 'https://api-endpoint.mta.info/Dataservice/mtagtfsfeeds/camsys%2Fmnr-alerts.json';
    private const CACHE_KEY = 'metro_north_board';
    private const CACHE_TTL = 20;
    private const NEW_HAVEN_LINE_ROUTE_ID = '3';
    private const DIR_NEW_HAVEN = 0;
    private const DIR_NYC = 1;

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
        $stopId = (string) env('STRATFORD_STOP_ID', '143');
        $now = time();

        $cancelledTrips  = $this->getCancelledTripIds();
        $depTimeToName   = Cache::get('metro_north_stratford_schedule', []);

        $binaryData = $this->fetchProtobuf();
        if (!$binaryData) {
            return ['to_new_haven' => [], 'to_nyc' => []];
        }

        $feed = new FeedMessage();
        $feed->mergeFromString($binaryData);

        $toNewHaven = [];
        $toNyc = [];

        foreach ($feed->getEntity() as $entity) {
            if ($entity->getTripUpdate() === null) {
                continue;
            }

            $tripUpdate = $entity->getTripUpdate();
            $trip = $tripUpdate->getTrip();
            $routeId = $trip->getRouteId();

            if ($routeId !== self::NEW_HAVEN_LINE_ROUTE_ID) {
                continue;
            }

            $tripId   = $trip->getTripId();

            $vehicleLabel = $tripUpdate->getVehicle()?->getLabel() ?? '';
            $trainNumber  = $vehicleLabel ?: $tripId;

            // MTA does not populate direction_id in the realtime feed.
            // Determine direction from first vs last stop in the trip update:
            // first < last  → toward New Haven (eastbound)
            // first > last  → toward NYC (westbound)
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
                $status = $this->resolveStatus($isCancelled, $delaySeconds);

                $eastern = new \DateTimeZone('America/New_York');
                $dt = (new \DateTime())->setTimestamp($departureTime)->setTimezone($eastern);

                // Scheduled time = actual - delay. Convert to seconds since midnight.
                $scheduledTs  = $departureTime - $delaySeconds;
                $scheduledDt  = (new \DateTime())->setTimestamp($scheduledTs)->setTimezone($eastern);
                $scheduledSecs = (int)$scheduledDt->format('G') * 3600
                               + (int)$scheduledDt->format('i') * 60
                               + (int)$scheduledDt->format('s');

                $trainName = $depTimeToName[$scheduledSecs] ?? $trainNumber;

                $entry = [
                    'train'  => $trainName,
                    'time'   => $dt->format('g:i A'),
                    'status' => $status,
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
        usort($toNyc, fn($a, $b) => $a['ts'] <=> $b['ts']);

        return [
            'to_new_haven' => array_column(array_slice($toNewHaven, 0, 3), 'data'),
            'to_nyc'       => array_column(array_slice($toNyc, 0, 3), 'data'),
        ];
    }

    private function fetchProtobuf(): ?string
    {
        try {
            $apiKey = env('MTA_API_KEY', '');
            $response = Http::timeout(10)
                ->withoutVerifying()
                ->withHeaders(array_filter(['x-api-key' => $apiKey]))
                ->get(self::MTA_FEED_URL);

            if ($response->status() === 403) {
                \Log::warning('MetroNorthService: MTA returned 403 — check MTA_API_KEY in .env');
            }
            return $response->successful() ? $response->body() : null;
        } catch (\Exception $e) {
            \Log::error('MetroNorthService: protobuf fetch failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function getCancelledTripIds(): array
    {
        try {
            $alerts = $this->getAlerts();
            $cancelled = [];

            foreach ($alerts['entity'] ?? [] as $entity) {
                $alert = $entity['alert'] ?? [];
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
     * Returns seconds-since-midnight → train short name for Stratford departures.
     * Built by the metro-north:build-schedule artisan command, cached for 24h.
     */
    public function buildStratfordScheduleCache(): array
    {
        $zipUrl  = 'https://rrgtfsfeeds.s3.amazonaws.com/gtfsmnr.zip';
        $tmpFile = tempnam(sys_get_temp_dir(), 'gtfsmnr') . '.zip';

        try {
            $response = Http::timeout(60)->withoutVerifying()->get($zipUrl);
            if (!$response->successful()) return [];

            file_put_contents($tmpFile, $response->body());
            $zip = new \ZipArchive();
            if ($zip->open($tmpFile) !== true) return [];

            $tripsContent = $zip->getFromName('trips.txt');
            if (!$tripsContent) { $zip->close(); return []; }

            // trips.txt → static_trip_id: short_name (small file, fine in memory)
            $lines  = explode("\n", trim($tripsContent));
            $header = str_getcsv(array_shift($lines));
            $tidIdx = array_search('trip_id', $header);
            $snIdx  = array_search('trip_short_name', $header);
            $rIdx   = array_search('route_id', $header);

            $tripShortNames = [];
            foreach ($lines as $line) {
                if (!trim($line)) continue;
                $cols = str_getcsv($line);
                if (($cols[$rIdx] ?? '') === self::NEW_HAVEN_LINE_ROUTE_ID) {
                    $tripShortNames[$cols[$tidIdx]] = $cols[$snIdx];
                }
            }

            // stop_times.txt → stream line-by-line, only keep stop 143 rows
            $stratfordStop = (string) env('STRATFORD_STOP_ID', '143');
            $depTimeToName = [];

            $stream = $zip->getStream('stop_times.txt');
            if (!$stream) { $zip->close(); return []; }

            $headerLine = fgets($stream);
            $header     = str_getcsv(trim($headerLine));
            $stTidIdx   = array_search('trip_id', $header);
            $stDepIdx   = array_search('departure_time', $header);
            $stStopIdx  = array_search('stop_id', $header);

            while (($line = fgets($stream)) !== false) {
                $line = trim($line);
                if (!$line) continue;
                // Fast string check before CSV parse
                if (strpos($line, ',' . $stratfordStop . ',') === false) continue;
                $cols  = str_getcsv($line);
                if (($cols[$stStopIdx] ?? '') !== $stratfordStop) continue;
                $sname = $tripShortNames[$cols[$stTidIdx] ?? ''] ?? '';
                if (!$sname) continue;
                [$h, $m, $s] = explode(':', $cols[$stDepIdx]);
                $secs = (int)$h * 3600 + (int)$m * 60 + (int)$s;
                $depTimeToName[$secs] = $sname;
            }
            fclose($stream);
            $zip->close();

            Cache::put('metro_north_stratford_schedule', $depTimeToName, 86400);
            return $depTimeToName;
        } finally {
            if (file_exists($tmpFile)) unlink($tmpFile);
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
