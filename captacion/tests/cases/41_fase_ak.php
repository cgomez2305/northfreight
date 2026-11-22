<?php
declare(strict_types=1);

use Captacion\Clock;
use Captacion\Db;
use Captacion\Services\Queue;
use Captacion\Services\Settings;
use CaptacionTests\T;

/** Fase AK: regresiones de la revision del CRM (cola con jobs que matan el proceso, ajustes ausentes en cache). */
return static function (array $ctx): void {
    T::reset(true);
    // un job «running» viejo con demasiados intentos se abandona (failed); uno con pocos se recupera (pending)
    $old = Clock::shift(-Queue::STALE_AFTER - 60);
    Db::run("INSERT INTO jobs (type, payload, status, attempts, started_at, run_at) VALUES ('x_muerto', '{}', 'running', ?, ?, ?)", [count(Queue::BACKOFF) + 1, $old, $old]);
    Db::run("INSERT INTO jobs (type, payload, status, attempts, started_at, run_at) VALUES ('x_vivo', '{}', 'running', 1, ?, ?)", [$old, $old]);
    Queue::recoverStale();
    T::eq(Db::value("SELECT status FROM jobs WHERE type = 'x_muerto'"), 'failed', 'recoverStale: un job que mata el proceso en cada intento queda failed');
    T::eq(Db::value("SELECT status FROM jobs WHERE type = 'x_vivo'"), 'pending', 'recoverStale: un job con pocos intentos vuelve a pending');
    Db::run("DELETE FROM jobs WHERE type IN ('x_muerto', 'x_vivo')");
    // Settings: una clave ausente se recuerda y aparece al guardarla
    Settings::clear();
    T::eq(Settings::get('zz.ausente', 'def'), 'def', 'Settings: ausente devuelve el valor por defecto');
    Settings::set('zz.ausente', 'ya');
    T::eq(Settings::get('zz.ausente', 'def'), 'ya', 'Settings: guardar una clave antes ausente la hace visible');
    Db::run("DELETE FROM settings WHERE `key` = 'zz.ausente'");
    Settings::clear();
};
