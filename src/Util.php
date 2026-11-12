<?php

namespace GlpiPlugin\Hrvacation;

/**
 * Utilitários internos do plugin.
 */
class Util
{
    /**
     * Escapa um valor para uso dentro de HTML (atributos e conteúdo de
     * textarea).
     *
     * Substitui Html::cleanInputText(), removido no GLPI 12. Não depende de
     * nenhuma função do núcleo, então continua válido nas próximas versões.
     *
     * @param mixed $value
     * @return string
     */
    public static function esc($value)
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
