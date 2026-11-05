<?php
/**
 * Cliente de solo lectura de la API SODA de data.transportation.gov (Socrata). Sin llave funciona (con limite compartido por IP); SOCRATA_APP_TOKEN opcional
 * (cabecera X-App-Token) sube ese limite. SOCRATA_API_BASE existe solo para las pruebas y para cambiar de portal. Reintenta 429 y 5xx con espera creciente (maximo 3
 * intentos) y respeta Retry-After hasta 20 s. Cliente HTTP inyectable: callable(string $url, array $headers): array{status:int, body:string, error?:string, retry_after?:int}.
 * Nunca lanza otra cosa que SocrataUnavailable.
 */

declare(strict_types=1);

namespace Captacion\Services;

use Captacion\Env;

final class SocrataClient
{
    public const DEFAULT_BASE = 'https://data.transportation.gov';

    /** @var callable|null */
    private $http;
    /** @var callable */
    private $sleep;
    public int $requests = 0;

    public function __construct(?callable $http = null, ?callable $sleep = null, private ?string $base = null, private ?string $token = null)
    {
        $this->http = $http;
        $this->sleep = $sleep ?? static function (float $s): void {
            usleep((int) ($s * 1_000_000));
        };
        $this->base ??= rtrim(Env::get('SOCRATA_API_BASE', self::DEFAULT_BASE) ?? self::DEFAULT_BASE, '/');
        $this->token ??= Env::get('SOCRATA_APP_TOKEN');
    }

    public function hasToken(): bool
    {
        return $this->token !== null && $this->token !== '';
    }

    /**
     * @param array<string,string> $params parametros SoQL ($select, $where, $order, $limit…)
     * @return list<array<string,mixed>>
     * @throws SocrataUnavailable
     */
    public function query(string $dataset, array $params): array
    {
        if (preg_match('/^[a-z0-9]{4}-[a-z0-9]{4}$/', $dataset) !== 1) {
            throw new SocrataUnavailable('Identificador de dataset no valido.');
        }
        $url = $this->base . '/resource/' . $dataset . '.json?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $headers = $this->hasToken() ? ['X-App-Token: ' . $this->token] : [];
        $lastErr = '';
        for ($try = 1; $try <= 3; $try++) {
            $this->requests++;
            try {
                $r = $this->http !== null ? ($this->http)($url, $headers) : Http::request('GET', $url, null, $headers, 25, 6);
            } catch (\Throwable $t) {
                $r = ['status' => 0, 'body' => '', 'error' => $t->getMessage()];
            }
            $status = (int) ($r['status'] ?? 0);
            if ($status === 200) {
                $json = json_decode((string) ($r['body'] ?? ''), true);
                if (!is_array($json) || ($json !== [] && array_keys($json) !== range(0, count($json) - 1))) {
                    throw new SocrataUnavailable('Socrata devolvio una respuesta que no es una lista JSON.');
                }
                return $json;
            }
            if ($status === 429 || $status >= 500 || $status === 0) {
                $lastErr = $status === 0 ? 'sin respuesta (' . mb_substr(Storage::redact((string) ($r['error'] ?? '')), 0, 120) . ')' : 'HTTP ' . $status;
                if ($try < 3) {
                    $wait = $status === 429 && isset($r['retry_after']) ? min(20, max(1, (int) $r['retry_after'])) : 2 * $try;
                    ($this->sleep)((float) $wait);
                }
                continue;
            }
            // 4xx distinto de 429: la consulta esta mal (columna inexistente, dataset retirado…); reintentar no sirve.
            $msg = json_decode((string) ($r['body'] ?? ''), true);
            $detail = is_array($msg) && isset($msg['message']) ? ': ' . mb_substr(preg_replace('/\s+/', ' ', (string) $msg['message']) ?? '', 0, 160) : '';
            throw new SocrataUnavailable('Socrata respondio HTTP ' . $status . $detail, permanent: true);
        }
        throw new SocrataUnavailable('Socrata no respondio bien tras 3 intentos: ' . $lastErr);
    }
}
