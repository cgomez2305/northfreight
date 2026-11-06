<?php
/**
 * Paleta de comandos (Q5a-F): el catalogo de pantallas, vistas y acciones que la barra superior ofrece con Ctrl/⌘ K. Se arma en el servidor segun el ROL: solo los administradores
 * reciben algo (el lector de NorthStar no tiene paleta ni buscador). Las acciones sobre un lead (mover, registrar llamada, nota, «respondio») las completa el navegador
 * cuando hay un lead abierto en la ficha o en el vistazo; aqui solo se declara cuales existen. Sin datos personales: solo nombres de pantallas y de vistas.
 */

declare(strict_types=1);

namespace Captacion\Services;

final class Commands
{
    /** @return array{screens:list<array{0:string,1:string}>,views:list<array{0:string,1:string}>,actions:list<array{0:string,1:string}>,leadActions:list<array{0:string,1:string}>}|array{} */
    public static function forUser(array $user, array $views): array
    {
        if (($user['role'] ?? '') !== 'admin') {
            return [];
        }
        $screens = [['Dashboard', '/'], ['Leads · Lista', '/leads'], ['Pipeline · Tablero', '/pipeline'], ['Leads · Tabla', '/tabla'], ['Leads · Calendario', '/calendario'], ['Leads · Cronología', '/cronologia'], ['Tareas', '/tareas'], ['Prospectos', '/prospectos'], ['Referidos', '/referidos'], ['Vacantes', '/vacantes'],
            ['Reportes', '/reportes'], ['Informes', '/informes'], ['Comunicaciones', '/comunicaciones'], ['Configuración', '/config'], ['Actividad', '/actividad']];
        $v = [];
        foreach ($views as $x) {
            $v[] = [(string) $x['name'], (string) $x['href']];
        }
        return [
            'screens' => $screens,
            'views' => $v,
            'actions' => [['new_lead', 'Nuevo lead'], ['new_task', 'Nueva tarea'], ['theme_light', 'Tema claro'], ['theme_dark', 'Tema oscuro'], ['theme_auto', 'Tema automático'], ['keys', 'Atajos de teclado'], ['side', 'Contraer o expandir la barra lateral'],
                ['go_tabla', 'Ir a Tabla'], ['go_calendar', 'Ir a Calendario'], ['go_timeline', 'Ir a Cronología'], ['rules_go', 'Ir a Reglas'], ['rules_new', 'Nueva regla'], ['rules_sim', 'Simular reglas']],
            'leadActions' => [['call', 'Registrar llamada'], ['note', 'Añadir nota'], ['task', 'Añadir tarea'], ['responded', 'Marcar respondió']],
        ];
    }
}
