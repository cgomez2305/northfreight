<?php
/**
 * Plantillas de fabrica (EN y ES) de las secuencias A6 (tibios) y A12 (referidos). Se siembran en `email_templates` la primera vez (Templates::ensure) y se pueden restaurar.
 *
 * REGLA: nada que NorthStar no haya confirmado. Ni cifras, ni garantias, ni tiempos de respuesta, ni tarifas, ni casos inventados. Donde hace falta un hecho que no tenemos hay un
 * MARCADOR [[CONFIRMAR: …]]: una plantilla con un marcador sin resolver NO se envia (la secuencia espera y el panel avisa) hasta que Carlos lo reemplace por texto real.
 * Variables: {nombre} {equipo} {estado} {link_baja} (recordatorios de cita: {cita_fecha} {cita_enlace}) (el pie con la direccion fisica y la baja se añade siempre aparte) y {link_calculadora}, {whatsapp}.
 */

declare(strict_types=1);

namespace Captacion\Services;

final class TemplateDefaults
{
    /** @return list<array{tkey:string,lang:string,name:string,subject:string,body:string}> */
    public static function all(): array
    {
        return [
            // ---------------------------------------------------------------- A6 día 1: caso real (BLOQUEADA hasta que haya un caso aprobado)
            ['tkey' => 'a6_d1', 'lang' => 'en', 'name' => 'A6 · Día 1 · Caso real', 'subject' => 'How a dispatch desk works on a real load',
                'body' => "Hi {nombre},\n\nThanks for reaching out to NorthStar about dispatch for your {equipo} operation.\n\nHere is what working with a desk looks like on a real load:\n\n[[CONFIRMAR: caso real aprobado por NorthStar, sin cifras ni garantías sin respaldo]]\n\nIf you want to talk it through, just reply to this email or message us on WhatsApp: {whatsapp}.\n\nNorthStar Freight Solutions"],
            ['tkey' => 'a6_d1', 'lang' => 'es', 'name' => 'A6 · Día 1 · Caso real', 'subject' => 'Cómo trabaja una mesa de dispatch en una carga real',
                'body' => "Hola {nombre},\n\nGracias por escribir a NorthStar sobre el dispatch de tu operación con {equipo}.\n\nAsí se ve trabajar con una mesa de dispatch en una carga real:\n\n[[CONFIRMAR: caso real aprobado por NorthStar, sin cifras ni garantías sin respaldo]]\n\nSi quieres hablarlo, responde a este correo o escríbenos por WhatsApp: {whatsapp}.\n\nNorthStar Freight Solutions"],
            // ---------------------------------------------------------------- A6 día 3: calculadora de RPM (lista, el sitio ya la tiene)
            ['tkey' => 'a6_d3', 'lang' => 'en', 'name' => 'A6 · Día 3 · Calculadora de RPM', 'subject' => 'Does the load pay? Run the numbers first',
                'body' => "Hi {nombre},\n\nBefore you accept your next load, it helps to know what it really pays per mile once fuel and deadhead are in.\n\nWe built a free rate per mile calculator for that:\n{link_calculadora}\n\nIt gives an estimate based on the numbers you type in, nothing more. Use it with any load, whether you work with us or not.\n\nQuestions? Reply here or message us on WhatsApp: {whatsapp}.\n\nNorthStar Freight Solutions"],
            ['tkey' => 'a6_d3', 'lang' => 'es', 'name' => 'A6 · Día 3 · Calculadora de RPM', 'subject' => '¿La carga paga? Haz las cuentas primero',
                'body' => "Hola {nombre},\n\nAntes de aceptar tu próxima carga, conviene saber cuánto paga de verdad por milla una vez que entran el combustible y el deadhead.\n\nHicimos una calculadora gratuita de rate per mile para eso:\n{link_calculadora}\n\nDa una estimación con los números que escribas, nada más. Úsala con cualquier carga, trabajes con nosotros o no.\n\n¿Preguntas? Responde aquí o escríbenos por WhatsApp: {whatsapp}.\n\nNorthStar Freight Solutions"],
            // ---------------------------------------------------------------- A6 día 7: tarifas de la semana (NO SE ENVÍA: no existe una fuente real de tarifas; el paso se omite)
            ['tkey' => 'a6_d7', 'lang' => 'en', 'name' => 'A6 · Día 7 · Tarifas de la semana (omitida: sin fuente real)', 'subject' => 'Rates this week',
                'body' => "Hi {nombre},\n\nHere is what we are seeing on rates this week:\n\n[[CONFIRMAR: tarifas de la semana con su fuente real]]\n\nReply to this email if you want help with a lane.\n\nNorthStar Freight Solutions"],
            ['tkey' => 'a6_d7', 'lang' => 'es', 'name' => 'A6 · Día 7 · Tarifas de la semana (omitida: sin fuente real)', 'subject' => 'Tarifas de esta semana',
                'body' => "Hola {nombre},\n\nEsto es lo que vemos en tarifas esta semana:\n\n[[CONFIRMAR: tarifas de la semana con su fuente real]]\n\nResponde a este correo si quieres ayuda con una ruta.\n\nNorthStar Freight Solutions"],
            // ---------------------------------------------------------------- A6 día 14: último intento
            ['tkey' => 'a6_d14', 'lang' => 'en', 'name' => 'A6 · Día 14 · Último intento', 'subject' => 'Still thinking about dispatch?',
                'body' => "Hi {nombre},\n\nThis is my last note for now. If dispatch is still on your mind, you can reply to this email or message us on WhatsApp: {whatsapp}. No forms first.\n\nIf the timing is not right, no problem. You will not hear from us again about this.\n\nNorthStar Freight Solutions"],
            ['tkey' => 'a6_d14', 'lang' => 'es', 'name' => 'A6 · Día 14 · Último intento', 'subject' => '¿Sigues pensando en el dispatch?',
                'body' => "Hola {nombre},\n\nEste es mi último mensaje por ahora. Si el dispatch todavía te interesa, responde a este correo o escríbenos por WhatsApp: {whatsapp}. Sin formularios primero.\n\nSi no es el momento, no hay problema. No volverás a recibir mensajes nuestros sobre esto.\n\nNorthStar Freight Solutions"],
            // ---------------------------------------------------------------- A11: reactivación de leads perdidos hace 60 días o más (BLOQUEADAS hasta que Carlos escriba qué decir)
            ['tkey' => 'a11_react_1', 'lang' => 'en', 'name' => 'A11 · Reactivación · Mensaje 1', 'subject' => 'Still looking for dispatch help?',
                'body' => "Hi {nombre},\n\nA while back you asked NorthStar about dispatch for your {equipo} operation, and we did not end up working together.\n\nIf things have changed and you want to talk again, reply to this email or message us on WhatsApp: {whatsapp}. No forms first.\n\n[[CONFIRMAR: qué ha cambiado en NorthStar o qué puede ofrecer hoy, sin cifras, garantías ni promesas]]\n\nNorthStar Freight Solutions"],
            ['tkey' => 'a11_react_1', 'lang' => 'es', 'name' => 'A11 · Reactivación · Mensaje 1', 'subject' => '¿Sigues buscando ayuda con el dispatch?',
                'body' => "Hola {nombre},\n\nHace un tiempo preguntaste a NorthStar por el dispatch de tu operación con {equipo} y al final no trabajamos juntos.\n\nSi algo cambió y quieres hablar de nuevo, responde a este correo o escríbenos por WhatsApp: {whatsapp}. Sin formularios primero.\n\n[[CONFIRMAR: qué ha cambiado en NorthStar o qué puede ofrecer hoy, sin cifras, garantías ni promesas]]\n\nNorthStar Freight Solutions"],
            ['tkey' => 'a11_react_2', 'lang' => 'en', 'name' => 'A11 · Reactivación · Mensaje 2 (último)', 'subject' => 'One last note from NorthStar',
                'body' => "Hi {nombre},\n\nThis is my last note about this. If dispatch is back on your list, reply here or message us on WhatsApp: {whatsapp}.\n\n[[CONFIRMAR: cierre del mensaje, sin cifras ni promesas]]\n\nIf not, no problem. You will not hear from us again about this.\n\nNorthStar Freight Solutions"],
            ['tkey' => 'a11_react_2', 'lang' => 'es', 'name' => 'A11 · Reactivación · Mensaje 2 (último)', 'subject' => 'Un último mensaje de NorthStar',
                'body' => "Hola {nombre},\n\nEste es mi último mensaje sobre esto. Si el dispatch vuelve a estar en tu lista, responde aquí o escríbenos por WhatsApp: {whatsapp}.\n\n[[CONFIRMAR: cierre del mensaje, sin cifras ni promesas]]\n\nSi no, no hay problema. No volverás a recibir mensajes nuestros sobre esto.\n\nNorthStar Freight Solutions"],
            // ---------------------------------------------------------------- A5: recordatorios de cita por correo (24 h y 1 h antes)
            ['tkey' => 'appt_reminder_24h', 'lang' => 'en', 'name' => 'A5 · Recordatorio de cita · 24 h antes', 'subject' => 'Reminder: your call with NorthStar',
                'body' => "Hi {nombre},\n\nThis is a reminder of your call with NorthStar:\n\n{cita_fecha}\n\nLink: {cita_enlace}\n\nNeed to change it? Use the link in your Cal.com confirmation email, or message us on WhatsApp: {whatsapp}.\n\nNorthStar Freight Solutions"],
            ['tkey' => 'appt_reminder_24h', 'lang' => 'es', 'name' => 'A5 · Recordatorio de cita · 24 h antes', 'subject' => 'Recordatorio: tu llamada con NorthStar',
                'body' => "Hola {nombre},\n\nTe recordamos tu llamada con NorthStar:\n\n{cita_fecha}\n\nEnlace: {cita_enlace}\n\n¿Necesitas cambiarla? Usa el enlace del correo de confirmación de Cal.com o escríbenos por WhatsApp: {whatsapp}.\n\nNorthStar Freight Solutions"],
            ['tkey' => 'appt_reminder_1h', 'lang' => 'en', 'name' => 'A5 · Recordatorio de cita · 1 h antes', 'subject' => 'Your call with NorthStar is coming up',
                'body' => "Hi {nombre},\n\nYour call with NorthStar is coming up:\n\n{cita_fecha}\n\nLink: {cita_enlace}\n\nSee you there.\n\nNorthStar Freight Solutions"],
            ['tkey' => 'appt_reminder_1h', 'lang' => 'es', 'name' => 'A5 · Recordatorio de cita · 1 h antes', 'subject' => 'Tu llamada con NorthStar es pronto',
                'body' => "Hola {nombre},\n\nTu llamada con NorthStar es pronto:\n\n{cita_fecha}\n\nEnlace: {cita_enlace}\n\nHasta ahora.\n\nNorthStar Freight Solutions"],
            // ---------------------------------------------------------------- A12: pedido de referidos a los 30 días de firmar
            ['tkey' => 'a12_referral', 'lang' => 'en', 'name' => 'A12 · Pedido de referidos (30 días tras firmar)', 'subject' => 'Do you know another owner-operator?',
                'body' => "Hi {nombre},\n\nIt has been a month since you started with NorthStar. Thanks for trusting us with your loads.\n\nIf you know another owner-operator or small fleet that could use a dispatch desk, you can share your personal link: {link_referido}\n\nOr just reply to this email with their name and phone number and we will reach out. No pressure, and only if you think it would help them.\n\nNorthStar Freight Solutions"],
            ['tkey' => 'a12_referral', 'lang' => 'es', 'name' => 'A12 · Pedido de referidos (30 días tras firmar)', 'subject' => '¿Conoces a otro owner-operator?',
                'body' => "Hola {nombre},\n\nHa pasado un mes desde que empezaste con NorthStar. Gracias por confiarnos tus cargas.\n\nSi conoces a otro owner-operator o flota pequeña que pueda usar una mesa de dispatch, puedes compartir tu enlace personal: {link_referido}\n\nO responde a este correo con su nombre y teléfono y los contactamos. Sin presión, y solo si crees que le ayudaría.\n\nNorthStar Freight Solutions"],
        ];
    }
}
