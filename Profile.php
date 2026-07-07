<?php

namespace GlpiPlugin\Hrvacation;

use CommonDBTM;
use CronTask;
use Dropdown;
use Group;
use Html;
use ITILCategory;
use ITILSolution;
use Log;
use Session;
use Ticket;
use TicketTask;
use Toolbox;
use User;

/**
 * Período de férias de um colaborador.
 *
 * Cada registro representa as férias de um usuário entre date_start e date_end.
 * A partir dessas datas, o cron abre os chamados de bloqueio e liberação.
 */
class Period extends CommonDBTM
{
    /** Direito usado para controlar o acesso ao plugin (configurável em Perfis). */
    public static $rightname = 'plugin_hrvacation_period';

    /** Mantém histórico de alterações na aba "Histórico". */
    public $dohistory = true;

    /**
     * Nome do tipo de item exibido na interface.
     */
    public static function getTypeName($nb = 0)
    {
        return _n('Afastamento', 'Afastamentos', $nb, 'hrvacation');
    }

    /**
     * Ícone do menu/itemtype.
     */
    public static function getIcon()
    {
        return 'ti ti-calendar-off';
    }

    /**
     * Abas exibidas no formulário (formulário + histórico).
     */
    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs)
             ->addStandardTab(Log::class, $tabs, $options);
        return $tabs;
    }

    /**
     * Conteúdo do menu: lista, adicionar e o calendário.
     */
    public static function getMenuContent()
    {
        $menu = [];
        if (!static::canView()) {
            return false;
        }

        $menu['title'] = self::getMenuName();
        $menu['page']  = self::getSearchURL(false);
        $menu['icon']  = self::getIcon();

        $menu['links']['search'] = self::getSearchURL(false);
        if (self::canCreate()) {
            $menu['links']['add'] = self::getFormURL(false);
        }
        // Link extra para o calendário, com ícone.
        $calendar_url = '/plugins/hrvacation/front/calendar.php';
        $menu['links']["<i class='ti ti-calendar-event pointer' title='" .
            __s('Calendário', 'hrvacation') . "'></i>"] = $calendar_url;
        $menu['links'][__('Calendário', 'hrvacation')] = $calendar_url;

        // Link extra para a linha do tempo, com ícone.
        $timeline_url = '/plugins/hrvacation/front/timeline.php';
        $menu['links']["<i class='ti ti-timeline pointer' title='" .
            __s('Linha do tempo', 'hrvacation') . "'></i>"] = $timeline_url;
        $menu['links'][__('Linha do tempo', 'hrvacation')] = $timeline_url;

        return $menu;
    }

    // ----------------------------------------------------------------- LIST

    /**
     * Listagem simples dos afastamentos com JOIN direto no banco.
     * Usado no lugar do Search::show() para garantir que o nome do
     * colaborador seja exibido corretamente em qualquer interface.
     */
    public static function showList()
    {
        global $DB;

        $canedit   = Session::haveRight(self::$rightname, UPDATE);
        $cancreate = Session::haveRight(self::$rightname, CREATE);
        $base      = self::getFormURL(false);

        $iterator = $DB->request([
            'SELECT'    => [
                'p.id',
                'p.users_id',
                'p.date_start',
                'p.date_end',
                'p.block_ticket_id',
                'p.unblock_ticket_id',
                'p.entities_id',
                'u.name        AS user_name',
                'u.firstname   AS user_firstname',
                'u.realname    AS user_realname',
            ],
            'FROM'      => self::getTable() . ' AS p',
            'LEFT JOIN' => [
                User::getTable() . ' AS u' => ['FKEY' => ['p' => 'users_id', 'u' => 'id']],
            ],
            'WHERE'     => ['p.is_deleted' => 0]
                + getEntitiesRestrictCriteria('p'),
            'ORDER'     => ['p.date_start DESC'],
        ]);

        echo "<div class='container-fluid'>";
        echo "<div style='margin:16px 0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;'>";
        echo "<h2 style='margin:0;font-size:16px;'>" . self::getTypeName(2) . "</h2>";
        if ($cancreate) {
            echo "<a href='" . htmlspecialchars($base) . "?id=-1' class='btn btn-primary'>"
                . "<i class='ti ti-plus'></i> " . __('Adicionar', 'hrvacation') . "</a>";
        }
        echo "</div>";

        echo "<table class='tab_cadre_fixehov'>";
        echo "<thead><tr>";
        echo "<th>" . __('ID') . "</th>";
        echo "<th>" . User::getTypeName(1) . "</th>";
        echo "<th>" . __('Início do afastamento', 'hrvacation') . "</th>";
        echo "<th>" . __('Término do afastamento', 'hrvacation') . "</th>";
        echo "<th>" . __('Chamado de bloqueio', 'hrvacation') . "</th>";
        echo "<th>" . __('Chamado de liberação', 'hrvacation') . "</th>";
        echo "</tr></thead><tbody>";

        $i = 0;
        foreach ($iterator as $row) {
            $rowclass = ($i % 2) ? 'tab_bg_2' : 'tab_bg_1';
            $url      = htmlspecialchars($base . '?id=' . (int) $row['id']);

            $firstname = trim($row['user_firstname'] ?? '');
            $realname  = trim($row['user_realname'] ?? '');
            $username  = ($firstname || $realname)
                ? trim("$firstname $realname")
                : ($row['user_name'] ?? ('#' . $row['users_id']));

            echo "<tr class='$rowclass'>";
            echo "<td><a href='$url'>" . (int) $row['id'] . "</a></td>";
            echo "<td><a href='$url'>" . htmlspecialchars($username) . "</a></td>";
            echo "<td>" . Html::convDate($row['date_start']) . "</td>";
            echo "<td>" . Html::convDate($row['date_end']) . "</td>";
            echo "<td>" . self::getTicketLink($row['block_ticket_id']) . "</td>";
            echo "<td>" . self::getTicketLink($row['unblock_ticket_id']) . "</td>";
            echo "</tr>";
            $i++;
        }

        if ($i === 0) {
            echo "<tr><td colspan='6' style='text-align:center;padding:20px;color:#888;'>"
                . __('Nenhum resultado encontrado') . "</td></tr>";
        }

        echo "</tbody></table></div>";
    }

    // ------------------------------------------------------------------ FORM

    /**
     * Renderiza valores de campos 'specific' na listagem.
     * Usado para exibir o nome do colaborador sem depender do motor de JOIN.
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }

        switch ($field) {
            case 'users_id':
                $uid = (int) ($values[$field] ?? 0);
                if ($uid <= 0) {
                    return '';
                }
                $user = new User();
                if (!$user->getFromDB($uid)) {
                    return $uid;
                }
                $firstname = trim($user->fields['firstname'] ?? '');
                $realname  = trim($user->fields['realname'] ?? '');
                $name      = $user->fields['name'] ?? '';
                if ($firstname || $realname) {
                    return trim("$firstname $realname");
                }
                return $name;
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    /**
     * Renderiza o campo 'specific' no formulário de busca.
     */
    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if ($field === 'users_id') {
            return User::dropdown([
                'name'   => $name,
                'value'  => $values,
                'right'  => 'all',
                'display' => false,
            ]);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    /**
     * Retorna um <select> com 1..200 dias para o campo informado.
     */
    public static function daysDropdown($name, $selected = 0)
    {
        $out = "<select name='" . htmlspecialchars($name) . "' class='form-select'>";
        $out .= "<option value='0'>" . __('Selecione', 'hrvacation') . "</option>";
        for ($i = 1; $i <= 200; $i++) {
            $sel = ($selected === $i) ? " selected" : "";
            $out .= "<option value='{$i}'{$sel}>{$i} " . __('dia(s)', 'hrvacation') . "</option>";
        }
        $out .= "</select>";
        return $out;
    }

    /**
     * Calcula date_end a partir de date_start + days_count (dias corridos).
     * date_end = date_start + (days_count - 1) dias.
     */
    protected static function calcDateEnd($date_start, $days_count)
    {
        $days = (int) $days_count;
        if (empty($date_start) || $days <= 0) {
            return null;
        }
        $ts = strtotime($date_start);
        if (!$ts) {
            return null;
        }
        return date('Y-m-d', strtotime('+' . ($days - 1) . ' days', $ts));
    }

    /**
     * Validação/normalização ao criar.
     */
    public function prepareInputForAdd($input)
    {
        if (empty($input['entities_id'])) {
            $input['entities_id'] = $_SESSION['glpiactive_entity'] ?? 0;
        }
        if (empty($input['users_id_recipient'])) {
            $input['users_id_recipient'] = (int) Session::getLoginUserID();
        }
        return $this->prepareCommon($input);
    }

    /**
     * Validação/normalização ao atualizar.
     */
    public function prepareInputForUpdate($input)
    {
        return $this->prepareCommon($input);
    }

    /**
     * Regras comuns: calcula date_end dos períodos e valida datas.
     */
    protected function prepareCommon($input)
    {
        // Normaliza is_fracionado
        $input['is_fracionado'] = empty($input['is_fracionado']) ? 0 : 1;

        // Período 1
        $input['date_end'] = self::calcDateEnd(
            $input['date_start'] ?? '',
            $input['days_count'] ?? 0
        );

        // Períodos 2 e 3 (só se fracionado)
        foreach ([2, 3] as $n) {
            if ($input['is_fracionado']
                && !empty($input["date_start{$n}"])
                && !empty($input["days_count{$n}"])) {
                $input["date_end{$n}"] = self::calcDateEnd(
                    $input["date_start{$n}"],
                    $input["days_count{$n}"]
                );
            } else {
                $input["date_start{$n}"] = null;
                $input["days_count{$n}"] = 0;
                $input["date_end{$n}"]   = null;
            }
        }

        // Validação básica
        if (empty($input['date_start']) || empty($input['date_end'])) {
            Session::addMessageAfterRedirect(
                __('Informe a data de início e a quantidade de dias.', 'hrvacation'),
                false, ERROR
            );
            return false;
        }

        return $input;
    }

    /**
     * Renderiza o formulário de cadastro/edição de um período.
     */
    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        $fracionado = (int) ($this->fields['is_fracionado'] ?? 0);

        // ---- Linha 1: Usuário + Redirecionar e-mail ----
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . User::getTypeName(1) . " <span class='red'>*</span></td><td>";
        User::dropdown(['name' => 'users_id', 'value' => $this->fields['users_id'],
            'right' => 'all', 'entity' => $this->fields['entities_id']]);
        echo "</td><td>" . __('Redirecionar e-mail para', 'hrvacation') . "</td><td>";
        User::dropdown(['name' => 'users_id_redirect',
            'value' => $this->fields['users_id_redirect'] ?? 0,
            'right' => 'all', 'entity' => $this->fields['entities_id'],
            'display_emptychoice' => true]);
        echo "</td></tr>";

        // ---- Linha 2: Período 1 — início + dias ----
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Início do afastamento', 'hrvacation') . " <span class='red'>*</span></td><td>";
        Html::showDateField('date_start', ['value' => $this->fields['date_start'] ?? '']);
        echo "</td><td>" . __('Quantidade de dias', 'hrvacation') . " <span class='red'>*</span></td><td>";
        echo self::daysDropdown('days_count', (int) ($this->fields['days_count'] ?? 0));
        echo "</td></tr>";

        // ---- Linha 3: término calculado + checkbox fracionado ----
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Término calculado', 'hrvacation') . "</td><td>";
        echo "<span id='date_end_display' style='color:#374151;font-weight:600;'>";
        echo !empty($this->fields['date_end'])
            ? Html::convDate($this->fields['date_end'])
            : '<em style="color:#9ca3af;">' . __('Selecione início e dias', 'hrvacation') . '</em>';
        echo "</span></td>";
        echo "<td><label style='display:flex;align-items:center;gap:8px;cursor:pointer;'>";
        echo "<input type='checkbox' name='is_fracionado' value='1' id='chk_fracionado'"
            . ($fracionado ? ' checked' : '') . " style='width:18px;height:18px;'>";
        echo "<strong>" . __('Fracionado', 'hrvacation') . "</strong></label>";
        echo "<div style='font-size:11px;color:#6b7280;margin-top:2px;'>"
            . __('Até 3 períodos', 'hrvacation') . "</div></td><td></td></tr>";

        // ---- Períodos 2 e 3 ----
        foreach ([2, 3] as $n) {
            $disp = $fracionado ? '' : 'none';
            echo "<tr class='tab_bg_2 periodo_fracionado' id='periodo{$n}a' style='display:{$disp};'>";
            echo "<td>" . sprintf(__('Início — Período %d', 'hrvacation'), $n)
                . " <span class='red'>*</span></td><td>";
            Html::showDateField("date_start{$n}", ['value' => $this->fields["date_start{$n}"] ?? '']);
            echo "</td><td>" . sprintf(__('Qtd. dias — Período %d', 'hrvacation'), $n)
                . " <span class='red'>*</span></td><td>";
            echo self::daysDropdown("days_count{$n}", (int) ($this->fields["days_count{$n}"] ?? 0));
            echo "</td></tr>";

            echo "<tr class='tab_bg_2 periodo_fracionado' id='periodo{$n}b' style='display:{$disp};'>";
            echo "<td>" . sprintf(__('Término calc. — P%d', 'hrvacation'), $n) . "</td><td>";
            echo "<span id='date_end_display{$n}' style='color:#374151;font-weight:600;'>";
            echo !empty($this->fields["date_end{$n}"])
                ? Html::convDate($this->fields["date_end{$n}"])
                : '<em style="color:#9ca3af;">' . __('Selecione início e dias', 'hrvacation') . '</em>';
            echo "</span></td>";
            echo "<td>" . __('Chamado bloqueio', 'hrvacation') . " P{$n}:</td><td>";
            echo self::getTicketLink($this->fields["block_ticket_id{$n}"] ?? 0) . ' &nbsp; '
                . __('Liberação', 'hrvacation') . " P{$n}: "
                . self::getTicketLink($this->fields["unblock_ticket_id{$n}"] ?? 0);
            echo "</td></tr>";
        }

        // ---- Comentários ----
        echo "<tr class='tab_bg_1'><td>" . __('Comentários') . "</td><td colspan='3'>";
        echo "<textarea class='form-control' name='comment' rows='3' style='width:100%;'>"
            . Html::cleanInputText($this->fields['comment'] ?? '') . "</textarea>";
        echo "</td></tr>";

        // ---- Chamados período 1 ----
        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Chamado de bloqueio', 'hrvacation') . " (P1)</td>";
        echo "<td>" . self::getTicketLink($this->fields['block_ticket_id']) . "</td>";
        echo "<td>" . __('Chamado de liberação', 'hrvacation') . " (P1)</td>";
        echo "<td>" . self::getTicketLink($this->fields['unblock_ticket_id']) . "</td>";
        echo "</tr>";

        $this->showFormButtons($options);

        // JS: calcula término e controla fracionado
        echo "<script>
(function(){
    function addDays(dateStr, days) {
        if (!dateStr || !days || parseInt(days) <= 0) return '';
        var p = dateStr.split('-');
        if (p.length !== 3) return '';
        var d = new Date(p[0], p[1]-1, p[2]);
        d.setDate(d.getDate() + parseInt(days) - 1);
        return String(d.getDate()).padStart(2,'0') + '/'
             + String(d.getMonth()+1).padStart(2,'0') + '/' + d.getFullYear();
    }
    function updateEnd(sName, dName, dispId) {
        var s = document.querySelector('[name=\"' + sName + '\"]');
        var d = document.querySelector('[name=\"' + dName + '\"]');
        var el = document.getElementById(dispId);
        if (!s || !d || !el) return;
        var r = addDays(s.value, d.value);
        el.innerHTML = r ? '<strong>' + r + '</strong>'
            : '<em style=\"color:#9ca3af\">" . addslashes(__('Selecione início e dias', 'hrvacation')) . "</em>';
    }
    function bind(sName, dName, dispId) {
        ['change','input'].forEach(function(ev){
            var s = document.querySelector('[name=\"' + sName + '\"]');
            var d = document.querySelector('[name=\"' + dName + '\"]');
            if (s) s.addEventListener(ev, function(){ updateEnd(sName, dName, dispId); });
            if (d) d.addEventListener(ev, function(){ updateEnd(sName, dName, dispId); });
        });
    }
    bind('date_start', 'days_count', 'date_end_display');
    bind('date_start2', 'days_count2', 'date_end_display2');
    bind('date_start3', 'days_count3', 'date_end_display3');

    var chk = document.getElementById('chk_fracionado');
    function toggleFracionado() {
        document.querySelectorAll('.periodo_fracionado').forEach(function(r){
            r.style.display = chk.checked ? '' : 'none';
        });
    }
    if (chk) { chk.addEventListener('change', toggleFracionado); }
})();
</script>";
        return true;
    }

    /**
     * Retorna um link clicável para um chamado, ou um traço se não existir.
     */
    public static function getTicketLink($tickets_id)
    {
        $tickets_id = (int) $tickets_id;
        if ($tickets_id <= 0) {
            return "<i class='text-muted'>" . __('Ainda não gerado', 'hrvacation') . "</i>";
        }
        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return "#$tickets_id (" . __('removido', 'hrvacation') . ")";
        }
        $url = Ticket::getFormURLWithID($tickets_id);
        return "<a href='" . htmlspecialchars($url) . "'>#$tickets_id - " .
            htmlspecialchars($ticket->fields['name']) . "</a>";
    }

    // --------------------------------------------------------------- SEARCH

    /**
     * Colunas disponíveis na listagem/busca.
     */
    public function rawSearchOptions()
    {
        $opts = [];

        $opts[] = ['id' => 'common', 'name' => self::getTypeName(2)];

        $opts[] = [
            'id'            => '1',
            'table'         => self::getTable(),
            'field'         => 'id',
            'name'          => __('ID'),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];
        $opts[] = [
            'id'            => '2',
            'table'         => self::getTable(),
            'field'         => 'users_id',
            'name'          => User::getTypeName(1),
            'datatype'      => 'specific',
            'massiveaction' => false,
            'searchtype'    => ['equals', 'notequals'],
        ];
        $opts[] = [
            'id'       => '3',
            'table'    => self::getTable(),
            'field'    => 'date_start',
            'name'     => __('Início do afastamento', 'hrvacation'),
            'datatype' => 'date',
        ];
        $opts[] = [
            'id'       => '4',
            'table'    => self::getTable(),
            'field'    => 'date_end',
            'name'     => __('Término do afastamento', 'hrvacation'),
            'datatype' => 'date',
        ];
        $opts[] = [
            'id'       => '5',
            'table'    => self::getTable(),
            'field'    => 'block_ticket_id',
            'name'     => __('Chamado de bloqueio', 'hrvacation'),
            'datatype' => 'number',
        ];
        $opts[] = [
            'id'       => '6',
            'table'    => self::getTable(),
            'field'    => 'unblock_ticket_id',
            'name'     => __('Chamado de liberação', 'hrvacation'),
            'datatype' => 'number',
        ];
        $opts[] = [
            'id'       => '16',
            'table'    => self::getTable(),
            'field'    => 'comment',
            'name'     => __('Comentários'),
            'datatype' => 'text',
        ];
        $opts[] = [
            'id'        => '17',
            'table'     => User::getTable(),
            'field'     => 'name',
            'linkfield' => 'users_id_redirect',
            'name'      => __('Redirecionar e-mail para', 'hrvacation'),
            'datatype'  => 'dropdown',
        ];
        $opts[] = [
            'id'       => '80',
            'table'    => 'glpi_entities',
            'field'    => 'completename',
            'name'     => \Entity::getTypeName(1),
            'datatype' => 'dropdown',
        ];

        return $opts;
    }

    // ------------------------------------------------------------------ CRON

    /**
     * Descrição da tarefa automática (exibida em Configurar > Ações automáticas).
     */
    public static function cronInfo($name)
    {
        if ($name === 'vacationTickets') {
            return [
                'description' => __('Abre chamados de bloqueio/liberação de acessos por afastamento', 'hrvacation'),
            ];
        }
        return [];
    }

    /**
     * Abertura imediata ao cadastrar: se as férias já começaram (ou começam
     * hoje, dentro da antecedência), o chamado de bloqueio é aberto na hora,
     * sem esperar o cron. Idem para a liberação, se o término já estiver
     * dentro da janela. Períodos futuros continuam a cargo do cron.
     */
    public function post_addItem()
    {
        $config = Config::getConfig();
        self::processDue($this->fields, $config);
        parent::post_addItem();
    }

    /**
     * Tarefa diária: percorre os períodos pendentes e abre os chamados que já
     * estão na hora (incluindo retroativos), criando cada chamado uma única vez.
     *
     * @param CronTask $task
     * @return integer 1 = executou ações, 0 = nada a fazer
     */
    public static function cronVacationTickets(CronTask $task)
    {
        global $DB;

        $config = Config::getConfig();
        $count  = 0;

        // Períodos não excluídos que ainda têm algum chamado pendente (qualquer período).
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'is_deleted' => 0,
                'OR'         => [
                    ['block_ticket_id'    => 0],
                    ['unblock_ticket_id'  => 0],
                    ['block_ticket_id2'   => 0],
                    ['unblock_ticket_id2' => 0],
                    ['block_ticket_id3'   => 0],
                    ['unblock_ticket_id3' => 0],
                ],
            ],
        ]);

        foreach ($iterator as $row) {
            $n = self::processDue($row, $config);
            if ($n > 0) {
                $task->addVolume($n);
                $count += $n;
            }
        }

        return $count > 0 ? 1 : 0;
    }

    /**
     * Avalia UM registro e abre os chamados de todos os períodos ativos,
     * respeitando antecedências e janelas. Suporta até 3 períodos (fracionado).
     */
    protected static function processDue(array $row, array $config)
    {
        $today             = date('Y-m-d');
        $lead_block        = (int) $config['block_lead_days'];
        $lead_unblock      = (int) $config['unblock_lead_days'];
        $block_threshold   = date('Y-m-d', strtotime("+{$lead_block} days"));
        $unblock_threshold = date('Y-m-d', strtotime("+{$lead_unblock} days"));
        $unblock_floor     = date('Y-m-d', strtotime('-30 days'));

        $count = 0;

        // Períodos a processar: sempre o 1, mais 2 e 3 se fracionado
        $periods = [
            1 => ['start' => $row['date_start'],  'end' => $row['date_end'],
                  'block' => 'block_ticket_id',   'unblock' => 'unblock_ticket_id'],
        ];
        if (!empty($row['is_fracionado'])) {
            foreach ([2, 3] as $n) {
                if (!empty($row["date_start{$n}"])) {
                    $periods[$n] = [
                        'start'   => $row["date_start{$n}"],
                        'end'     => $row["date_end{$n}"],
                        'block'   => "block_ticket_id{$n}",
                        'unblock' => "unblock_ticket_id{$n}",
                        'num'     => $n,
                    ];
                }
            }
        }

        foreach ($periods as $pnum => $p) {
            $row_with_period = array_merge($row, [
                'date_start' => $p['start'],
                'date_end'   => $p['end'],
                '_period_num' => $pnum,
            ]);

            // Bloqueio
            if ((int) ($row[$p['block']] ?? 0) === 0
                && !empty($p['start']) && !empty($p['end'])
                && $p['start'] <= $block_threshold
                && $p['end'] >= $today) {
                $tid = self::openTicket($row_with_period, 'block', $config);
                if ($tid > 0) {
                    (new self())->update(['id' => $row['id'], $p['block'] => $tid]);
                    $row[$p['block']] = $tid;
                    $count++;
                }
            }

            // Liberação
            if ((int) ($row[$p['unblock']] ?? 0) === 0
                && !empty($p['end'])
                && $p['end'] <= $unblock_threshold
                && $p['end'] >= $unblock_floor) {
                $tid = self::openTicket($row_with_period, 'unblock', $config);
                if ($tid > 0) {
                    (new self())->update(['id' => $row['id'], $p['unblock'] => $tid]);
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Cria de fato o chamado (bloqueio ou liberação) para um período.
     *
     * @param array  $row    Linha do período.
     * @param string $kind   'block' ou 'unblock'.
     * @param array  $config Configuração do plugin.
     * @return integer ID do chamado criado, ou 0 em caso de falha.
     */
    protected static function openTicket(array $row, $kind, array $config)
    {
        $user = new User();
        $username = $user->getFromDB($row['users_id'])
            ? $user->getFriendlyName()
            : ('#' . $row['users_id']);

        $start = Html::convDate($row['date_start']);
        $end   = Html::convDate($row['date_end']);

        // Sufixo de período para afastamentos fracionados
        $pnum   = (int) ($row['_period_num'] ?? 1);
        $psuffix = (!empty($row['is_fracionado']) && $pnum > 1)
            ? sprintf(' [%s %d]', __('Período', 'hrvacation'), $pnum)
            : '';

        $periodo = sprintf(
            __('Período de afastamento: %1$s a %2$s.', 'hrvacation'),
            $start, $end
        );

        if ($kind === 'block') {
            $title = sprintf(__('Bloqueio de acessos - %s (afastamento)', 'hrvacation'), $username) . $psuffix;
            $cat   = (int) $config['itilcategories_id_block'];
            $body  = $title . "\n\n" . $periodo . "\n\n"
                . __('Solicitação do RH: bloquear os acessos do colaborador durante o período de afastamento.', 'hrvacation');
        } else {
            $title = sprintf(__('Liberação de acessos - %s (retorno de afastamento)', 'hrvacation'), $username) . $psuffix;
            $cat   = (int) $config['itilcategories_id_unblock'];
            $body  = $title . "\n\n" . $periodo . "\n\n"
                . __('Solicitação do RH: liberar novamente os acessos do colaborador no retorno do afastamento.', 'hrvacation');
        }

        $redir_id = (int) ($row['users_id_redirect'] ?? 0);
        if ($redir_id > 0) {
            $ruser = new User();
            if ($ruser->getFromDB($redir_id)) {
                $rname  = $ruser->getFriendlyName();
                $remail = \UserEmail::getDefaultForUser($redir_id);
                $rline  = $rname . ($remail ? " <{$remail}>" : '');
                $body  .= "\n\n" . __('Redirecionar e-mail para:', 'hrvacation') . " " . $rline;
            }
        }

        if (!empty($row['comment'])) {
            $body .= "\n\n" . __('Observações do RH:', 'hrvacation') . " " . $row['comment'];
        }

        $input = [
            'name'        => $title,
            'content'     => $body,
            'entities_id' => $row['entities_id'],
            'type'        => (int) ($config['ticket_type'] ?: Ticket::DEMAND_TYPE),
            'status'      => Ticket::INCOMING,
        ];

        // Categoria do chamado (se configurada).
        if ($cat > 0) {
            $input['itilcategories_id'] = $cat;
        }

        // Requerente = usuário do RH que cadastrou o afastamento (users_id_recipient).
        // Fallback: usuário logado no momento da abertura (cron/post_addItem).
        $requester_id = (int) ($row['users_id_recipient'] ?? 0);
        if ($requester_id <= 0) {
            $requester_id = (int) Session::getLoginUserID();
        }
        if ($requester_id > 0) {
            $input['_users_id_requester'] = $requester_id;
        }

        // O colaborador afastado entra como observador do chamado.
        if ((int) $row['users_id'] > 0) {
            $input['_users_id_observer'] = (int) $row['users_id'];
        }

        // Grupo responsável pelo atendimento (se configurado).
        if (!empty($config['groups_id_assign'])) {
            $input['_groups_id_assign'] = (int) $config['groups_id_assign'];
        }

        $ticket = new Ticket();
        $tid = $ticket->add($input);

        if (!$tid) {
            return 0;
        }
        $tid = (int) $tid;

        // Cria as tarefas (uma por linha da configuração), sem responsável.
        $tasks_text = ($kind === 'block')
            ? ($config['block_tasks'] ?? '')
            : ($config['unblock_tasks'] ?? '');
        self::addTicketTasks($tid, $tasks_text);

        return $tid;
    }

    /**
     * Cria uma TicketTask "a fazer" (sem responsável) para cada linha não vazia
     * do texto informado.
     *
     * @param integer $tickets_id
     * @param string  $tasks_text  Tarefas separadas por quebra de linha.
     * @return void
     */
    protected static function addTicketTasks($tickets_id, $tasks_text)
    {
        if (trim((string) $tasks_text) === '') {
            return;
        }

        $lines = preg_split('/\r\n|\r|\n/', $tasks_text);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $task = new TicketTask();
            $task->add([
                'tickets_id' => $tickets_id,
                'content'    => $line,
                'state'      => 1, // 1 = "A fazer" (Planning::TODO)
            ]);
        }
    }

    // ------------------------------------------------ CANCELAMENTO AO EXCLUIR

    /**
     * Ao excluir (enviar para a lixeira) um período, cancela os chamados que já
     * tinham sido abertos.
     */
    public function post_deleteItem()
    {
        $this->cancelLinkedTickets();
        parent::post_deleteItem();
    }

    /**
     * Mesmo tratamento caso o período seja excluído definitivamente (purgado)
     * direto, sem passar pela lixeira.
     */
    public function post_purgeItem()
    {
        $this->cancelLinkedTickets();
        parent::post_purgeItem();
    }

    /**
     * Cancela os chamados de bloqueio e liberação vinculados a este período.
     */
    protected function cancelLinkedTickets()
    {
        $reason = __('Afastamento cancelado pelo RH', 'hrvacation');
        $fields = [
            'block_ticket_id', 'unblock_ticket_id',
            'block_ticket_id2', 'unblock_ticket_id2',
            'block_ticket_id3', 'unblock_ticket_id3',
        ];
        foreach ($fields as $field) {
            $tid = (int) ($this->fields[$field] ?? 0);
            if ($tid > 0) {
                self::cancelTicket($tid, $reason);
            }
        }
    }

    /**
     * "Cancela" um chamado: registra uma solução com o motivo, movendo-o para
     * o status Solucionado. Ignora chamados já solucionados/fechados.
     *
     * @param integer $tickets_id
     * @param string  $reason
     * @return void
     */
    protected static function cancelTicket($tickets_id, $reason)
    {
        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return;
        }

        $status = (int) $ticket->fields['status'];
        if (in_array($status, [Ticket::SOLVED, Ticket::CLOSED], true)) {
            return; // já resolvido/fechado, nada a fazer
        }

        $solution = new ITILSolution();
        $solution->add([
            'itemtype' => Ticket::class,
            'items_id' => $tickets_id,
            'content'  => $reason,
        ]);
    }

    // -------------------------------------------------------------- CALENDAR

    /**
     * Renderiza um calendário mensal simples (sem dependências de JS externas),
     * destacando os colaboradores em férias em cada dia.
     *
     * @param integer $year
     * @param integer $month 1..12
     * @return void
     */
    public static function showCalendar($year, $month)
    {
        global $DB;

        $month = max(1, min(12, (int) $month));
        $year  = (int) $year;

        $first_ts   = mktime(0, 0, 0, $month, 1, $year);
        $days_in    = (int) date('t', $first_ts);
        $first_dow  = (int) date('w', $first_ts); // 0 = domingo
        $first_date = date('Y-m-d', $first_ts);
        $last_date  = date('Y-m-d', mktime(0, 0, 0, $month, $days_in, $year));

        // Navegação prev/next.
        $prev = ['m' => $month - 1, 'y' => $year];
        if ($prev['m'] < 1) { $prev['m'] = 12; $prev['y']--; }
        $next = ['m' => $month + 1, 'y' => $year];
        if ($next['m'] > 12) { $next['m'] = 1; $next['y']++; }

        // Períodos que cruzam o mês exibido (respeitando a entidade ativa).
        $byday = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'is_deleted' => 0,
                ['date_start' => ['<=', $last_date]],
                ['date_end'   => ['>=', $first_date]],
            ] + getEntitiesRestrictCriteria(self::getTable()),
        ]);
        foreach ($iterator as $row) {
            $user = new User();
            $label = $user->getFromDB($row['users_id'])
                ? $user->getFriendlyName()
                : ('#' . $row['users_id']);

            $start_in = ($row['date_start'] >= $first_date && $row['date_start'] <= $last_date);
            $end_in   = ($row['date_end']   >= $first_date && $row['date_end']   <= $last_date);

            if ($row['date_start'] === $row['date_end']) {
                // Afastamento de um único dia.
                if ($start_in) {
                    $day = (int) date('j', strtotime($row['date_start']));
                    $byday[$day][] = ['label' => $label, 'id' => $row['id'], 'kind' => 'both'];
                }
            } else {
                if ($start_in) {
                    $day = (int) date('j', strtotime($row['date_start']));
                    $byday[$day][] = ['label' => $label, 'id' => $row['id'], 'kind' => 'start'];
                }
                if ($end_in) {
                    $day = (int) date('j', strtotime($row['date_end']));
                    $byday[$day][] = ['label' => $label, 'id' => $row['id'], 'kind' => 'end'];
                }
            }
        }

        $months_pt = [
            1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
            5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
            9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
        ];
        $weekdays = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

        $base = self::getFormURL(false);
        $cal  = '/plugins/hrvacation/front/calendar.php';
        $today = date('Y-m-d');

        echo "<div class='hrvac-calendar' style='max-width:1100px;margin:auto;'>";

        // Cabeçalho com navegação.
        echo "<div style='display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;'>";
        echo "<a class='btn btn-outline-secondary' href='{$cal}?month={$prev['m']}&year={$prev['y']}'>"
            . "&laquo; " . __('Mês anterior', 'hrvacation') . "</a>";
        echo "<h2 style='margin:0;'>" . $months_pt[$month] . " " . $year . "</h2>";
        echo "<div>";
        if (self::canCreate()) {
            echo "<a class='btn btn-primary' href='" . $base . "?id=-1'>"
                . "<i class='ti ti-plus'></i> " . __('Cadastrar afastamento', 'hrvacation') . "</a> ";
        }
        echo "<a class='btn btn-outline-info' href='/plugins/hrvacation/front/timeline.php'>"
            . "<i class='ti ti-timeline'></i> " . __('Linha do tempo', 'hrvacation') . "</a> ";
        echo "<a class='btn btn-outline-secondary' href='{$cal}?month={$next['m']}&year={$next['y']}'>"
            . __('Próximo mês', 'hrvacation') . " &raquo;</a>";
        echo "</div>";
        echo "</div>";

        // Grade do mês.
        echo "<table class='tab_cadre_fixe' style='table-layout:fixed;'>";
        echo "<tr>";
        foreach ($weekdays as $wd) {
            echo "<th style='width:14.28%;text-align:center;'>$wd</th>";
        }
        echo "</tr>";

        $cell = 0;
        $day  = 1;
        $total_cells = $first_dow + $days_in;
        $rows = (int) ceil($total_cells / 7);

        for ($r = 0; $r < $rows; $r++) {
            echo "<tr>";
            for ($c = 0; $c < 7; $c++) {
                if ($cell < $first_dow || $day > $days_in) {
                    echo "<td style='height:90px;vertical-align:top;background:#f7f7f7;'></td>";
                } else {
                    $thisdate = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $istoday = ($thisdate === $today);
                    $style = "height:90px;vertical-align:top;padding:3px;";
                    if ($istoday) {
                        $style .= "background:#fff7e6;border:2px solid #f0ad4e;";
                    }
                    echo "<td style='$style'>";
                    echo "<div style='font-weight:bold;font-size:12px;color:#555;'>$day</div>";
                    if (!empty($byday[$day])) {
                        foreach ($byday[$day] as $entry) {
                            $url = $base . '?id=' . (int) $entry['id'];
                            $kind = $entry['kind'] ?? 'both';
                            if ($kind === 'start') {
                                $bg = '#d3f9d8'; $fg = '#2b8a3e';
                                $tip = __('Início do afastamento', 'hrvacation');
                                $marker = '▸ ';
                            } elseif ($kind === 'end') {
                                $bg = '#ffe3e3'; $fg = '#c92a2a';
                                $tip = __('Fim do afastamento', 'hrvacation');
                                $marker = '◂ ';
                            } else {
                                $bg = '#d0ebff'; $fg = '#0b4f8a';
                                $tip = __('Início e fim do afastamento', 'hrvacation');
                                $marker = '◆ ';
                            }
                            $title = $tip . ' — ' . $entry['label'];
                            echo "<div style='margin-top:2px;'>";
                            echo "<a href='" . htmlspecialchars($url) . "' "
                                . "style='display:block;font-size:11px;background:{$bg};color:{$fg};"
                                . "border-radius:3px;padding:1px 4px;overflow:hidden;text-overflow:ellipsis;"
                                . "white-space:nowrap;text-decoration:none;' "
                                . "title='" . htmlspecialchars($title) . "'>"
                                . $marker . htmlspecialchars($entry['label']) . "</a>";
                            echo "</div>";
                        }
                    }
                    echo "</td>";
                    $day++;
                }
                $cell++;
            }
            echo "</tr>";
        }
        echo "</table>";
        echo "</div>";
    }

    // -------------------------------------------------------------- TIMELINE

    /**
     * Renderiza uma linha do tempo (Gantt) com TODOS os períodos de férias que
     * cruzam a janela exibida: uma barra por colaborador, do início ao fim das
     * férias, empilhadas para evidenciar sobreposições.
     *
     * @param string  $start_date Data inicial da janela (Y-m-d).
     * @param integer $days       Tamanho da janela em dias.
     * @return void
     */
    public static function showTimeline($start_date, $days)
    {
        global $DB;

        $days = max(15, min(366, (int) $days));
        $win_start = $start_date ?: date('Y-m-01');
        $win_start_ts = strtotime($win_start);
        $win_start = date('Y-m-d', $win_start_ts);
        $win_end_ts = strtotime("+{$days} days", $win_start_ts);
        $win_end = date('Y-m-d', $win_end_ts);
        $total = $days; // total de dias da régua

        // Períodos que cruzam a janela (respeitando a entidade ativa).
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'is_deleted' => 0,
                ['date_start' => ['<=', $win_end]],
                ['date_end'   => ['>=', $win_start]],
            ] + getEntitiesRestrictCriteria(self::getTable()),
            'ORDER' => ['date_start ASC', 'date_end ASC'],
        ]);

        $rows = [];
        foreach ($iterator as $row) {
            $user = new User();
            $name = $user->getFromDB($row['users_id'])
                ? $user->getFriendlyName()
                : ('#' . $row['users_id']);
            $rows[] = $row + ['_name' => $name];
        }

        $months_pt = [
            1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr', 5 => 'Mai', 6 => 'Jun',
            7 => 'Jul', 8 => 'Ago', 9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez',
        ];

        $base = self::getFormURL(false);
        $self = '/plugins/hrvacation/front/timeline.php';
        $today = date('Y-m-d');
        $label_w = 240; // largura da coluna de nomes (px)

        // Navegação (janela anterior/seguinte) e tamanhos de janela.
        $prev_start = date('Y-m-d', strtotime("-{$days} days", $win_start_ts));
        $next_start = date('Y-m-d', strtotime("+{$days} days", $win_start_ts));

        echo "<div class='hrvac-timeline' style='max-width:1200px;margin:auto;'>";

        echo "<div style='display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap;gap:8px;'>";
        echo "<a class='btn btn-outline-secondary' href='{$self}?start={$prev_start}&days={$days}'>&laquo; "
            . __('Anterior', 'hrvacation') . "</a>";
        echo "<h2 style='margin:0;'>" . __('Linha do tempo de afastamentos', 'hrvacation') . "</h2>";
        echo "<div style='display:flex;gap:6px;align-items:center;'>";
        foreach ([30 => '30d', 90 => '90d', 180 => '180d'] as $d => $lbl) {
            $active = ($d === $days) ? 'btn-info' : 'btn-outline-info';
            echo "<a class='btn {$active}' href='{$self}?start={$win_start}&days={$d}'>{$lbl}</a>";
        }
        echo "<a class='btn btn-outline-secondary' href='/plugins/hrvacation/front/calendar.php'>"
            . "<i class='ti ti-calendar'></i> " . __('Calendário', 'hrvacation') . "</a>";
        if (self::canCreate()) {
            echo "<a class='btn btn-primary' href='" . $base . "?id=-1'>"
                . "<i class='ti ti-plus'></i> " . __('Cadastrar', 'hrvacation') . "</a>";
        }
        echo "</div>";
        echo "<a class='btn btn-outline-secondary' href='{$self}?start={$next_start}&days={$days}'>"
            . __('Próxima', 'hrvacation') . " &raquo;</a>";
        echo "</div>";

        // Período exibido (texto).
        echo "<div style='text-align:center;color:#666;margin-bottom:8px;'>"
            . Html::convDate($win_start) . " &mdash; " . Html::convDate($win_end) . "</div>";

        // Régua superior: marcas de início de mês + linha de "hoje".
        $month_marks = [];
        for ($i = 0; $i <= $total; $i++) {
            $d = strtotime("+{$i} days", $win_start_ts);
            if ((int) date('j', $d) === 1 || $i === 0) {
                $left = ($i / $total) * 100;
                $month_marks[] = [
                    'left'  => $left,
                    'label' => $months_pt[(int) date('n', $d)] . '/' . date('y', $d),
                ];
            }
        }
        $today_left = null;
        if ($today >= $win_start && $today <= $win_end) {
            $today_left = ((strtotime($today) - $win_start_ts) / DAY_TIMESTAMP / $total) * 100;
        }

        // Container com overlay de gridlines.
        echo "<div style='position:relative;border:1px solid #e0e0e0;border-radius:6px;overflow:hidden;'>";

        // Cabeçalho da régua.
        echo "<div style='position:relative;height:26px;background:#f5f7fa;border-bottom:1px solid #e0e0e0;'>";
        echo "<div style='position:absolute;left:0;top:0;bottom:0;width:{$label_w}px;"
            . "font-weight:bold;font-size:12px;color:#555;display:flex;align-items:center;padding-left:8px;'>"
            . __('Colaborador', 'hrvacation') . "</div>";
        echo "<div style='position:absolute;left:{$label_w}px;right:0;top:0;bottom:0;'>";
        foreach ($month_marks as $mk) {
            $l = number_format($mk['left'], 3, '.', '');
            echo "<div style='position:absolute;left:{$l}%;top:0;bottom:0;border-left:1px solid #d9e2ec;'>"
                . "<span style='font-size:11px;color:#486581;padding-left:3px;'>"
                . htmlspecialchars($mk['label']) . "</span></div>";
        }
        if ($today_left !== null) {
            $tl = number_format($today_left, 3, '.', '');
            echo "<div style='position:absolute;left:{$tl}%;top:0;bottom:0;border-left:2px solid #e8590c;'></div>";
        }
        echo "</div></div>";

        if (empty($rows)) {
            echo "<div style='padding:20px;text-align:center;color:#888;'>"
                . __('Nenhum afastamento cadastrado nesta janela.', 'hrvacation') . "</div>";
        }

        // Paleta para diferenciar barras.
        $palette = [
            ['#cfe8ff', '#0b4f8a'], ['#d3f9d8', '#2b8a3e'], ['#ffe3e3', '#c92a2a'],
            ['#fff3bf', '#e67700'], ['#e5dbff', '#6741d9'], ['#c5f6fa', '#0c8599'],
        ];

        $r = 0;
        foreach ($rows as $row) {
            $start = max($row['date_start'], $win_start);
            $end   = min($row['date_end'], $win_end);
            $off   = (strtotime($start) - $win_start_ts) / DAY_TIMESTAMP;
            $span  = ((strtotime($end) - strtotime($start)) / DAY_TIMESTAMP) + 1;

            $left  = max(0, ($off / $total) * 100);
            $width = max(0.8, ($span / $total) * 100);
            if ($left + $width > 100) {
                $width = 100 - $left;
            }
            $left  = number_format($left, 3, '.', '');
            $width = number_format($width, 3, '.', '');

            [$bg, $fg] = $palette[$r % count($palette)];
            $rowbg = ($r % 2) ? '#ffffff' : '#fbfcfd';

            $range_txt = Html::convDate($row['date_start']) . ' – ' . Html::convDate($row['date_end']);
            $url = $base . '?id=' . (int) $row['id'];

            echo "<div style='position:relative;height:34px;background:{$rowbg};border-bottom:1px solid #f0f0f0;'>";

            // Nome + datas (coluna fixa).
            echo "<div style='position:absolute;left:0;top:0;bottom:0;width:{$label_w}px;"
                . "display:flex;flex-direction:column;justify-content:center;padding-left:8px;overflow:hidden;'>";
            echo "<span style='font-size:12px;font-weight:600;color:#333;white-space:nowrap;"
                . "overflow:hidden;text-overflow:ellipsis;'>" . htmlspecialchars($row['_name']) . "</span>";
            echo "<span style='font-size:10px;color:#888;'>" . htmlspecialchars($range_txt) . "</span>";
            echo "</div>";

            // Trilha + barra.
            echo "<div style='position:absolute;left:{$label_w}px;right:0;top:0;bottom:0;'>";
            // gridlines de mês na linha.
            foreach ($month_marks as $mk) {
                $l = number_format($mk['left'], 3, '.', '');
                echo "<div style='position:absolute;left:{$l}%;top:0;bottom:0;border-left:1px solid #f0f3f6;'></div>";
            }
            if ($today_left !== null) {
                $tl = number_format($today_left, 3, '.', '');
                echo "<div style='position:absolute;left:{$tl}%;top:0;bottom:0;border-left:2px solid #ffd8a8;'></div>";
            }
            // a barra.
            echo "<a href='" . htmlspecialchars($url) . "' title='" . htmlspecialchars($row['_name'] . ' — ' . $range_txt) . "' "
                . "style='position:absolute;left:{$left}%;width:{$width}%;top:7px;height:20px;"
                . "background:{$bg};color:{$fg};border:1px solid {$fg};border-radius:10px;"
                . "font-size:10px;line-height:18px;padding:0 6px;white-space:nowrap;overflow:hidden;"
                . "text-overflow:ellipsis;text-decoration:none;box-sizing:border-box;'>"
                . htmlspecialchars($range_txt) . "</a>";
            echo "</div>";

            echo "</div>";
            $r++;
        }

        echo "</div>"; // container
        echo "<div style='margin-top:8px;font-size:11px;color:#999;'>"
            . "<span style='border-left:2px solid #e8590c;padding-left:4px;'>"
            . __('Linha laranja = hoje. Clique numa barra para abrir o período.', 'hrvacation')
            . "</span></div>";
        echo "</div>"; // wrapper
    }
}
