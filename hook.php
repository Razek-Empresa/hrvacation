<?php

/**
 * Hooks de instalação e desinstalação do plugin.
 */

use GlpiPlugin\Hrvacation\Config;
use GlpiPlugin\Hrvacation\Period;
/**
 * Instalação: cria tabelas, direitos de perfil, configuração padrão e cron.
 *
 * @return boolean
 */
function plugin_hrvacation_install()
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    // --- Tabela de períodos de férias ---------------------------------------
    if (!$DB->tableExists('glpi_plugin_hrvacation_periods')) {
        $query = "CREATE TABLE `glpi_plugin_hrvacation_periods` (
            `id`                   int {$sign} NOT NULL AUTO_INCREMENT,
            `entities_id`          int {$sign} NOT NULL DEFAULT '0',
            `is_recursive`         tinyint     NOT NULL DEFAULT '0',
            `users_id`             int {$sign} NOT NULL DEFAULT '0',
            `is_fracionado`        tinyint     NOT NULL DEFAULT '0',
            `date_start`           date                 DEFAULT NULL,
            `days_count`           smallint    NOT NULL DEFAULT '0',
            `date_end`             date                 DEFAULT NULL,
            `block_ticket_id`      int {$sign} NOT NULL DEFAULT '0',
            `unblock_ticket_id`    int {$sign} NOT NULL DEFAULT '0',
            `date_start2`          date                 DEFAULT NULL,
            `days_count2`          smallint    NOT NULL DEFAULT '0',
            `date_end2`            date                 DEFAULT NULL,
            `block_ticket_id2`     int {$sign} NOT NULL DEFAULT '0',
            `unblock_ticket_id2`   int {$sign} NOT NULL DEFAULT '0',
            `date_start3`          date                 DEFAULT NULL,
            `days_count3`          smallint    NOT NULL DEFAULT '0',
            `date_end3`            date                 DEFAULT NULL,
            `block_ticket_id3`     int {$sign} NOT NULL DEFAULT '0',
            `unblock_ticket_id3`   int {$sign} NOT NULL DEFAULT '0',
            `users_id_redirect`    int {$sign} NOT NULL DEFAULT '0',
            `comment`              text,
            `is_deleted`           tinyint     NOT NULL DEFAULT '0',
            `users_id_recipient`   int {$sign} NOT NULL DEFAULT '0',
            `date_creation`        timestamp   NULL     DEFAULT NULL,
            `date_mod`             timestamp   NULL     DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `users_id` (`users_id`),
            KEY `date_start` (`date_start`),
            KEY `date_end` (`date_end`),
            KEY `is_deleted` (`is_deleted`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;";
        $DB->doQuery($query);
    }

    // --- Tabela de configuração (linha única id=1) --------------------------
    if (!$DB->tableExists('glpi_plugin_hrvacation_configs')) {
        $query = "CREATE TABLE `glpi_plugin_hrvacation_configs` (
            `id`                       int {$sign} NOT NULL AUTO_INCREMENT,
            `block_lead_days`          int         NOT NULL DEFAULT '0',
            `unblock_lead_days`        int         NOT NULL DEFAULT '0',
            `itilcategories_id_block`  int {$sign} NOT NULL DEFAULT '0',
            `itilcategories_id_unblock`int {$sign} NOT NULL DEFAULT '0',
            `groups_id_assign`         int {$sign} NOT NULL DEFAULT '0',
            `ticket_type`              int         NOT NULL DEFAULT '2',
            `block_tasks`              text,
            `unblock_tasks`            text,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;";
        $DB->doQuery($query);

        $DB->insert('glpi_plugin_hrvacation_configs', [
            'id'                        => 1,
            'block_lead_days'           => 0,
            'unblock_lead_days'         => 0,
            'itilcategories_id_block'   => 0,
            'itilcategories_id_unblock' => 0,
            'groups_id_assign'          => 0,
            'ticket_type'               => 2, // Ticket::DEMAND_TYPE (Requisição)
            'block_tasks'               => Config::getDefaultBlockTasks(),
            'unblock_tasks'             => Config::getDefaultUnblockTasks(),
        ]);
    }

    // --- Tabela de execuções (auditoria da automação) ------------------------
    if (!$DB->tableExists('glpi_plugin_hrvacation_executions')) {
        $query = "CREATE TABLE `glpi_plugin_hrvacation_executions` (
            `id`           int {$sign} NOT NULL AUTO_INCREMENT,
            `periods_id`   int {$sign} NOT NULL DEFAULT '0',
            `period_num`   tinyint     NOT NULL DEFAULT '1',
            `action`       varchar(20)          DEFAULT NULL,
            `target`       varchar(20)          DEFAULT NULL,
            `status`       varchar(20)          DEFAULT NULL,
            `message`      text,
            `tickets_id`   int {$sign} NOT NULL DEFAULT '0',
            `date_creation` timestamp  NULL     DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `periods_id` (`periods_id`),
            KEY `action` (`action`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;";
        $DB->doQuery($query);
    }

    // --- Migração de instalações já existentes ------------------------------
    $migration = new Migration(PLUGIN_HRVACATION_VERSION);

    // Campos existentes anteriores
    if (!$DB->fieldExists('glpi_plugin_hrvacation_configs', 'block_tasks')) {
        $migration->addField('glpi_plugin_hrvacation_configs', 'block_tasks', 'text');
    }
    if (!$DB->fieldExists('glpi_plugin_hrvacation_configs', 'unblock_tasks')) {
        $migration->addField('glpi_plugin_hrvacation_configs', 'unblock_tasks', 'text');
    }
    if (!$DB->fieldExists('glpi_plugin_hrvacation_periods', 'users_id_redirect')) {
        $migration->addField('glpi_plugin_hrvacation_periods', 'users_id_redirect',
            "int {$sign} NOT NULL DEFAULT '0'", ['after' => 'unblock_ticket_id']);
        $migration->addKey('glpi_plugin_hrvacation_periods', 'users_id_redirect');
    }
    if ($DB->fieldExists('glpi_plugin_hrvacation_periods', 'email_redirect')) {
        $migration->dropField('glpi_plugin_hrvacation_periods', 'email_redirect');
    }

    // Novos campos: fracionado + períodos 2 e 3
    $t = 'glpi_plugin_hrvacation_periods';
    if (!$DB->fieldExists($t, 'is_fracionado')) {
        $migration->addField($t, 'is_fracionado', "tinyint NOT NULL DEFAULT '0'", ['after' => 'users_id']);
    }
    if (!$DB->fieldExists($t, 'days_count')) {
        $migration->addField($t, 'days_count', "smallint NOT NULL DEFAULT '0'", ['after' => 'date_start']);
    }
    foreach ([2, 3] as $n) {
        if (!$DB->fieldExists($t, "date_start{$n}")) {
            $migration->addField($t, "date_start{$n}", 'date', ['after' => "unblock_ticket_id"]);
        }
        if (!$DB->fieldExists($t, "days_count{$n}")) {
            $migration->addField($t, "days_count{$n}", "smallint NOT NULL DEFAULT '0'", ['after' => "date_start{$n}"]);
        }
        if (!$DB->fieldExists($t, "date_end{$n}")) {
            $migration->addField($t, "date_end{$n}", 'date', ['after' => "days_count{$n}"]);
        }
        if (!$DB->fieldExists($t, "block_ticket_id{$n}")) {
            $migration->addField($t, "block_ticket_id{$n}", "int {$sign} NOT NULL DEFAULT '0'", ['after' => "date_end{$n}"]);
        }
        if (!$DB->fieldExists($t, "unblock_ticket_id{$n}")) {
            $migration->addField($t, "unblock_ticket_id{$n}", "int {$sign} NOT NULL DEFAULT '0'", ['after' => "block_ticket_id{$n}"]);
        }
    }
    // Campos de automação (worker AD / Microsoft 365)
    $c = 'glpi_plugin_hrvacation_configs';
    if (!$DB->fieldExists($c, 'automation_enabled')) {
        $migration->addField($c, 'automation_enabled', "tinyint NOT NULL DEFAULT '0'");
    }
    if (!$DB->fieldExists($c, 'automation_dryrun')) {
        $migration->addField($c, 'automation_dryrun', "tinyint NOT NULL DEFAULT '1'");
    }
    // Token do antigo worker: não é mais usado (tudo roda dentro do GLPI).
    if ($DB->fieldExists($c, 'automation_token')) {
        $migration->dropField($c, 'automation_token');
    }

    // Active Directory (LDAPS). A senha é gravada criptografada (GLPIKey).
    foreach ([
        'ad_host'              => 'string',
        'ad_base_dn'           => 'string',
        'ad_bind_user'         => 'string',
        'ad_bind_password'     => 'text',
        'ad_ca_cert'           => 'text',
    ] as $field => $type) {
        if (!$DB->fieldExists($c, $field)) {
            $migration->addField($c, $field, $type);
        }
    }
    $autoreply_new = !$DB->fieldExists($c, 'autoreply_message');
    if (!$DB->fieldExists($c, 'automation_autoreply')) {
        $migration->addField($c, 'automation_autoreply', "tinyint NOT NULL DEFAULT '0'");
    }
    if ($autoreply_new) {
        $migration->addField($c, 'autoreply_message', 'text');
    }
    // Regra da resposta automática (padrões = comportamento original).
    foreach ([
        'autoreply_start_hour' => "tinyint NOT NULL DEFAULT '0'",
        'autoreply_end_hour'   => "tinyint NOT NULL DEFAULT '0'",
        'autoreply_timezone'   => "varchar(64) NOT NULL DEFAULT 'E. South America Standard Time'",
        'autoreply_audience'   => "varchar(20) NOT NULL DEFAULT 'none'",
        'autoreply_on_unblock' => "varchar(10) NOT NULL DEFAULT 'keep'",
        'autoreply_on_delete'  => "tinyint NOT NULL DEFAULT '1'",
        'autoreply_overwrite'  => "tinyint NOT NULL DEFAULT '1'",
    ] as $field => $type) {
        if (!$DB->fieldExists($c, $field)) {
            $migration->addField($c, $field, $type);
        }
    }
    if (!$DB->fieldExists($c, 'ad_allow_sha1')) {
        $migration->addField($c, 'ad_allow_sha1', "tinyint NOT NULL DEFAULT '0'");
    }
    if (!$DB->fieldExists($c, 'ad_port')) {
        $migration->addField($c, 'ad_port', "int NOT NULL DEFAULT '636'");
    }
    if (!$DB->fieldExists($c, 'ad_password_updated')) {
        $migration->addField($c, 'ad_password_updated', "timestamp NULL DEFAULT NULL");
    }
    if (!$DB->fieldExists($c, 'automation_max_per_run')) {
        $migration->addField($c, 'automation_max_per_run', "int NOT NULL DEFAULT '5'");
    }
    if (!$DB->fieldExists($c, 'automation_exclude')) {
        $migration->addField($c, 'automation_exclude', 'text');
    }
    if (!$DB->fieldExists($c, 'automation_o365')) {
        $migration->addField($c, 'automation_o365', "tinyint NOT NULL DEFAULT '0'");
    }
    if (!$DB->fieldExists($c, 'automation_ad')) {
        $migration->addField($c, 'automation_ad', "tinyint NOT NULL DEFAULT '0'");
    }
    $since_new = !$DB->fieldExists($c, 'automation_since');
    if ($since_new) {
        $migration->addField($c, 'automation_since', "timestamp NULL DEFAULT NULL");
    }

    // Microsoft 365 (Entra ID). O client secret é gravado criptografado (GLPIKey).
    if (!$DB->fieldExists($c, 'm365_tenant_id')) {
        $migration->addField($c, 'm365_tenant_id', 'string');
    }
    if (!$DB->fieldExists($c, 'm365_client_id')) {
        $migration->addField($c, 'm365_client_id', 'string');
    }
    if (!$DB->fieldExists($c, 'm365_client_secret')) {
        $migration->addField($c, 'm365_client_secret', 'text');
    }
    if (!$DB->fieldExists($c, 'm365_secret_updated')) {
        $migration->addField($c, 'm365_secret_updated', "timestamp NULL DEFAULT NULL");
    }
    if (!$DB->fieldExists($c, 'm365_secret_expires')) {
        $migration->addField($c, 'm365_secret_expires', "date DEFAULT NULL");
    }
    if ($DB->fieldExists($c, 'm365_fallback_domain')) {
        $migration->dropField($c, 'm365_fallback_domain');
    }
    $migration->executeMigration();

    // Modelo padrão da resposta automática.
    if (!empty($autoreply_new)) {
        $DB->update('glpi_plugin_hrvacation_configs',
            ['autoreply_message' => \GlpiPlugin\Hrvacation\Automation::defaultAutoReplyMessage()], ['id' => 1]);
    }

    // Data de corte da automação: o histórico anterior não é processado.
    if (!empty($since_new)) {
        $DB->update('glpi_plugin_hrvacation_configs', ['automation_since' => date('Y-m-d H:i:s')], ['id' => 1]);
    }


    // --- Preferências de exibição (colunas padrão da listagem) --------------
    // Remove todas as preferências (globais e pessoais) para forçar o padrão
    // correto com ID, Colaborador, Início e Término.
    $DB->delete('glpi_displaypreferences', ['itemtype' => Period::class]);
    foreach ([2 => 1, 3 => 2, 4 => 3] as $num => $rank) {
        $dp = new DisplayPreference();
        $dp->add([
            'itemtype' => Period::class,
            'num'      => $num,
            'rank'     => $rank,
            'users_id' => 0,
        ]);
    }

    // Preenche os valores padrão de tarefas se ainda estiverem vazios.
    $cfg = new Config();
    if ($cfg->getFromDB(1)) {
        $toset = [];
        if (empty($cfg->fields['block_tasks'])) {
            $toset['block_tasks'] = Config::getDefaultBlockTasks();
        }
        if (empty($cfg->fields['unblock_tasks'])) {
            $toset['unblock_tasks'] = Config::getDefaultUnblockTasks();
        }
        if (!empty($toset)) {
            $toset['id'] = 1;
            $cfg->update($toset);
        }
    }

    // --- Direitos de perfil (idempotente) -----------------------------------
    // Só cria o direito se ele ainda não existir, evitando "Duplicate entry"
    // quando o install roda de novo durante uma atualização.
    $right_exists = (int) ($DB->request([
        'COUNT' => 'cpt',
        'FROM'  => 'glpi_profilerights',
        'WHERE' => ['name' => 'plugin_hrvacation_period'],
    ])->current()['cpt'] ?? 0);

    if ($right_exists === 0) {
        // Adiciona o direito a TODOS os perfis com valor 0 (ninguém vê por padrão)...
        ProfileRight::addProfileRights(['plugin_hrvacation_period']);
        // ...e concede acesso total ao perfil Super-Admin (id 4) para começar.
        $DB->update(
            'glpi_profilerights',
            ['rights' => ALLSTANDARDRIGHT],
            [
                'name'        => 'plugin_hrvacation_period',
                'profiles_id' => 4,
            ]
        );
    }

    // --- Tarefa automática (cron) -------------------------------------------
    // Modo INTERNAL (GLPI): roda durante o uso normal do sistema, sem precisar
    // Tarefa automática diária.
    
    // Registra a tarefa em modo EXTERNAL (CLI), adequado para disparo via
    // crontab (front/cron.php ou bin/console). O modo definido pelo
    // administrador em Configurar > Ações automáticas é preservado nas
    // atualizações — o Register só aplica o padrão na primeira criação.
    CronTask::Register(
        Period::class,
        'vacationTickets',
        DAY_TIMESTAMP,
        [
            'comment' => 'Abre chamados de bloqueio/liberação de acessos conforme os afastamentos cadastrados',
            'mode'    => CronTask::MODE_EXTERNAL,
            'state'   => CronTask::STATE_WAITING,
        ]
    );

    return true;
}

/**
 * Desinstalação: remove tabelas, direitos e cron.
 *
 * @return boolean
 */
function plugin_hrvacation_uninstall()
{
    global $DB;

    foreach (['glpi_plugin_hrvacation_periods', 'glpi_plugin_hrvacation_configs',
              'glpi_plugin_hrvacation_executions'] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    // Remove a tarefa automática.
    $cron = new CronTask();
    $cron->deleteByCriteria(['itemtype' => Period::class]);

    // Remove as preferências de exibição da listagem.
    $DB->delete('glpi_displaypreferences', ['itemtype' => Period::class]);

    // Remove os direitos dos perfis.
    if (method_exists('ProfileRight', 'deleteProfileRights')) {
        ProfileRight::deleteProfileRights(['plugin_hrvacation_period']);
    } else {
        $DB->delete('glpi_profilerights', ['name' => 'plugin_hrvacation_period']);
    }

    return true;
}
