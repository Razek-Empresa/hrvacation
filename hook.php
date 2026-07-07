<?php

namespace GlpiPlugin\Hrvacation;

use CommonDBTM;
use Dropdown;
use Group;
use Html;
use ITILCategory;
use Ticket;

/**
 * Configuração do plugin (linha única, id = 1).
 */
class Config extends CommonDBTM
{
    public static $rightname = 'config';

    public static function getTypeName($nb = 0)
    {
        return __('Afastamentos / Bloqueio de acessos', 'hrvacation');
    }

    /**
     * Retorna a configuração atual como array (com defaults seguros).
     *
     * @return array
     */
    public static function getConfig()
    {
        $config = new self();
        if ($config->getFromDB(1)) {
            return $config->fields;
        }
        return [
            'id'                        => 1,
            'block_lead_days'           => 0,
            'unblock_lead_days'         => 0,
            'itilcategories_id_block'   => 0,
            'itilcategories_id_unblock' => 0,
            'groups_id_assign'          => 0,
            'ticket_type'               => Ticket::DEMAND_TYPE,
            'block_tasks'               => self::getDefaultBlockTasks(),
            'unblock_tasks'             => self::getDefaultUnblockTasks(),
        ];
    }

    /**
     * Lista padrão de tarefas do chamado de BLOQUEIO (uma por linha).
     */
    public static function getDefaultBlockTasks()
    {
        return implode("\n", [
            'Bloquear acesso Active Directory',
            'Bloquear acesso sectra Razek',
            'Bloquear acesso sectra SmartMed',
            'Bloquear acesso sectra Medfield',
            'Bloquear acesso Office 365',
            'Configurar mensagem de ausência Office 365',
            'Redirecionar Email',
        ]);
    }

    /**
     * Lista padrão de tarefas do chamado de LIBERAÇÃO (espelho do bloqueio).
     */
    public static function getDefaultUnblockTasks()
    {
        return implode("\n", [
            'Desbloquear acesso Active Directory',
            'Desbloquear acesso sectra Razek',
            'Desbloquear acesso sectra SmartMed',
            'Desbloquear acesso sectra Medfield',
            'Desbloquear acesso Office 365',
            'Remover mensagem de ausência Office 365',
            'Remover redirecionamento de Email',
        ]);
    }

    /**
     * Formulário de configuração.
     */
    public function showConfigForm()
    {
        $this->getFromDB(1);
        $f = $this->fields;

        echo "<form method='post' action='/plugins/hrvacation/front/config.form.php'>";
        echo "<table class='tab_cadre_fixe'>";
        echo "<tr><th colspan='2'>" . self::getTypeName() . "</th></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Antecedência para o chamado de BLOQUEIO (dias)', 'hrvacation') . "</td>";
        echo "<td>";
        Dropdown::showNumber('block_lead_days', [
            'value' => $f['block_lead_days'],
            'min'   => 0,
            'max'   => 30,
        ]);
        echo "<br><i class='text-muted'>" .
            __('0 = abre no próprio dia de início do afastamento. 1 = abre 1 dia antes, etc.', 'hrvacation') .
            "</i></td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Antecedência para o chamado de LIBERAÇÃO (dias)', 'hrvacation') . "</td>";
        echo "<td>";
        Dropdown::showNumber('unblock_lead_days', [
            'value' => $f['unblock_lead_days'],
            'min'   => 0,
            'max'   => 30,
        ]);
        echo "<br><i class='text-muted'>" .
            __('Contado a partir do último dia do afastamento (término).', 'hrvacation') .
            "</i></td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Categoria do chamado de bloqueio', 'hrvacation') . "</td>";
        echo "<td>";
        ITILCategory::dropdown([
            'name'  => 'itilcategories_id_block',
            'value' => $f['itilcategories_id_block'],
        ]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Categoria do chamado de liberação', 'hrvacation') . "</td>";
        echo "<td>";
        ITILCategory::dropdown([
            'name'  => 'itilcategories_id_unblock',
            'value' => $f['itilcategories_id_unblock'],
        ]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Grupo responsável pelos chamados', 'hrvacation') . "</td>";
        echo "<td>";
        Group::dropdown([
            'name'      => 'groups_id_assign',
            'value'     => $f['groups_id_assign'],
            'condition' => ['is_assign' => 1],
        ]);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Tipo do chamado', 'hrvacation') . "</td>";
        echo "<td>";
        Ticket::dropdownType('ticket_type', ['value' => $f['ticket_type']]);
        echo "</td></tr>";

        echo "<tr><th colspan='2'>" .
            __('Tarefas geradas automaticamente em cada chamado (uma por linha)', 'hrvacation') .
            "</th></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td style='vertical-align:top;'>" . __('Tarefas do chamado de BLOQUEIO', 'hrvacation') . "</td>";
        echo "<td>";
        echo "<textarea name='block_tasks' rows='8' class='form-control' style='width:100%;font-family:monospace;'>" .
            Html::cleanInputText($f['block_tasks'] ?? '') . "</textarea>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td style='vertical-align:top;'>" . __('Tarefas do chamado de LIBERAÇÃO', 'hrvacation') . "</td>";
        echo "<td>";
        echo "<textarea name='unblock_tasks' rows='8' class='form-control' style='width:100%;font-family:monospace;'>" .
            Html::cleanInputText($f['unblock_tasks'] ?? '') . "</textarea>";
        echo "<br><i class='text-muted'>" .
            __('Cada linha vira uma tarefa separada (a fazer) no chamado, sem responsável definido.', 'hrvacation') .
            "</i>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_2'>";
        echo "<td colspan='2' style='text-align:center;'>";
        echo Html::hidden('id', ['value' => 1]);
        echo Html::submit(_x('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
        echo "</td></tr>";

        echo "</table>";
        Html::closeForm();
    }
}
