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
    __('Calendário de afastamentos', 'hrvacation'),
    $_SERVER['PHP_SELF'],
    $menu_type,
    Period::class
);

$month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n');
$year  = isset($_GET['year'])  ? (int) $_GET['year']  : (int) date('Y');

Period::showCalendar($year, $month);

Html::footer();
