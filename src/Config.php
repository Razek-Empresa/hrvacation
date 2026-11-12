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
    /** Tipado como "string" por exigência do GLPI 12 (ver Period::$rightname). */
    public static string $rightname = 'config';

    /**
     * Sem histórico: evita que valores de configuração (incluindo o client
     * secret criptografado) sejam copiados para a tabela de logs do GLPI.
     */
    public bool $dohistory = false;

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
            Util::esc($f['block_tasks'] ?? '') . "</textarea>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td style='vertical-align:top;'>" . __('Tarefas do chamado de LIBERAÇÃO', 'hrvacation') . "</td>";
        echo "<td>";
        echo "<textarea name='unblock_tasks' rows='8' class='form-control' style='width:100%;font-family:monospace;'>" .
            Util::esc($f['unblock_tasks'] ?? '') . "</textarea>";
        echo "<br><i class='text-muted'>" .
            __('Cada linha vira uma tarefa separada (a fazer) no chamado, sem responsável definido.', 'hrvacation') .
            "</i>";
        echo "</td></tr>";

        // ---- Automação (worker AD / Microsoft 365) ----
        echo "<tr><th colspan='2'>" .
            __('Automação de bloqueio no Microsoft 365 / Active Directory', 'hrvacation') . "</th></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Automação ativa', 'hrvacation') . "</td><td>";
        echo "<label style='display:flex;align-items:center;gap:8px;'>";
        echo "<input type='checkbox' name='automation_enabled' value='1'"
            . (!empty($f['automation_enabled']) ? ' checked' : '') . " style='width:18px;height:18px;'>";
        echo __('Bloquear e liberar contas conforme os chamados abertos', 'hrvacation');
        echo "</label>";
        if (!empty($f['automation_since'])) {
            echo "<i class='text-muted'>" . sprintf(
                __('Considera apenas chamados abertos a partir de %s. Chamados anteriores seguem o processo manual.', 'hrvacation'),
                Html::convDateTime($f['automation_since'])
            ) . "</i>";
        }
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Modo simulação (dry-run)', 'hrvacation') . "</td><td>";
        echo "<label style='display:flex;align-items:center;gap:8px;'>";
        echo "<input type='checkbox' name='automation_dryrun' value='1'"
            . (!empty($f['automation_dryrun']) ? ' checked' : '') . " style='width:18px;height:18px;'>";
        echo "<strong>" . __('Apenas registrar o que seria feito, sem executar', 'hrvacation') . "</strong>";
        echo "</label>";
        echo "<br><i class='text-muted'>" .
            __('Mantenha ligado nas primeiras semanas para validar antes de agir de verdade.', 'hrvacation') .
            "</i></td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Bloquear no Microsoft 365', 'hrvacation') . "</td><td>";
        echo "<label style='display:flex;align-items:center;gap:8px;'>";
        echo "<input type='checkbox' name='automation_o365' value='1'"
            . (!empty($f['automation_o365']) ? ' checked' : '') . " style='width:18px;height:18px;'>";
        echo __('Desabilita a conta no Entra ID e encerra as sessões ativas', 'hrvacation');
        echo "</label></td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Bloquear no Active Directory', 'hrvacation') . "</td><td>";
        echo "<label style='display:flex;align-items:center;gap:8px;'>";
        echo "<input type='checkbox' name='automation_ad' value='1'"
            . (!empty($f['automation_ad']) ? ' checked' : '') . " style='width:18px;height:18px;'>";
        echo __('Desabilita a conta no AD local', 'hrvacation');
        echo "</label></td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Limite de contas por execução', 'hrvacation') . "</td><td>";
        Dropdown::showNumber('automation_max_per_run', [
            'value' => $f['automation_max_per_run'] ?? 5,
            'min'   => 1,
            'max'   => 50,
        ]);
        echo "<br><i class='text-muted'>" .
            __('Disjuntor de segurança: se houver mais bloqueios pendentes que isso, nada é executado e o motivo fica registrado.', 'hrvacation') .
            "</i></td></tr>";

        echo "<tr class='tab_bg_1'><td style='vertical-align:top;'>"
            . __('Contas nunca bloqueadas', 'hrvacation') . "</td><td>";
        echo "<textarea name='automation_exclude' rows='5' class='form-control' "
            . "style='width:100%;font-family:monospace;'>"
            . Util::esc($f['automation_exclude'] ?? '') . "</textarea>";
        echo "<br><i class='text-muted'>" .
            __('Um por linha: login do GLPI ou e-mail (administradores, contas de serviço, diretoria).', 'hrvacation') .
            "</i></td></tr>";

        // ---- Microsoft 365 (Entra ID) ----
        echo "<tr><th colspan='2'>" .
            __('Microsoft 365 (Entra ID)', 'hrvacation') . "</th></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('ID do diretório (Tenant ID)', 'hrvacation') . "</td><td>";
        echo "<input type='text' name='m365_tenant_id' class='form-control' autocomplete='off' "
            . "style='font-family:monospace;' placeholder='00000000-0000-0000-0000-000000000000' value='"
            . Util::esc($f['m365_tenant_id'] ?? '') . "'>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('ID do aplicativo (Client ID)', 'hrvacation') . "</td><td>";
        echo "<input type='text' name='m365_client_id' class='form-control' autocomplete='off' "
            . "style='font-family:monospace;' placeholder='00000000-0000-0000-0000-000000000000' value='"
            . Util::esc($f['m365_client_id'] ?? '') . "'>";
        echo "</td></tr>";

        // O segredo NUNCA é enviado de volta ao navegador.
        $has_secret = !empty($f['m365_client_secret']);
        echo "<tr class='tab_bg_1'><td>" . __('Segredo do cliente (Client secret)', 'hrvacation') . "</td><td>";
        echo "<input type='password' name='m365_client_secret' class='form-control' "
            . "autocomplete='new-password' spellcheck='false' value='' placeholder='"
            . ($has_secret
                ? __s('Configurado — digite apenas para substituir', 'hrvacation')
                : __s('Cole aqui o VALOR do segredo', 'hrvacation'))
            . "'>";
        if ($has_secret) {
            $upd = !empty($f['m365_secret_updated']) ? Html::convDateTime($f['m365_secret_updated']) : '-';
            echo "<div style='margin-top:4px;color:#2b8a3e;'>&#10003; "
                . sprintf(__('Segredo armazenado criptografado (atualizado em %s).', 'hrvacation'), $upd)
                . "</div>";
            echo "<label style='display:flex;align-items:center;gap:6px;margin-top:4px;'>"
                . "<input type='checkbox' name='m365_secret_clear' value='1'> "
                . __('Apagar o segredo armazenado', 'hrvacation') . "</label>";
        }
        echo "<i class='text-muted'>" .
            __('O segredo é criptografado com a chave do GLPI e nunca é exibido novamente.', 'hrvacation') .
            "</i></td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Validade do segredo', 'hrvacation') . "</td><td>";
        Html::showDateField('m365_secret_expires', ['value' => $f['m365_secret_expires'] ?? '']);
        if (!empty($f['m365_secret_expires'])) {
            $days = (int) floor((strtotime($f['m365_secret_expires']) - time()) / DAY_TIMESTAMP);
            if ($days < 0) {
                echo "<div style='color:#c92a2a;font-weight:600;margin-top:4px;'>"
                    . __('Segredo EXPIRADO: gere um novo no Entra ID.', 'hrvacation') . "</div>";
            } elseif ($days <= 30) {
                echo "<div style='color:#e67700;font-weight:600;margin-top:4px;'>"
                    . sprintf(__('Segredo expira em %d dia(s). Programe a renovação.', 'hrvacation'), $days) . "</div>";
            }
        }
        echo "<br><i class='text-muted'>" .
            __('Informe a data de expiração exibida no Entra ID, para receber o aviso de renovação aqui.', 'hrvacation') .
            "</i></td></tr>";

        echo "<tr class='tab_bg_1'><td></td><td>";
        echo Html::submit(__('Salvar e testar conexão', 'hrvacation'),
            ['name' => 'test_m365', 'class' => 'btn btn-outline-secondary']);
        echo "<br><i class='text-muted'>" .
            __('Confere a autenticação e as permissões do app, sem alterar nenhuma conta.', 'hrvacation') .
            "</i></td></tr>";

        // ---- Resposta automática de ausência ----
        echo "<tr><th colspan='2'>" . __('Resposta automática de ausência (Microsoft 365)', 'hrvacation') . "</th></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Configurar resposta automática', 'hrvacation') . "</td><td>";
        echo "<label style='display:flex;align-items:center;gap:8px;'>";
        echo "<input type='checkbox' name='automation_autoreply' value='1'"
            . (!empty($f['automation_autoreply']) ? ' checked' : '') . " style='width:18px;height:18px;'>";
        echo __('Ativar no bloqueio e desligar na liberação (somente remetentes internos)', 'hrvacation');
        echo "</label>";
        echo "<i class='text-muted'>" .
            __('Exige a permissão de aplicativo MailboxSettings.ReadWrite no app do Entra ID.', 'hrvacation') .
            "</i></td></tr>";

        $rule = AutoReplyRule::fromConfig($f);
        $sel = function ($name, array $opts, $current) {
            $h = "<select name='{$name}' class='form-select' style='max-width:420px;'>";
            foreach ($opts as $v => $label) {
                $h .= "<option value='" . htmlspecialchars((string) $v) . "'"
                    . ((string) $v === (string) $current ? ' selected' : '') . '>'
                    . htmlspecialchars($label) . '</option>';
            }
            return $h . '</select>';
        };
        $hours = [];
        for ($h = 0; $h < 24; $h++) {
            $hours[$h] = sprintf('%02dh', $h);
        }

        echo "<tr class='tab_bg_1'><td>" . __('Início da mensagem', 'hrvacation') . "</td><td>";
        echo "<div style='display:flex;align-items:center;gap:8px;flex-wrap:wrap;'>"
            . __('No primeiro dia do afastamento, às', 'hrvacation') . ' '
            . $sel('autoreply_start_hour', $hours, $rule['start_hour']) . "</div>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Encerramento', 'hrvacation') . "</td><td>";
        echo "<div style='display:flex;align-items:center;gap:8px;flex-wrap:wrap;'>"
            . __('No dia do retorno, às', 'hrvacation') . ' '
            . $sel('autoreply_end_hour', $hours, $rule['end_hour']) . "</div>";
        echo "<i class='text-muted'>" .
            __('O dia do retorno é o dia seguinte ao último dia do afastamento. Quem liga e desliga a mensagem é o próprio Exchange, pelo agendamento.', 'hrvacation') .
            "</i></td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Fuso horário', 'hrvacation') . "</td><td>";
        echo $sel('autoreply_timezone', AutoReplyRule::TIMEZONES, $rule['timezone']);
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Quem recebe a resposta', 'hrvacation') . "</td><td>";
        echo $sel('autoreply_audience', AutoReplyRule::AUDIENCES, $rule['audience']);
        echo "<br><i class='text-muted'>" .
            __('A mesma mensagem é usada para todos os destinatários escolhidos.', 'hrvacation') .
            "</i></td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('No chamado de liberação', 'hrvacation') . "</td><td>";
        echo $sel('autoreply_on_unblock', AutoReplyRule::ON_UNBLOCK, $rule['on_unblock']);
        echo "<br><i class='text-muted'>" .
            __('O chamado de liberação abre antes do retorno. "Desligar" encerra a mensagem nesse momento, ainda durante o afastamento.', 'hrvacation') .
            "</i></td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Ao excluir o afastamento', 'hrvacation') . "</td><td>";
        echo "<label style='display:flex;align-items:center;gap:8px;'>";
        echo "<input type='checkbox' name='autoreply_on_delete' value='1'"
            . ($rule['on_delete'] ? ' checked' : '') . " style='width:18px;height:18px;'>";
        echo __('Desligar a resposta automática na hora', 'hrvacation');
        echo "</label></td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Resposta já configurada pelo colaborador', 'hrvacation') . "</td><td>";
        echo "<label style='display:flex;align-items:center;gap:8px;'>";
        echo "<input type='checkbox' name='autoreply_overwrite' value='1'"
            . ($rule['overwrite'] ? ' checked' : '') . " style='width:18px;height:18px;'>";
        echo __('Substituir pela mensagem do plugin', 'hrvacation');
        echo "</label>";
        echo "<i class='text-muted'>" .
            __('Desmarcado: se o colaborador já tiver uma resposta automática ativa ou agendada, ela é mantida e o chamado registra isso.', 'hrvacation') .
            "</i></td></tr>";

        echo "<tr class='tab_bg_1'><td style='vertical-align:top;'>" . __('Como a regra funciona', 'hrvacation') . "</td><td>";
        echo "<div style='border:1px solid #d0d7de;border-radius:6px;padding:10px 12px;background:rgba(127,127,127,.06);'>";
        foreach (AutoReplyRule::describe($rule) as $line) {
            echo "<div style='margin:2px 0;'>" . htmlspecialchars($line) . "</div>";
        }
        echo "</div><i class='text-muted'>" .
            __('Resumo das opções salvas. Após alterar os campos, salve para atualizar.', 'hrvacation') .
            "</i></td></tr>";

        echo "<tr class='tab_bg_1'><td style='vertical-align:top;'>" . __('Mensagem', 'hrvacation') . "</td><td>";
        echo "<textarea name='autoreply_message' rows='8' class='form-control' style='width:100%;'>"
            . Util::esc($f['autoreply_message'] ?? '') . "</textarea>";
        echo "<i class='text-muted'>" .
            __('Marcadores: {nome} colaborador, {inicio} primeiro dia, {fim} último dia, {retorno} dia da volta, {contato} frase com a pessoa indicada em "Redirecionar e-mail para" (fica vazio se não houver).', 'hrvacation') .
            "</i></td></tr>";

        // ---- Active Directory (LDAPS) ----
        echo "<tr><th colspan='2'>" . __('Active Directory (LDAPS)', 'hrvacation') . "</th></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Controlador de domínio', 'hrvacation') . "</td><td>";
        echo "<input type='text' name='ad_host' class='form-control' autocomplete='off' "
            . "placeholder='dc01.exemplo.local' value='"
            . Util::esc($f['ad_host'] ?? '') . "'>";
        echo "<i class='text-muted'>" .
            __('Use o nome completo que consta no certificado do controlador, não o IP.', 'hrvacation') .
            "</i></td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Porta', 'hrvacation') . "</td><td>";
        echo "<input type='number' name='ad_port' class='form-control' style='max-width:120px;' min='1' max='65535' value='"
            . (int) ($f['ad_port'] ?? 636) . "'>";
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('OU dos colaboradores', 'hrvacation') . "</td><td>";
        echo "<input type='text' name='ad_base_dn' class='form-control' autocomplete='off' "
            . "style='font-family:monospace;' placeholder='OU=Colaboradores,DC=exemplo,DC=local' value='"
            . Util::esc($f['ad_base_dn'] ?? '') . "'>";
        echo "<i class='text-muted'>" .
            __('Somente contas dentro desta OU podem ser bloqueadas ou liberadas.', 'hrvacation') .
            "</i></td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Conta de serviço', 'hrvacation') . "</td><td>";
        echo "<input type='text' name='ad_bind_user' class='form-control' autocomplete='off' "
            . "placeholder='svc-glpi@exemplo.local' value='"
            . Util::esc($f['ad_bind_user'] ?? '') . "'>";
        echo "</td></tr>";

        // A senha NUNCA é enviada de volta ao navegador.
        $has_pwd = !empty($f['ad_bind_password']);
        echo "<tr class='tab_bg_1'><td>" . __('Senha da conta de serviço', 'hrvacation') . "</td><td>";
        echo "<input type='password' name='ad_bind_password' class='form-control' "
            . "autocomplete='new-password' spellcheck='false' value='' placeholder='"
            . ($has_pwd
                ? __s('Configurada — digite apenas para substituir', 'hrvacation')
                : __s('Senha da conta de serviço', 'hrvacation'))
            . "'>";
        if ($has_pwd) {
            $upd = !empty($f['ad_password_updated']) ? Html::convDateTime($f['ad_password_updated']) : '-';
            echo "<div style='margin-top:4px;color:#2b8a3e;'>&#10003; "
                . sprintf(__('Senha armazenada criptografada (atualizada em %s).', 'hrvacation'), $upd)
                . "</div>";
            echo "<label style='display:flex;align-items:center;gap:6px;margin-top:4px;'>"
                . "<input type='checkbox' name='ad_password_clear' value='1'> "
                . __('Apagar a senha armazenada', 'hrvacation') . "</label>";
        }
        echo "</td></tr>";

        echo "<tr class='tab_bg_1'><td style='vertical-align:top;'>"
            . __('Certificado da AC interna', 'hrvacation') . "</td><td>";
        if (!empty($f['ad_ca_cert'])) {
            try {
                $ca = AdLdap::inspectCa($f['ad_ca_cert']);
                echo "<div style='margin-bottom:4px;color:#2b8a3e;'>&#10003; "
                    . sprintf(__('Certificado carregado: %1$s (válido até %2$s).', 'hrvacation'),
                        htmlspecialchars($ca['subject']), $ca['expires'] ? Html::convDate($ca['expires']) : '?')
                    . "</div>";
            } catch (\Throwable $e) {
                echo "<div style='margin-bottom:4px;color:#c92a2a;'>" . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
        echo "<textarea name='ad_ca_cert' rows='5' class='form-control' spellcheck='false' "
            . "style='width:100%;font-family:monospace;font-size:11px;' "
            . "placeholder='-----BEGIN CERTIFICATE-----'>"
            . Util::esc($f['ad_ca_cert'] ?? '') . "</textarea>";
        echo "<i class='text-muted'>" .
            __('Certificado raiz da autoridade certificadora interna (formato Base-64). Não é secreto. Sem ele, a conexão é recusada.', 'hrvacation') .
            "</i></td></tr>";

        echo "<tr class='tab_bg_1'><td>" . __('Compatibilidade com AC legada', 'hrvacation') . "</td><td>";
        echo "<label style='display:flex;align-items:center;gap:8px;'>";
        echo "<input type='checkbox' name='ad_allow_sha1' value='1'"
            . (!empty($f['ad_allow_sha1']) ? ' checked' : '') . " style='width:18px;height:18px;'>";
        echo __('Aceitar certificado assinado com SHA-1', 'hrvacation');
        echo "</label>";
        echo "<i class='text-muted'>" .
            __('Use apenas se a AC interna assina com SHA-1. A AC e o nome do servidor continuam sendo verificados, com TLS 1.2 ou superior. Desmarque quando a AC passar a usar SHA-256.', 'hrvacation') .
            "</i></td></tr>";

        echo "<tr class='tab_bg_1'><td></td><td>";
        echo Html::submit(__('Salvar e testar conexão com o AD', 'hrvacation'),
            ['name' => 'test_ad', 'class' => 'btn btn-outline-secondary']);
        echo "<br><i class='text-muted'>" .
            __('Confere a conexão, a autenticação e a permissão na OU, sem alterar nenhuma conta.', 'hrvacation') .
            "</i></td></tr>";

        echo "<tr class='tab_bg_2'>";
        echo "<td colspan='2' style='text-align:center;'>";
        echo Html::hidden('id', ['value' => 1]);
        echo Html::submit(_x('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
        echo "</td></tr>";

        echo "</table>";
        Html::closeForm();
    }
}
