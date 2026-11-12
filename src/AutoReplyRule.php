<?php

namespace GlpiPlugin\Hrvacation;

/**
 * Regra da resposta automática de ausência, lida da configuração do plugin.
 *
 * Fonte única do cálculo: a execução, a simulação (dry-run) e o resumo
 * exibido na tela de configuração usam estes mesmos métodos, então o que
 * aparece na tela é exatamente o que será aplicado.
 */
class AutoReplyRule
{
    const TIMEZONES = [
        'E. South America Standard Time'   => 'Brasília (UTC-3)',
        'Central Brazilian Standard Time'  => 'Cuiabá (UTC-4)',
        'SA Western Standard Time'         => 'Manaus (UTC-4)',
        'SA Pacific Standard Time'         => 'Rio Branco (UTC-5)',
    ];

    const AUDIENCES = [
        'none'         => 'Somente pessoas da empresa',
        'contactsOnly' => 'Pessoas da empresa e contatos externos conhecidos',
        'all'          => 'Todos, inclusive remetentes externos',
    ];

    const ON_UNBLOCK = [
        'keep'    => 'Manter até o encerramento agendado',
        'disable' => 'Desligar ao abrir o chamado de liberação',
    ];

    /**
     * Normaliza a configuração, aplicando os padrões.
     */
    public static function fromConfig(array $c)
    {
        $tz = (string) ($c['autoreply_timezone'] ?? '');
        $au = (string) ($c['autoreply_audience'] ?? '');
        $un = (string) ($c['autoreply_on_unblock'] ?? '');

        return [
            'start_hour' => max(0, min(23, (int) ($c['autoreply_start_hour'] ?? 0))),
            'end_hour'   => max(0, min(23, (int) ($c['autoreply_end_hour'] ?? 0))),
            'timezone'   => isset(self::TIMEZONES[$tz]) ? $tz : 'E. South America Standard Time',
            'audience'   => isset(self::AUDIENCES[$au]) ? $au : 'none',
            'on_unblock' => isset(self::ON_UNBLOCK[$un]) ? $un : 'keep',
            'on_delete'  => !array_key_exists('autoreply_on_delete', $c) || !empty($c['autoreply_on_delete']),
            'overwrite'  => !array_key_exists('autoreply_overwrite', $c) || !empty($c['autoreply_overwrite']),
        ];
    }

    /** Dia do retorno: o dia seguinte ao último dia do afastamento. */
    public static function returnDate($date_end)
    {
        return date('Y-m-d', strtotime($date_end . ' +1 day'));
    }

    /**
     * Janela da mensagem para um afastamento.
     *
     * @return array ['start' => 'Y-m-d H:i', 'end' => 'Y-m-d H:i']
     */
    public static function window(array $rule, $date_start, $date_end)
    {
        return [
            'start' => sprintf('%s %02d:00', $date_start, $rule['start_hour']),
            'end'   => sprintf('%s %02d:00', self::returnDate($date_end), $rule['end_hour']),
        ];
    }

    /** O encerramento agendado já passou? */
    public static function endPassed(array $rule, $date_end)
    {
        $w = self::window($rule, $date_end, $date_end);
        return date('Y-m-d H:i') >= $w['end'];
    }

    public static function fmt($datetime)
    {
        return date('d/m/Y \à\s H\h', strtotime($datetime));
    }

    /**
     * Resumo em linguagem simples, com um afastamento de exemplo.
     *
     * @return string[] linhas do resumo
     */
    public static function describe(array $rule)
    {
        $ex_start = date('Y-m-d', strtotime('first day of next month'));
        $ex_end   = date('Y-m-d', strtotime($ex_start . ' +19 days'));
        $w        = self::window($rule, $ex_start, $ex_end);

        $lines = [];
        $lines[] = sprintf(
            'Exemplo: afastamento de %s a %s (retorno em %s).',
            date('d/m', strtotime($ex_start)),
            date('d/m', strtotime($ex_end)),
            date('d/m', strtotime(self::returnDate($ex_end)))
        );
        $lines[] = sprintf(
            'Ao abrir o chamado de bloqueio, a mensagem é agendada de %s até %s, horário de %s.',
            self::fmt($w['start']),
            self::fmt($w['end']),
            self::TIMEZONES[$rule['timezone']]
        );
        $lines[] = 'Quem recebe: ' . mb_strtolower(self::AUDIENCES[$rule['audience']]) . '.';
        $lines[] = $rule['on_unblock'] === 'keep'
            ? sprintf('No chamado de liberação, a mensagem é mantida e o Exchange a encerra sozinho em %s.', self::fmt($w['end']))
            : 'No chamado de liberação, a mensagem é desligada no mesmo momento.';
        $lines[] = $rule['on_delete']
            ? 'Se o afastamento for excluído, a mensagem é desligada na hora.'
            : 'Se o afastamento for excluído, a mensagem NÃO é desligada (a TI deve desligar manualmente).';
        $lines[] = $rule['overwrite']
            ? 'Se o colaborador já tiver uma resposta automática própria, ela é substituída.'
            : 'Se o colaborador já tiver uma resposta automática própria, ela é mantida e o chamado registra isso.';

        return $lines;
    }
}
