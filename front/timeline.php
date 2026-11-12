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
