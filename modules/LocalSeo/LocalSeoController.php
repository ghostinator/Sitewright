<?php
declare(strict_types=1);

namespace RankMathWebapp\Modules\LocalSeo;

use RankMathWebapp\Core\{Config, Database, FetchException, RateLimiter, Request, Response, SafeHttpClient, Validator};

/**
 * Business locations + LocalBusiness JSON-LD. Geocoding via OpenStreetMap Nominatim is OPT-IN
 * (enable_geocoding / RMW_ENABLE_GEOCODING): when on, the address text you enter is sent to nominatim.openstreetmap.org.
 */
class LocalSeoController
{
    private const FIELDS = ['name', 'address', 'city', 'state', 'country', 'postal_code', 'phone', 'email', 'website', 'latitude', 'longitude', 'business_hours'];
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getLocations(array $params): never
    {
        $projectId = Request::queryInt('project_id', 0, 0, PHP_INT_MAX);
        $rows = $this->db->fetchAll(
            'SELECT * FROM `' . $this->db->t('local_locations') . '`' . ($projectId > 0 ? ' WHERE project_id = :p' : '') . ' ORDER BY created_at DESC, id DESC LIMIT 1000',
            $projectId > 0 ? ['p' => $projectId] : []
        );
        Response::success('Locations retrieved', array_map([$this, 'decode'], $rows));
    }

    public function getLocation(array $params): never
    {
        Response::success('Location retrieved', $this->decode($this->find(Validator::id($params['id'] ?? null))));
    }

    public function addLocation(array $params): never
    {
        $in = Request::json();
        $data = $this->validated($in, true);
        $data['project_id'] = Validator::projectId($in);

        if (($data['latitude'] ?? null) === null || ($data['longitude'] ?? null) === null) {
            $coords = $this->coordinatesFor($data);
            if ($coords) { [$data['latitude'], $data['longitude']] = $coords; }
        }
        $id = $this->db->insert('local_locations', $data);
        Response::success('Location added', ['id' => (int)$id, 'geocoded' => isset($coords) && $coords !== null], 201);
    }

    public function updateLocation(array $params): never
    {
        $id = Validator::id($params['id'] ?? null);
        $data = $this->validated(Request::json(), false);
        if (!$data) { Response::error('No data to update', 400); }
        $this->find($id);
        $this->db->update('local_locations', $data, ['id' => $id]);
        Response::success('Location updated');
    }

    public function deleteLocation(array $params): never
    {
        $n = $this->db->delete('local_locations', ['id' => Validator::id($params['id'] ?? null)]);
        $n ? Response::success('Location deleted') : Response::notFound('Location not found');
    }

    public function getSchema(array $params): never
    {
        $loc = $this->decode($this->find(Validator::id($params['id'] ?? null)));
        $schema = ['@context' => 'https://schema.org', '@type' => 'LocalBusiness', 'name' => $loc['name']];
        if ($loc['address']) {
            $schema['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $loc['address'], 'addressLocality' => $loc['city'],
                'addressRegion' => $loc['state'], 'postalCode' => $loc['postal_code'], 'addressCountry' => $loc['country']];
        }
        if ($loc['latitude'] !== null && $loc['longitude'] !== null) {
            $schema['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float)$loc['latitude'], 'longitude' => (float)$loc['longitude']];
        }
        foreach (['telephone' => 'phone', 'email' => 'email', 'url' => 'website'] as $k => $col) {
            if ($loc[$col]) { $schema[$k] = $loc[$col]; }
        }
        if (is_array($loc['business_hours']) && $loc['business_hours']) {
            $schema['openingHoursSpecification'] = self::formatBusinessHours($loc['business_hours']);
        }
        Response::success('Schema generated', $schema);
    }

    public function searchNearby(array $params): never
    {
        $in = ['lat' => Request::query('lat'), 'lng' => Request::query('lng'), 'radius' => Request::query('radius')];
        $lat = Validator::float($in, 'lat', -90, 90, null, true);
        $lng = Validator::float($in, 'lng', -180, 180, null, true);
        $radius = Validator::float($in, 'radius', 0.001, 500, 10.0);

        // Haversine; every placeholder is used once (native prepares do not allow reuse).
        $t = $this->db->t('local_locations');
        $rows = $this->db->fetchAll(
            "SELECT * FROM (
                SELECT l.*, (6371 * ACOS(LEAST(1, GREATEST(-1,
                    COS(RADIANS(:lat1)) * COS(RADIANS(latitude)) * COS(RADIANS(longitude) - RADIANS(:lng))
                    + SIN(RADIANS(:lat2)) * SIN(RADIANS(latitude)))))) AS distance
                FROM `$t` l WHERE latitude IS NOT NULL AND longitude IS NOT NULL) d
             WHERE distance <= :radius ORDER BY distance LIMIT 100",
            ['lat1' => $lat, 'lat2' => $lat, 'lng' => $lng, 'radius' => $radius]
        );
        Response::success('Nearby locations found', array_map([$this, 'decode'], $rows));
    }

    /** @return array<string,mixed> */
    private function validated(array $in, bool $full): array
    {
        $out = [];
        $str = ['name' => 255, 'address' => 500, 'city' => 100, 'state' => 100, 'country' => 100, 'postal_code' => 20, 'website' => 500];
        foreach ($str as $f => $max) {
            if ($f === 'website') { continue; }
            if ($full || array_key_exists($f, $in)) {
                $v = Validator::str($in, $f, $max, $f === 'name' && $full);
                if ($f === 'name' && $v === null && !$full) { Validator::fail('name', 'cannot be empty'); }
                $out[$f] = $v;
            }
        }
        if ($full || array_key_exists('website', $in)) { $out['website'] = Validator::url($in, 'website', 500, false); }
        if ($full || array_key_exists('phone', $in)) { $out['phone'] = Validator::phone($in, 'phone'); }
        if ($full || array_key_exists('email', $in)) { $out['email'] = Validator::email($in, 'email'); }
        if ($full || array_key_exists('latitude', $in)) { $out['latitude'] = Validator::float($in, 'latitude', -90, 90); }
        if ($full || array_key_exists('longitude', $in)) { $out['longitude'] = Validator::float($in, 'longitude', -180, 180); }
        if (array_key_exists('latitude', $out) && array_key_exists('longitude', $out) && (($out['latitude'] === null) !== ($out['longitude'] === null))) {
            Validator::fail('latitude', 'latitude and longitude must be given together');
        }
        if ($full || array_key_exists('business_hours', $in)) {
            $h = $in['business_hours'] ?? null;
            $out['business_hours'] = $h === null ? null : json_encode(self::validHours($h));
        }
        return $out;
    }

    /** Expected shape: {"monday":{"open":"09:00","close":"17:00"}, ...} */
    private static function validHours(mixed $h): array
    {
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        if (!is_array($h)) { Validator::fail('business_hours', 'must be an object keyed by weekday'); }
        $out = [];
        foreach ($h as $day => $t) {
            if (!in_array($day, $days, true) || !is_array($t)) { Validator::fail('business_hours', 'unknown weekday or bad entry'); }
            foreach (['open', 'close'] as $k) {
                if (!isset($t[$k]) || !is_string($t[$k]) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $t[$k])) { Validator::fail('business_hours', "$day.$k must be HH:MM"); }
            }
            $out[$day] = ['open' => $t['open'], 'close' => $t['close']];
        }
        return $out;
    }

    public static function formatBusinessHours(array $hours): array
    {
        $out = [];
        foreach ($hours as $day => $t) {
            if (is_array($t) && !empty($t['open']) && !empty($t['close'])) {
                $out[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ucfirst((string)$day), 'opens' => $t['open'], 'closes' => $t['close']];
            }
        }
        return $out;
    }

    private function find(int $id): array
    {
        return $this->db->fetchOne('SELECT * FROM `' . $this->db->t('local_locations') . '` WHERE id = :id', ['id' => $id]) ?? Response::notFound('Location not found');
    }

    private function decode(array $row): array
    {
        $h = $row['business_hours'] !== null ? json_decode((string)$row['business_hours'], true) : null;
        $row['business_hours'] = is_array($h) ? $h : null;
        return $row;
    }

    /** Reuse stored coordinates for an identical address, else (only if enabled) ask Nominatim. @return array{0:float,1:float}|null */
    private function coordinatesFor(array $d): ?array
    {
        $address = implode(', ', array_filter([$d['address'] ?? '', $d['city'] ?? '', $d['state'] ?? '', $d['postal_code'] ?? '', $d['country'] ?? '']));
        if ($address === '') { return null; }
        $t = $this->db->t('local_locations');
        $hit = $this->db->fetchOne("SELECT latitude, longitude FROM `$t` WHERE latitude IS NOT NULL AND address <=> :a AND city <=> :c AND state <=> :s AND postal_code <=> :p AND country <=> :k LIMIT 1",
            ['a' => $d['address'] ?? null, 'c' => $d['city'] ?? null, 's' => $d['state'] ?? null, 'p' => $d['postal_code'] ?? null, 'k' => $d['country'] ?? null]);
        if ($hit) { return [(float)$hit['latitude'], (float)$hit['longitude']]; }
        if (!Config::get('enable_geocoding')) { return null; }
        if (!RateLimiter::hit('geocode:global', 1, 1)['allowed']) { return null; } // Nominatim policy: max 1 request/second

        try {
            $res = SafeHttpClient::default()->get(
                'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . rawurlencode($address),
                ['max_bytes' => 262144, 'timeout' => 8, 'accept' => 'application/json']
            );
        } catch (FetchException) {
            return null;
        }
        $json = json_decode($res['body'], true);
        if ($res['status'] !== 200 || empty($json[0]['lat']) || empty($json[0]['lon'])) { return null; }
        $lat = (float)$json[0]['lat']; $lng = (float)$json[0]['lon'];
        return abs($lat) <= 90 && abs($lng) <= 180 ? [$lat, $lng] : null;
    }
}
