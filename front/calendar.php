<?php

// A partir do GLPI 11 o núcleo já está carregado quando este arquivo é
// executado, e inc/includes.php apenas registra um aviso de obsolescência.
// Só carregamos o bootstrap legado se o GLPI realmente não estiver inicializado.
if (!class_exists('Session')) {
    include(defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : '../../../inc/includes.php');
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
