<?php
/** Render de vistas PHP (views/*.php) con escapado: las vistas reciben $e(string) para imprimir y NUNCA hacen echo de datos sin escapar. */

declare(strict_types=1);

namespace Captacion;

final class View
{
    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Devuelve el HTML de la vista dentro del layout (o solo la vista con $layout = false). */
    public static function render(string $view, array $vars = [], bool $layout = true): string
    {
        $render = static function (string $__file, array $__vars): string {
            $e = [self::class, 'e'];
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
            } catch (\Throwable $t) {
                ob_end_clean();
                throw $t;
            }
            return (string) ob_get_clean();
        };
        $content = $render(CAPTACION_ROOT . '/views/' . $view . '.php', $vars);
        if (!$layout) {
            return $content;
        }
        return $render(CAPTACION_ROOT . '/views/layout.php', $vars + ['content' => $content]);
    }
}
