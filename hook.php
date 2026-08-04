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
    $migration->executeMigration();

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
    // de crontab. Para timing preciso em produção, recomenda-se trocar para
    // modo CLI em Configurar > Ações automáticas e agendar bin/console glpi:cron.
    CronTask::Register(
        Period::class,
        'vacationTickets',
        DAY_TIMESTAMP,
        [
            'comment' => 'Abre chamados de bloqueio/liberação de acessos conforme as férias cadastradas',
            'mode'    => CronTask::MODE_INTERNAL,
            'state'   => CronTask::STATE_WAITING,
        ]
    );

    // Garante o modo INTERNAL mesmo em instalações que já tinham o cron
    // registrado em modo CLI (externo) por versões anteriores do plugin.
    $DB->update(
        'glpi_crontasks',
        ['mode' => CronTask::MODE_INTERNAL],
        [
            'itemtype' => Period::class,
            'name'     => 'vacationTickets',
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

    foreach (['glpi_plugin_hrvacation_periods', 'glpi_plugin_hrvacation_configs'] as $table) {
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
