<?php
/** Una consulta con tope de tiempo (Db::withTimeout) tardo mas de lo permitido. La pantalla avisa y ofrece reintentar o acotar con un filtro. */

declare(strict_types=1);

namespace Captacion;

final class QueryTimeout extends \RuntimeException
{
}
