<?php

// A partir do GLPI 11 o núcleo já está carregado quando este arquivo é
// executado, e inc/includes.php apenas registra um aviso de obsolescência.
// Só carregamos o bootstrap legado se o GLPI realmente não estiver inicializado.
if (!class_exists('Session')) {
    include(defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : '../../../inc/includes.php');
}

use GlpiPlugin\Hrvacation\AdLdap;
use GlpiPlugin\Hrvacation\Config;
use GlpiPlugin\Hrvacation\M365;

Session::checkRight('config', UPDATE);

$config = new Config();
$self   = '/plugins/hrvacation/front/config.form.php';

if (isset($_POST['update']) || isset($_POST['test_m365']) || isset($_POST['test_ad'])) {
    $config->check(1, UPDATE);

    $input = $_POST;

    // Checkboxes desmarcados não são enviados pelo navegador — normaliza para 0.
    foreach (['automation_enabled', 'automation_dryrun', 'automation_o365', 'automation_ad', 'ad_allow_sha1', 'automation_autoreply', 'autoreply_on_delete', 'autoreply_overwrite'] as $cb) {
        $input[$cb] = empty($input[$cb]) ? 0 : 1;
    }

    // Ao ativar a automação, só chamados abertos a partir de agora são processados.
    $current = Config::getConfig();
    if ($input['automation_enabled'] && empty($current['automation_enabled'])) {
        $input['automation_since'] = date('Y-m-d H:i:s');
    }

    // ---- Client secret: nunca gravado em texto puro -------------------------
    $new_secret = trim((string) ($input['m365_client_secret'] ?? ''));
    $clear      = !empty($input['m365_secret_clear']);
    unset($input['m365_client_secret'], $input['m365_secret_clear']);

    if ($clear) {
        $input['m365_client_secret']  = '';
        $input['m365_secret_updated'] = null;
    } elseif ($new_secret !== '') {
        // Confusão comum: colar o "ID do segredo" (GUID) em vez do "Valor".
        if (preg_match('/^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $new_secret)) {
            Session::addMessageAfterRedirect(
                __('Isso parece o ID do segredo, não o VALOR. No Entra ID, copie a coluna "Valor". Segredo não salvo.', 'hrvacation'),
                false,
                ERROR
            );
        } else {
            $input['m365_client_secret']  = M365::encryptSecret($new_secret);
            $input['m365_secret_updated'] = date('Y-m-d H:i:s');
        }
    }
    unset($new_secret);

    // ---- Validação dos identificadores --------------------------------------
    $guid = '/^[0-9a-f]{8}-([0-9a-f]{4}-){3}[0-9a-f]{12}$/i';
    foreach (['m365_tenant_id' => 'Tenant ID', 'm365_client_id' => 'Client ID'] as $field => $label) {
        if (!array_key_exists($field, $input)) {
            continue;
        }
        $input[$field] = trim((string) $input[$field]);
        if ($input[$field] !== '' && !preg_match($guid, $input[$field])) {
            Session::addMessageAfterRedirect(
                sprintf(__('%s inválido: deve estar no formato 00000000-0000-0000-0000-000000000000.', 'hrvacation'), $label),
                false,
                ERROR
            );
            unset($input[$field]);
        }
    }

    if (isset($input['m365_secret_expires']) && $input['m365_secret_expires'] === '') {
        $input['m365_secret_expires'] = null;
    }

    // ---- Regra da resposta automática: só aceita valores válidos ------------
    foreach (['autoreply_start_hour', 'autoreply_end_hour'] as $f) {
        if (array_key_exists($f, $input)) {
            $input[$f] = max(0, min(23, (int) $input[$f]));
        }
    }
    foreach ([
        'autoreply_timezone'   => \GlpiPlugin\Hrvacation\AutoReplyRule::TIMEZONES,
        'autoreply_audience'   => \GlpiPlugin\Hrvacation\AutoReplyRule::AUDIENCES,
        'autoreply_on_unblock' => \GlpiPlugin\Hrvacation\AutoReplyRule::ON_UNBLOCK,
    ] as $f => $allowed) {
        if (array_key_exists($f, $input) && !isset($allowed[(string) $input[$f]])) {
            unset($input[$f]);
        }
    }

    // ---- Active Directory: senha nunca gravada em texto puro ---------------
    $new_pwd   = (string) ($input['ad_bind_password'] ?? '');
    $clear_pwd = !empty($input['ad_password_clear']);
    unset($input['ad_bind_password'], $input['ad_password_clear']);

    if ($clear_pwd) {
        $input['ad_bind_password']    = '';
        $input['ad_password_updated'] = null;
    } elseif ($new_pwd !== '') {
        $input['ad_bind_password']    = AdLdap::encryptSecret($new_pwd);
        $input['ad_password_updated'] = date('Y-m-d H:i:s');
    }
    unset($new_pwd);

    if (array_key_exists('ad_host', $input)) {
        $input['ad_host'] = trim((string) $input['ad_host']);
        $input['ad_host'] = preg_replace('#^ldaps?://#i', '', $input['ad_host']);
        if ($input['ad_host'] !== '' && !preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/i', $input['ad_host'])) {
            Session::addMessageAfterRedirect(__('Controlador de domínio inválido: informe apenas o nome, sem porta ou barras.', 'hrvacation'), false, ERROR);
            unset($input['ad_host']);
        } elseif (preg_match('/^\d+(\.\d+){3}$/', $input['ad_host'])) {
            Session::addMessageAfterRedirect(__('Atenção: use o nome do controlador (como no certificado), não o IP, ou a verificação do certificado falhará.', 'hrvacation'), false, WARNING);
        }
    }
    if (array_key_exists('ad_port', $input)) {
        $input['ad_port'] = max(1, min(65535, (int) $input['ad_port'])) ?: 636;
    }
    foreach (['ad_base_dn', 'ad_bind_user'] as $f) {
        if (array_key_exists($f, $input)) {
            $input[$f] = trim((string) $input[$f]);
        }
    }
    if (!empty($input['ad_base_dn']) && !preg_match('/(^|,)\s*DC=/i', $input['ad_base_dn'])) {
        Session::addMessageAfterRedirect(__('A OU deve estar no formato OU=...,DC=...,DC=...', 'hrvacation'), false, ERROR);
        unset($input['ad_base_dn']);
    }
    if (array_key_exists('ad_ca_cert', $input)) {
        $input['ad_ca_cert'] = trim((string) $input['ad_ca_cert']);
        if ($input['ad_ca_cert'] !== '') {
            try {
                AdLdap::inspectCa($input['ad_ca_cert']);
            } catch (\Throwable $e) {
                Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
                unset($input['ad_ca_cert']);
            }
        }
    }

    $config->update($input + ['id' => 1]);

    // ---- Teste de conexão ----------------------------------------------------
    if (isset($_POST['test_m365'])) {
        try {
            $r = M365::test(Config::getConfig());
            Session::addMessageAfterRedirect(
                implode('<br>', array_map('htmlspecialchars', $r['messages'])),
                false,
                $r['ok'] ? INFO : ERROR
            );
        } catch (\Throwable $e) {
            Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
        }
    }

    if (isset($_POST['test_ad'])) {
        try {
            $r = AdLdap::test(Config::getConfig());
            Session::addMessageAfterRedirect(
                implode('<br>', array_map('htmlspecialchars', $r['messages'])),
                false,
                $r['ok'] ? INFO : ERROR
            );
        } catch (\Throwable $e) {
            Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
        }
    }

    Html::redirect($self);
}

Html::header(
    Config::getTypeName(),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

$config->showConfigForm();

Html::footer();
