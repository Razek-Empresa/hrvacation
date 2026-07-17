<?php

if (defined('GLPI_ROOT')) {
    include(GLPI_ROOT . '/inc/includes.php');
} else {
    include('../../../inc/includes.php');
}

use GlpiPlugin\Hrvacation\Period;

Session::checkRight('plugin_hrvacation_period', READ);

$interface = $_SESSION['glpiactiveprofile']['interface'] ?? 'central';
$menu_type = ($interface === 'helpdesk') ? 'helpdesk' : 'tools';

Html::header(
    __('Linha do tempo de afastamentos', 'hrvacation'),
    $_SERVER['PHP_SELF'],
    $menu_type,
    Period::class
);

$start = isset($_GET['start']) ? $_GET['start'] : date('Y-m-01');
$days  = isset($_GET['days'])  ? (int) $_GET['days'] : 90;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
    $start = date('Y-m-01');
}

Period::showTimeline($start, $days);

Html::footer();
