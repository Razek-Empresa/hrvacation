<?php

if (defined('GLPI_ROOT')) {
    include(GLPI_ROOT . '/inc/includes.php');
} else {
    include('../../../inc/includes.php');
}

use GlpiPlugin\Hrvacation\Config;

Session::checkRight('config', UPDATE);

$config = new Config();

if (isset($_POST['update'])) {
    $config->check(1, UPDATE);
    $config->update($_POST + ['id' => 1]);
    Html::redirect('/plugins/hrvacation/front/config.form.php');
}

Html::header(
    Config::getTypeName(),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

$config->showConfigForm();

Html::footer();
