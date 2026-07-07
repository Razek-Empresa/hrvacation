<?php

/**
 * Plugin HR Vacation (Férias / Bloqueio de acessos) para GLPI.
 *
 * Permite ao RH cadastrar períodos de férias num calendário e, com base nas
 * datas, abre automaticamente um chamado para BLOQUEIO dos acessos no início
 * das férias e outro para LIBERAÇÃO dos acessos no retorno.
 *
 * Compatível com GLPI 10.0.x.
 */

use GlpiPlugin\Hrvacation\Period;
use GlpiPlugin\Hrvacation\Profile;

define('PLUGIN_HRVACATION_VERSION', '2.1.0');
define('PLUGIN_HRVACATION_MIN_GLPI', '10.0.0');

/**
 * Inicialização do plugin: registra hooks, menus e direitos.
 */
function plugin_init_hrvacation()
{
    global $PLUGIN_HOOKS;

    // Obrigatório para o GLPI aceitar os formulários do plugin.
    $PLUGIN_HOOKS['csrf_compliant']['hrvacation'] = true;

    // Exibe o direito do plugin na tela de Perfis (aba "Afastamentos").
    Plugin::registerClass(Profile::class, ['addtabon' => 'Profile']);
    $PLUGIN_HOOKS['change_profile']['hrvacation'] = [Profile::class, 'initProfile'];

    // Carrega o direito do plugin na sessão ativa.
    // O GLPI só popula $_SESSION['glpiactiveprofile'] com direitos que estão
    // registrados no momento do login; plugins instalados depois precisam
    // injetar o valor manualmente a cada requisição.
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $profiles_id = (int) $_SESSION['glpiactiveprofile']['id'];
        if (!isset($_SESSION['glpiactiveprofile']['plugin_hrvacation_period'])) {
            global $DB;
            $row = $DB->request([
                'FROM'  => 'glpi_profilerights',
                'WHERE' => [
                    'profiles_id' => $profiles_id,
                    'name'        => 'plugin_hrvacation_period',
                ],
            ])->current();
            $_SESSION['glpiactiveprofile']['plugin_hrvacation_period'] =
                $row ? (int) $row['rights'] : 0;
        }
    }

    // Adiciona a entrada "Afastamentos" no menu "Plugins" da interface
    // simplificada (self-service). Hook dedicado a esse fim no GLPI.
    $PLUGIN_HOOKS['helpdesk_menu_entry']['hrvacation'] = '/front/period.php';

    if (Session::getLoginUserID()) {
        // Adiciona a entrada de menu (em "Ferramentas").
        $PLUGIN_HOOKS['menu_toadd']['hrvacation'] = [
            'tools' => Period::class,
        ];

        // Link da página de configuração (só para quem pode editar config).
        if (Session::haveRight('config', UPDATE)) {
            $PLUGIN_HOOKS['config_page']['hrvacation'] = 'front/config.form.php';
        }
    }
}

/**
 * Metadados e requisitos do plugin.
 *
 * @return array
 */
function plugin_version_hrvacation()
{
    return [
        'name'         => 'Afastamentos / Bloqueio de acessos',
        'version'      => PLUGIN_HRVACATION_VERSION,
        'author'       => 'TI Razek',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_HRVACATION_MIN_GLPI,
            ],
            'php' => [
                'min' => '7.4',
            ],
        ],
    ];
}

/**
 * Verificação de pré-requisitos antes da instalação.
 *
 * @return boolean
 */
function plugin_hrvacation_check_prerequisites()
{
    return true;
}

/**
 * Verificação de configuração antes da ativação.
 *
 * @param boolean $verbose
 * @return boolean
 */
function plugin_hrvacation_check_config($verbose = false)
{
    return true;
}
