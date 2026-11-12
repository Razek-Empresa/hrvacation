<?php

namespace GlpiPlugin\Hrvacation;

/**
 * A conta (ou caixa de correio) não existe no destino.
 *
 * Não é uma falha temporária: tentar de novo não resolve. A automação registra
 * uma única vez que não há nada a fazer, sem novas tentativas.
 */
class NotFoundException extends \RuntimeException
{
}
