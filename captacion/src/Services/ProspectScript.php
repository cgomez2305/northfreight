<?php
/**
 * Guion de las llamadas en frío (fase AU). Se ve dentro de cada prospecto y en el Modo llamada, en inglés y en español, con las variables del prospecto ya puestas.
 * Se edita en Configuración > Prospectos (ajuste `prospects.script` = {en, es}); vacío = el de fábrica.
 *
 * Reglas del texto de fábrica (CLAUDE.md §8.2 y docs/prospeccion-en-frio.md): presentarse con nombre y empresa, ofrecer inglés o español (fase AW; nunca adivinarlo por el apellido), decir que NO se llama de FMCSA ni del gobierno, pedir
 * permiso para seguir, afirmar solo lo que NorthStar hace hoy (Amazon Relay y dry van, MC nuevos, dispatch 24/7), precios como ESTIMACIÓN (fase AN), y quien pida que
 * no lo llamen se marca «No llamar más» antes de colgar.
 *
 * Formato: «# Título» abre una sección, «- » hace una lista, el resto son párrafos. Variables: {empresa} {ciudad} {estado} {flota} {mc} {agente} {whatsapp} {web}.
 */

declare(strict_types=1);

namespace Captacion\Services;

final class ProspectScript
{
    public const LANGS = ['en' => 'English', 'es' => 'Español'];
    public const VARS = ['{empresa}' => 'Razón social', '{ciudad}' => 'Ciudad', '{estado}' => 'Estado', '{flota}' => 'Flota', '{mc}' => 'Número MC', '{agente}' => 'Tu nombre', '{whatsapp}' => 'WhatsApp de NorthStar', '{web}' => 'Sitio web'];
    public const MAX = 6000;

    public const DEFAULTS = [
        'en' => "# Opening\nHi, am I speaking with the owner of {empresa}? This is {agente} with NorthStar Freight Solutions, a truck dispatch company. I'm not calling from FMCSA or any government office. Do you have a minute?\nDo you prefer English or Spanish?\n[Anota la respuesta en «Datos de la llamada»: los correos salen en ese idioma.]\n"
            . "# Why I'm calling\nWe saw your authority in {estado}. We dispatch Amazon Relay and dry van loads for owner-operators and small fleets, and we work with new MC numbers. Many brokers ask for some months of authority before they give loads, so the first weeks can be slow: we help you get moving with Amazon Relay and dry van. We book the loads, follow every trip and keep the paperwork organized: rate confirmations, BOLs and PODs.\n"
            . "# Questions\n- What equipment do you run: dry van, reefer, flatbed, box truck?\n- Do you have an Amazon Relay account?\n- Are you working with a dispatcher right now?\n- Which lanes or states do you like to run?\n- What is the best email and time to reach you?\n"
            . "# If they ask about price\nAmazon Relay: a flat fee per truck, about \$20 to \$40 depending on how many trucks you run. Dry van: about 5% of the truck's weekly gross. These are estimates, not fixed prices. Billing is weekly and there is no long contract: to cancel you just pay the last week.\n"
            . "# Objections\n- \"I already have a dispatcher.\" No problem. Can I email you our info in case anything changes?\n- \"Is this a scam?\" Fair question. We will never ask for your FMCSA password or bank details on a call. You can check us at {web} and write to us on WhatsApp at {whatsapp}.\n- \"Not interested.\" Thanks for your time. Mark: Not interested.\n- \"Don't call me again.\" Sorry about that, I'm taking you off our list right now. Mark: No llamar más, before hanging up.\n"
            . "# Voicemail (under 30 seconds)\nHi, this is {agente} with NorthStar Freight Solutions. We dispatch Amazon Relay and dry van loads for new carriers like {empresa}. Call or WhatsApp us at {whatsapp}, or visit {web}. Thank you.",
        'es' => "# Saludo\nHola, ¿hablo con el dueño de {empresa}? Soy {agente}, de NorthStar Freight Solutions, una empresa de dispatch de camiones. No llamo de FMCSA ni de ninguna oficina del gobierno. ¿Tiene un minuto?\n¿Prefiere que hablemos en inglés o en español?\n[Anota la respuesta en «Datos de la llamada»: los correos salen en ese idioma.]\n"
            . "# Por qué llamo\nVimos su autoridad en {estado}. Hacemos dispatch de cargas de Amazon Relay y dry van para owner-operators y flotas pequeñas, y trabajamos con MC nuevos. Muchos brókers piden varios meses de autoridad antes de dar cargas, así que las primeras semanas pueden ser lentas: le ayudamos a arrancar con Amazon Relay y dry van. Reservamos las cargas, seguimos cada viaje y llevamos el papeleo en orden: rate confirmations, BOL y POD.\n"
            . "# Preguntas\n- ¿Qué equipo maneja: dry van, reefer, flatbed, box truck?\n- ¿Tiene cuenta de Amazon Relay?\n- ¿Trabaja ahora con algún dispatcher?\n- ¿Qué rutas o estados prefiere?\n- ¿Cuál es el mejor correo y horario para contactarlo?\n"
            . "# Si pregunta el precio\nAmazon Relay: una tarifa fija por camión, de unos \$20 a \$40 según cuántos camiones maneje. Dry van: alrededor del 5 % del gross semanal del camión. Son estimaciones, no precios fijos. Se cobra cada semana y no hay contrato largo: para cancelar solo paga la última semana.\n"
            . "# Objeciones\n- «Ya tengo dispatcher.» No hay problema. ¿Le puedo mandar nuestra información por correo por si algo cambia?\n- «¿Esto es una estafa?» Buena pregunta. Nunca le vamos a pedir su contraseña de FMCSA ni datos bancarios por teléfono. Puede vernos en {web} y escribirnos por WhatsApp al {whatsapp}.\n- «No me interesa.» Gracias por su tiempo. Marca: No interesado.\n- «No me vuelva a llamar.» Disculpe, lo saco de nuestra lista ahora mismo. Marca: No llamar más, antes de colgar.\n"
            . "# Mensaje de voz (menos de 30 segundos)\nHola, le habla {agente}, de NorthStar Freight Solutions. Hacemos dispatch de Amazon Relay y dry van para carriers nuevos como {empresa}. Llámenos o escríbanos por WhatsApp al {whatsapp}, o visite {web}. Gracias.",
    ];

    /** @return array{en:string,es:string,lang:string} textos vigentes (los guardados o los de fábrica) y el idioma que se abre primero */
    public static function get(): array
    {
        $s = Settings::get('prospects.script', []);
        $s = is_array($s) ? $s : [];
        $out = ['lang' => ($s['lang'] ?? 'en') === 'es' ? 'es' : 'en'];
        foreach (array_keys(self::LANGS) as $l) {
            $t = trim((string) ($s[$l] ?? ''));
            $out[$l] = $t !== '' ? $t : self::DEFAULTS[$l];
        }
        return $out;
    }

    /** Guarda los textos (vacío = vuelve al de fábrica). @return array{ok:bool,msg:string} */
    public static function save(array $in, int $userId): array
    {
        $clean = ['lang' => ($in['lang'] ?? 'en') === 'es' ? 'es' : 'en'];
        foreach (array_keys(self::LANGS) as $l) {
            $t = trim(str_replace("\r\n", "\n", (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', mb_scrub(is_string($in[$l] ?? null) ? $in[$l] : ''))));
            if (mb_strlen($t) > self::MAX) {
                return ['ok' => false, 'msg' => 'El guion en ' . self::LANGS[$l] . ' pasa de ' . self::MAX . ' caracteres.'];
            }
            $clean[$l] = $t === self::DEFAULTS[$l] ? '' : $t;
        }
        $before = Settings::get('prospects.script', []);
        Settings::set('prospects.script', $clean);
        ActivityLog::record('prospect_script_save', $userId, 'settings', null, ['en' => $clean['en'] === '' ? 'fábrica' : 'propio', 'es' => $clean['es'] === '' ? 'fábrica' : 'propio', 'lang' => $clean['lang'], 'changed' => $before !== $clean]);
        return ['ok' => true, 'msg' => 'Guion guardado.'];
    }

    /** @return array<string,string> */
    public static function vars(array $p, string $agent): array
    {
        $v = ProspectMail::vars($p);
        return [
            '{empresa}' => $v['{empresa}'] === 'your company' ? 'your company' : $v['{empresa}'], '{ciudad}' => $v['{ciudad}'], '{estado}' => isset(UsStates::NAMES[(string) ($p['state'] ?? '')]) ? UsStates::NAMES[(string) $p['state']] : $v['{estado}'],
            '{flota}' => $v['{flota}'], '{mc}' => (string) ($p['mc_number'] ?? '') !== '' ? (string) $p['mc_number'] : 'MC', '{agente}' => $agent !== '' ? $agent : 'NorthStar',
            '{whatsapp}' => Templates::WHATSAPP, '{web}' => 'northstarfreightsolutions.us',
        ];
    }

    /** Texto del guion a HTML (escapado): secciones, listas y párrafos. */
    public static function html(string $text, array $vars): string
    {
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $out = '';
        $list = [];
        $endList = static function () use (&$list, &$out): void {
            if ($list) {
                $out .= '<ul>' . implode('', $list) . '</ul>';
                $list = [];
            }
        };
        foreach (explode("\n", strtr($text, $vars)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '# ')) {
                $endList();
                $out .= '<h4>' . $e(substr($line, 2)) . '</h4>';
            } elseif (preg_match('/^[-•]\s+(.+)$/u', $line, $m) === 1) {
                $list[] = '<li>' . $e($m[1]) . '</li>';
            } else {
                $endList();
                $out .= '<p>' . $e($line) . '</p>';
            }
        }
        $endList();
        return $out;
    }
}
