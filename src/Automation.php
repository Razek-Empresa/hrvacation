<?php

namespace GlpiPlugin\Hrvacation;

use CommonDBTM;
use Ticket;
use TicketTask;
use User;
use UserEmail;

/**
 * Suporte à automação de bloqueio/liberação no AD e no Microsoft 365.
 *
 * A execução é feita pelo próprio GLPI: o plugin monta a lista de contas que
 * devem ser bloqueadas ou liberadas, com base nos chamados já abertos, e
 * aplica a mudança via Microsoft Graph (M365) e LDAPS (Active Directory).
 *
 * Controle por destino: cada operação (bloqueio/liberação de um período) é
 * rastreada separadamente para AD e para M365. Uma falha em um destino não
 * impede o outro, e cada destino é retentado de forma independente.
 */
class Automation extends CommonDBTM
{
    public static function getTable($classname = null)
    {
        return 'glpi_plugin_hrvacation_executions';
    }

    const ACT_BLOCK   = 'block';
    const ACT_UNBLOCK = 'unblock';

    /** Após este número de erros, o destino deixa de ser retentado. */
    const MAX_ATTEMPTS = 3;

    public static function getExcludeList(array $config)
    {
        $raw = (string) ($config['automation_exclude'] ?? '');
        $out = [];
        foreach (preg_split('/\r\n|\r|\n|,/', $raw) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }

    /**
     * A conta está na lista de exceção? Aceita login do GLPI ou e-mail,
     * sem diferenciar maiúsculas/minúsculas. Também compara a parte antes do
     * "@" de um e-mail listado com o login (ex.: joao@exemplo.com.br ~ joao).
     */
    public static function isExcluded(array $config, $login, $email = '', $upn = '')
    {
        $candidates = array_filter(array_map(
            fn($v) => mb_strtolower(trim((string) $v)),
            [$login, $email, $upn]
        ));
        foreach (self::getExcludeList($config) as $entry) {
            $entry = mb_strtolower($entry);
            if (in_array($entry, $candidates, true)) {
                return true;
            }
            $local = strstr($entry, '@', true);
            if ($local !== false && $local === mb_strtolower(trim((string) $login))) {
                return true;
            }
        }
        return false;
    }

    /**
     * O chamado foi aberto depois da data de corte da automação?
     * Sem data de corte definida, nada é processado (comportamento seguro).
     */
    protected static function ticketAfterCutoff($tickets_id, array $config)
    {
        global $DB;
        static $cache = [];

        $since = (string) ($config['automation_since'] ?? '');
        if ($since === '') {
            return false;
        }
        if (!array_key_exists($tickets_id, $cache)) {
            $row = $DB->request([
                'SELECT' => ['date_creation'],
                'FROM'   => 'glpi_tickets',
                'WHERE'  => ['id' => $tickets_id],
            ])->current();
            $cache[$tickets_id] = $row['date_creation'] ?? null;
        }
        return $cache[$tickets_id] !== null && $cache[$tickets_id] >= $since;
    }

    /**
     * Destinos habilitados na configuração.
     */
    protected static function enabledTargets(array $config)
    {
        $t = [];
        if (!empty($config['automation_ad'])) {
            $t[] = 'ad';
        }
        if (!empty($config['automation_o365'])) {
            $t[] = 'o365';
        }
        if (!empty($config['automation_autoreply'])) {
            $t[] = 'autoreply';
        }
        return $t;
    }

    /**
     * Retorna TODAS as operações pendentes (sem corte). O limite por execução
     * é aplicado pelo worker como disjuntor: se houver mais bloqueios que o
     * limite, ele aborta em vez de executar.
     */
    public static function getPending(array $config, array $only = null)
    {
        global $DB;

        $items   = [];
        $targets = self::enabledTargets($config);
        if ($only !== null) {
            $targets = array_values(array_intersect($targets, $only));
        }
        $dryrun  = !empty($config['automation_dryrun']);

        if (empty($targets)) {
            return $items;
        }

        $iterator = $DB->request([
            'FROM'  => 'glpi_plugin_hrvacation_periods',
            'WHERE' => ['is_deleted' => 0],
        ]);

        foreach ($iterator as $row) {
            $slots = [
                1 => ['block' => 'block_ticket_id', 'unblock' => 'unblock_ticket_id',
                      'start' => 'date_start',      'end'     => 'date_end'],
            ];
            if (!empty($row['is_fracionado'])) {
                foreach ([2, 3] as $n) {
                    if (!empty($row["date_start{$n}"])) {
                        $slots[$n] = [
                            'block'   => "block_ticket_id{$n}",
                            'unblock' => "unblock_ticket_id{$n}",
                            'start'   => "date_start{$n}",
                            'end'     => "date_end{$n}",
                        ];
                    }
                }
            }

            foreach ($slots as $pnum => $f) {
                foreach ([self::ACT_BLOCK => $f['block'], self::ACT_UNBLOCK => $f['unblock']] as $act => $field) {
                    $tid = (int) ($row[$field] ?? 0);
                    if ($tid <= 0) {
                        continue; // chamado ainda não aberto
                    }
                    if (!self::ticketAfterCutoff($tid, $config)) {
                        continue; // chamado anterior à ativação da automação
                    }

                    // Quais destinos ainda precisam desta operação?
                    $need = [];
                    foreach ($targets as $target) {
                        if (!self::isDone((int) $row['id'], $pnum, $act, $target, $dryrun)
                            && !self::exhausted((int) $row['id'], $pnum, $act, $target)) {
                            $need[] = $target;
                        }
                    }
                    if (empty($need)) {
                        continue;
                    }

                    $user = new User();
                    if (!$user->getFromDB((int) $row['users_id'])) {
                        continue;
                    }
                    $login = trim((string) ($user->fields['name'] ?? ''));
                    if ($login === '') {
                        continue;
                    }

                    $email = (string) UserEmail::getDefaultForUser((int) $row['users_id']);

                    $items[] = [
                        'excluded'   => self::isExcluded($config, $login, $email),
                        'period_id'  => (int) $row['id'],
                        'period_num' => $pnum,
                        'action'     => $act,
                        'login'      => $login,
                        'email'      => $email,
                        'targets'    => $need,
                        'ticket_id'  => $tid,
                        'date_start' => $row[$f['start']],
                        'date_end'   => $row[$f['end']],
                        'redirect'   => (int) ($row['users_id_redirect'] ?? 0),
                    ];
                }
            }
        }

        return $items;
    }

    /**
     * Operação concluída para o destino?
     * - 'ok' e 'skipped' sempre contam como concluída.
     * - 'dryrun' só conta enquanto o modo simulação estiver ligado; ao
     *   desligá-lo, a operação passa a ser executada de verdade.
     */
    protected static function isDone($periods_id, $period_num, $action, $target, $dryrun)
    {
        $statuses = ['ok', 'skipped'];
        if ($dryrun) {
            $statuses[] = 'dryrun';
        }
        return countElementsInTable('glpi_plugin_hrvacation_executions', [
            'periods_id' => $periods_id,
            'period_num' => $period_num,
            'action'     => $action,
            'target'     => $target,
            'status'     => $statuses,
        ]) > 0;
    }

    /**
     * Destino esgotou as tentativas (evita repetir erro indefinidamente).
     */
    protected static function exhausted($periods_id, $period_num, $action, $target)
    {
        return countElementsInTable('glpi_plugin_hrvacation_executions', [
            'periods_id' => $periods_id,
            'period_num' => $period_num,
            'action'     => $action,
            'target'     => $target,
            'status'     => 'error',
        ]) >= self::MAX_ATTEMPTS;
    }

    /**
     * Executa as operações pendentes no Microsoft 365 diretamente pelo GLPI.
     * Chamado ao cadastrar um afastamento e a cada rodada da tarefa automática.
     *
     * @return int quantidade de operações processadas
     */
    public static function runM365(array $config, $task = null)
    {
        if (empty($config['automation_enabled']) || empty($config['automation_o365'])) {
            return 0;
        }

        $dryrun = !empty($config['automation_dryrun']);
        if (!$dryrun && !M365::isConfigured($config)) {
            self::log($task, 'M365: credenciais não configuradas; nada executado.');
            return 0;
        }

        $items = self::getPending($config, ['o365']);
        if (empty($items)) {
            return 0;
        }

        // Disjuntor: bloqueios acima do limite indicam problema (ex.: chamados
        // duplicados). Liberações não entram na conta.
        $max    = max(1, (int) ($config['automation_max_per_run'] ?? 5));
        $blocks = count(array_filter($items, fn($i) => $i['action'] === self::ACT_BLOCK));
        if (!$dryrun && $blocks > $max) {
            self::log($task, "M365: DISJUNTOR ACIONADO - {$blocks} bloqueios pendentes excedem o limite de {$max}. Nada executado; verifique os afastamentos.");
            return 0;
        }

        $done = 0;

        foreach ($items as $item) {
            $base = [
                'period_id'  => $item['period_id'],
                'period_num' => $item['period_num'],
                'action'     => $item['action'],
                'target'     => 'o365',
                'ticket_id'  => $item['ticket_id'],
            ];

            // A conta no Microsoft 365 é localizada pelo e-mail do usuário no GLPI.
            $upn = $item['email'];

            if (self::isExcluded($config, $item['login'], $item['email'], $upn)) {
                self::report($base + ['status' => 'skipped', 'message' => 'Conta consta na lista de exceção.']);
                $done++;
                continue;
            }

            $verbo = ($item['action'] === self::ACT_BLOCK) ? 'bloqueada' : 'liberada';

            if ($dryrun) {
                $msg = $upn !== ''
                    ? "SIMULAÇÃO: a conta {$upn} seria {$verbo} no Microsoft 365."
                    : "SIMULAÇÃO: usuário '{$item['login']}' sem e-mail no GLPI; a conta não seria encontrada.";
                self::report($base + ['status' => 'dryrun', 'message' => $msg]);
                $done++;
                continue;
            }

            if ($upn === '') {
                self::report($base + ['status' => 'error',
                    'message' => "Usuário '{$item['login']}' sem e-mail cadastrado no GLPI; cadastre o e-mail e a operação será retentada."]);
                $done++;
                continue;
            }

            try {
                $msg = M365::setAccountEnabled($config, $upn, $item['action'] === self::ACT_UNBLOCK);
                self::report($base + ['status' => 'ok', 'message' => $msg]);
            } catch (NotFoundException $e) {
                self::report($base + ['status' => 'skipped', 'message' => $e->getMessage() . ' Nada a fazer.']);
            } catch (\Throwable $e) {
                self::report($base + ['status' => 'error', 'message' => $e->getMessage()]);
                self::log($task, "M365: erro em {$upn} ({$item['action']}): " . $e->getMessage());
            }
            $done++;
        }

        return $done;
    }

    /**
     * Executa as operações pendentes no Active Directory local (LDAPS).
     * Mesmo padrão do Microsoft 365, com uma diferença: a conexão e a
     * autenticação acontecem uma única vez por rodada. Se falharem, nada é
     * executado e nenhuma tentativa é descontada das operações.
     *
     * @return int quantidade de operações processadas
     */
    public static function runAd(array $config, $task = null)
    {
        if (empty($config['automation_enabled']) || empty($config['automation_ad'])) {
            return 0;
        }

        $dryrun = !empty($config['automation_dryrun']);
        if (!$dryrun && !AdLdap::isConfigured($config)) {
            self::log($task, 'AD: configuração incompleta; nada executado.');
            return 0;
        }

        $items = self::getPending($config, ['ad']);
        if (empty($items)) {
            return 0;
        }

        $max    = max(1, (int) ($config['automation_max_per_run'] ?? 5));
        $blocks = count(array_filter($items, fn($i) => $i['action'] === self::ACT_BLOCK));
        if (!$dryrun && $blocks > $max) {
            self::log($task, "AD: DISJUNTOR ACIONADO - {$blocks} bloqueios pendentes excedem o limite de {$max}. Nada executado; verifique os afastamentos.");
            return 0;
        }

        // Conecta uma única vez antes de processar qualquer item.
        if (!$dryrun) {
            try {
                AdLdap::connect($config);
            } catch (\Throwable $e) {
                self::log($task, 'AD: ' . $e->getMessage() . ' Nenhuma operação executada nesta rodada.');
                return 0;
            }
        }

        $done = 0;
        foreach ($items as $item) {
            $base = [
                'period_id'  => $item['period_id'],
                'period_num' => $item['period_num'],
                'action'     => $item['action'],
                'target'     => 'ad',
                'ticket_id'  => $item['ticket_id'],
            ];

            if (self::isExcluded($config, $item['login'], $item['email'])) {
                self::report($base + ['status' => 'skipped', 'message' => 'Conta consta na lista de exceção.']);
                $done++;
                continue;
            }

            $verbo = ($item['action'] === self::ACT_BLOCK) ? 'bloqueada' : 'liberada';

            if ($dryrun) {
                self::report($base + ['status' => 'dryrun',
                    'message' => "SIMULAÇÃO: a conta {$item['login']} seria {$verbo} no AD."]);
                $done++;
                continue;
            }

            try {
                $msg = AdLdap::setAccountEnabled($config, $item['login'], $item['action'] === self::ACT_UNBLOCK);
                self::report($base + ['status' => 'ok', 'message' => $msg]);
            } catch (NotFoundException $e) {
                self::report($base + ['status' => 'skipped', 'message' => $e->getMessage() . ' Nada a fazer.']);
            } catch (\Throwable $e) {
                self::report($base + ['status' => 'error', 'message' => $e->getMessage()]);
                self::log($task, "AD: erro em {$item['login']} ({$item['action']}): " . $e->getMessage());
            }
            $done++;
        }

        return $done;
    }

    /** Modelo padrão da resposta automática. */
    public static function defaultAutoReplyMessage()
    {
        return "Olá!\n\nEstou ausente e retorno às atividades em {retorno}.\n\n{contato}\n\nObrigado,\n{nome}";
    }

    /**
     * Monta a mensagem a partir do modelo. Os valores são escapados antes de
     * entrar no HTML, e as quebras de linha viram <br>.
     *
     * Marcadores: {nome}, {inicio}, {fim}, {retorno}, {contato}
     */
    public static function buildAutoReply(array $config, array $item)
    {
        $tpl = trim((string) ($config['autoreply_message'] ?? ''));
        if ($tpl === '') {
            $tpl = self::defaultAutoReplyMessage();
        }

        $user = new User();
        $nome = $user->getFromDB((int) ($item['users_id'] ?? 0)) ? $user->getFriendlyName() : $item['login'];

        $contato = '';
        if (!empty($item['redirect'])) {
            $r = new User();
            if ($r->getFromDB((int) $item['redirect'])) {
                $email   = (string) UserEmail::getDefaultForUser((int) $item['redirect']);
                $contato = 'Em caso de necessidade, entre em contato com ' . $r->getFriendlyName()
                    . ($email !== '' ? " ({$email})" : '') . '.';
            }
        }

        $retorno = date('Y-m-d', strtotime($item['date_end'] . ' +1 day'));
        $vals = [
            '{nome}'    => $nome,
            '{inicio}'  => date('d/m/Y', strtotime($item['date_start'])),
            '{fim}'     => date('d/m/Y', strtotime($item['date_end'])),
            '{retorno}' => date('d/m/Y', strtotime($retorno)),
            '{contato}' => $contato,
        ];

        $escaped = htmlspecialchars($tpl, ENT_QUOTES, 'UTF-8');
        foreach ($vals as $k => $v) {
            $escaped = str_replace(htmlspecialchars($k), htmlspecialchars($v, ENT_QUOTES, 'UTF-8'), $escaped);
        }
        // Remove linhas vazias em excesso (ex.: {contato} sem contato definido).
        $escaped = preg_replace("/(\r?\n){3,}/", "\n\n", trim($escaped));

        return ['html' => nl2br($escaped), 'return' => $retorno];
    }

    /**
     * Configura (no bloqueio) ou desliga (na liberação) a resposta automática.
     *
     * @return int quantidade de operações processadas
     */
    public static function runAutoReply(array $config, $task = null)
    {
        if (empty($config['automation_enabled']) || empty($config['automation_autoreply'])) {
            return 0;
        }

        $dryrun = !empty($config['automation_dryrun']);
        if (!$dryrun && !M365::isConfigured($config)) {
            self::log($task, 'Resposta automática: credenciais do Microsoft 365 não configuradas; nada executado.');
            return 0;
        }

        $items = self::getPending($config, ['autoreply']);
        if (empty($items)) {
            return 0;
        }

        $max    = max(1, (int) ($config['automation_max_per_run'] ?? 5));
        $blocks = count(array_filter($items, fn($i) => $i['action'] === self::ACT_BLOCK));
        if (!$dryrun && $blocks > $max) {
            self::log($task, "Resposta automática: DISJUNTOR ACIONADO - {$blocks} configurações pendentes excedem o limite de {$max}. Nada executado.");
            return 0;
        }

        $done = 0;
        foreach ($items as $item) {
            $base = [
                'period_id'  => $item['period_id'],
                'period_num' => $item['period_num'],
                'action'     => $item['action'],
                'target'     => 'autoreply',
                'ticket_id'  => $item['ticket_id'],
            ];
            $upn = $item['email'];

            if (self::isExcluded($config, $item['login'], $item['email'], $upn)) {
                self::report($base + ['status' => 'skipped', 'message' => 'Conta consta na lista de exceção.']);
                $done++;
                continue;
            }

            if ($dryrun) {
                $rule = AutoReplyRule::fromConfig($config);
                $w    = AutoReplyRule::window($rule, $item['date_start'], $item['date_end']);
                if ($item['action'] === self::ACT_BLOCK) {
                    $msg = "SIMULAÇÃO: a resposta automática de {$upn} seria agendada de "
                        . AutoReplyRule::fmt($w['start']) . ' até ' . AutoReplyRule::fmt($w['end']) . '.';
                } elseif ($rule['on_unblock'] === 'keep' && !AutoReplyRule::endPassed($rule, $item['date_end'])) {
                    $msg = "SIMULAÇÃO: a resposta automática de {$upn} seria mantida até " . AutoReplyRule::fmt($w['end']) . '.';
                } else {
                    $msg = "SIMULAÇÃO: a resposta automática de {$upn} seria desligada.";
                }
                if ($upn === '') {
                    $msg = "SIMULAÇÃO: usuário '{$item['login']}' sem e-mail no GLPI; a caixa não seria encontrada.";
                }
                self::report($base + ['status' => 'dryrun', 'message' => $msg]);
                $done++;
                continue;
            }

            if ($upn === '') {
                self::report($base + ['status' => 'error',
                    'message' => "Usuário '{$item['login']}' sem e-mail cadastrado no GLPI; cadastre o e-mail e a operação será retentada."]);
                $done++;
                continue;
            }

            try {
                $rule = AutoReplyRule::fromConfig($config);
                if ($item['action'] === self::ACT_BLOCK) {
                    if (!$rule['overwrite']) {
                        $status = M365::getAutoReplyStatus($config, $upn);
                        if ($status !== 'disabled') {
                            self::report($base + ['status' => 'skipped', 'message' =>
                                "{$upn} já tem uma resposta automática própria ({$status}); mantida conforme a configuração do plugin."]);
                            $done++;
                            continue;
                        }
                    }
                    $m   = self::buildAutoReply($config, $item + ['users_id' => self::periodUser($item['period_id'])]);
                    $w   = AutoReplyRule::window($rule, $item['date_start'], $item['date_end']);
                    $msg = M365::setAutoReply($config, $upn, $w, $m['html'], $rule);
                } else {
                    $w = AutoReplyRule::window($rule, $item['date_start'], $item['date_end']);
                    if (!self::autoReplyWasSet($item['period_id'], $item['period_num'])) {
                        $msg = 'A resposta automática não foi configurada pelo plugin neste afastamento; nada alterado.';
                        self::report($base + ['status' => 'skipped', 'message' => $msg, 'silent' => true]);
                        $done++;
                        continue;
                    }
                    if ($rule['on_unblock'] === 'keep' && !AutoReplyRule::endPassed($rule, $item['date_end'])) {
                        $msg = "Resposta automática de {$upn} mantida; o Exchange a encerra sozinho em "
                            . AutoReplyRule::fmt($w['end']) . '.';
                    } else {
                        $msg = M365::clearAutoReply($config, $upn);
                    }
                }
                self::report($base + ['status' => 'ok', 'message' => $msg]);
            } catch (NotFoundException $e) {
                self::report($base + ['status' => 'skipped', 'message' => $e->getMessage() . ' Nada a fazer.']);
            } catch (\Throwable $e) {
                self::report($base + ['status' => 'error', 'message' => $e->getMessage()]);
                self::log($task, "Resposta automática: erro em {$upn} ({$item['action']}): " . $e->getMessage());
            }
            $done++;
        }

        return $done;
    }

    protected static function autoReplyWasSet($periods_id, $period_num)
    {
        return countElementsInTable('glpi_plugin_hrvacation_executions', [
            'periods_id' => (int) $periods_id, 'period_num' => (int) $period_num,
            'action' => self::ACT_BLOCK, 'target' => 'autoreply', 'status' => 'ok',
        ]) > 0;
    }

    protected static function periodUser($periods_id)
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => ['users_id'],
            'FROM'   => 'glpi_plugin_hrvacation_periods',
            'WHERE'  => ['id' => (int) $periods_id],
        ])->current();
        return (int) ($row['users_id'] ?? 0);
    }

    /**
     * Ao excluir um afastamento: se a resposta automática foi configurada e
     * ainda não foi desligada, desliga agora. Nunca impede a exclusão.
     */
    public static function onPeriodDeleted(array $fields)
    {
        $config = Config::getConfig();
        if (empty($config['automation_enabled']) || empty($config['automation_autoreply'])
            || !empty($config['automation_dryrun']) || !M365::isConfigured($config)
            || !AutoReplyRule::fromConfig($config)['on_delete']) {
            return;
        }

        $pid = (int) ($fields['id'] ?? 0);
        foreach ([1, 2, 3] as $pnum) {
            $was_set = countElementsInTable('glpi_plugin_hrvacation_executions', [
                'periods_id' => $pid, 'period_num' => $pnum, 'action' => self::ACT_BLOCK,
                'target' => 'autoreply', 'status' => 'ok',
            ]) > 0;
            $cleared = countElementsInTable('glpi_plugin_hrvacation_executions', [
                'periods_id' => $pid, 'period_num' => $pnum, 'action' => self::ACT_UNBLOCK,
                'target' => 'autoreply', 'status' => 'ok',
            ]) > 0;
            if (!$was_set || $cleared) {
                continue;
            }

            $suffix = ($pnum === 1) ? '' : (string) $pnum;
            $ticket = (int) ($fields["block_ticket_id{$suffix}"] ?? 0);
            $email  = (string) UserEmail::getDefaultForUser((int) ($fields['users_id'] ?? 0));
            $base   = ['period_id' => $pid, 'period_num' => $pnum, 'action' => self::ACT_UNBLOCK,
                       'target' => 'autoreply', 'ticket_id' => $ticket];
            try {
                $msg = M365::clearAutoReply($config, $email);
                self::report($base + ['status' => 'ok', 'message' => 'Afastamento excluído. ' . $msg]);
            } catch (\Throwable $e) {
                self::report($base + ['status' => 'error',
                    'message' => 'Afastamento excluído, mas a resposta automática NÃO foi desligada: ' . $e->getMessage()]);
            }
        }
    }

    protected static function log($task, $message)
    {
        if ($task instanceof \CronTask) {
            $task->log($message);
        }
        \Toolbox::logInFile('hrvacation', $message . "\n");
    }

    /**
     * Registra o resultado de uma operação e adiciona tarefa no chamado.
     */
    public static function report(array $data)
    {
        global $DB;

        $periods_id = (int) ($data['period_id'] ?? 0);
        $period_num = (int) ($data['period_num'] ?? 1);
        $action     = (string) ($data['action'] ?? '');
        $target     = (string) ($data['target'] ?? '');
        $status     = (string) ($data['status'] ?? '');
        $message    = (string) ($data['message'] ?? '');
        $tickets_id = (int) ($data['ticket_id'] ?? 0);

        if ($periods_id <= 0
            || !in_array($action, [self::ACT_BLOCK, self::ACT_UNBLOCK], true)
            || !in_array($target, ['ad', 'o365', 'autoreply'], true)
            || !in_array($status, ['ok', 'error', 'skipped', 'dryrun'], true)) {
            return ['error' => 'invalid data'];
        }

        // Número desta tentativa (só para erros), contado antes de gravar.
        $attempt = 0;
        if ($status === 'error') {
            $attempt = countElementsInTable('glpi_plugin_hrvacation_executions', [
                'periods_id' => $periods_id, 'period_num' => $period_num,
                'action' => $action, 'target' => $target, 'status' => 'error',
            ]) + 1;
        }

        $DB->insert('glpi_plugin_hrvacation_executions', [
            'periods_id'    => $periods_id,
            'period_num'    => $period_num,
            'action'        => $action,
            'target'        => $target,
            'status'        => $status,
            'message'       => $attempt > 0 ? "[tentativa {$attempt}/" . self::MAX_ATTEMPTS . "] {$message}" : $message,
            'tickets_id'    => $tickets_id,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);

        // O que vai para o chamado. Registros silenciosos ficam só no histórico
        // interno; erros geram tarefa na 1ª tentativa e na última, não nas do meio.
        if (!empty($data['silent']) || $tickets_id <= 0) {
            return ['ok' => true];
        }
        if ($status === 'error' && $attempt > 1 && $attempt < self::MAX_ATTEMPTS) {
            return ['ok' => true];
        }

        if ($status === 'error') {
            if ($attempt >= self::MAX_ATTEMPTS) {
                $prefix  = ($action === self::ACT_UNBLOCK)
                    ? 'FALHA AO LIBERAR - intervenção manual necessária. '
                    : 'Falha após ' . self::MAX_ATTEMPTS . ' tentativas; sem novas tentativas automáticas. ';
                $message = $prefix . $message;
            } else {
                $message .= "\nUma nova tentativa automática será feita (até " . self::MAX_ATTEMPTS . ').';
            }
        }

        $ticket = new Ticket();
        if ($ticket->getFromDB($tickets_id)) {
            $label = ($action === self::ACT_BLOCK)
                ? __('Bloqueio automático', 'hrvacation')
                : __('Liberação automática', 'hrvacation');
            $alvo = [
                'o365'      => 'Microsoft 365',
                'ad'        => 'Active Directory',
                'autoreply' => 'Resposta automática',
            ][$target];
            $situacao = [
                'ok'      => 'concluído',
                'error'   => 'erro',
                'skipped' => 'sem ação',
                'dryrun'  => 'simulação',
            ][$status];
            $txt = "[{$alvo}] {$label}: {$situacao}";
            if ($message !== '') {
                $txt .= "\n" . $message;
            }
            $task = new TicketTask();
            $task->add([
                'tickets_id' => $tickets_id,
                'content'    => $txt,
                // Só erros ficam "a fazer"; o resto é informativo.
                'state'      => ($status === 'error') ? 1 : 2,
            ]);
        }

        return ['ok' => true];
    }
}
