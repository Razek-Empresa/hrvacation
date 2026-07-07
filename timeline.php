<?php

if (defined('GLPI_ROOT')) {
    include(GLPI_ROOT . '/inc/includes.php');
} else {
    include('../../../inc/includes.php');
}

use GlpiPlugin\Hrvacation\Period;

Session::checkRight('plugin_hrvacation_period', READ);

$interface = $_SESSION['glpiactiveprofile']['interface'] ?? 'central';
$is_helpdesk = ($interface === 'helpdesk');
$menu_type   = $is_helpdesk ? 'helpdesk' : 'tools';

Html::header(
    Period::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    $menu_type,
    Period::class
);

// Na interface simplificada o breadcrumb/botões não aparecem automaticamente,
// então os renderizamos manualmente.
if ($is_helpdesk) {
    $base = Period::getFormURL(false);
    $cal  = '/plugins/hrvacation/front/calendar.php';
    $tl   = '/plugins/hrvacation/front/timeline.php';

    echo "<div style='display:flex;align-items:center;gap:8px;padding:12px 16px;"
        . "background:#fff;border-bottom:1px solid #e5e7eb;flex-wrap:wrap;'>";

    echo "<span style='font-weight:600;font-size:15px;color:#1f2937;margin-right:8px;'>"
        . Period::getTypeName(Session::getPluralNumber()) . "</span>";

    if (Session::haveRight('plugin_hrvacation_period', CREATE)) {
        echo "<a href='" . htmlspecialchars($base) . "?id=-1' "
            . "style='display:inline-flex;align-items:center;gap:4px;background:#e63946;"
            . "color:#fff;border-radius:5px;padding:6px 14px;text-decoration:none;"
            . "font-size:13px;font-weight:600;'>"
            . "<i class='ti ti-plus'></i> " . __('Adicionar', 'hrvacation') . "</a>";
    }

    echo "<a href='" . htmlspecialchars($cal) . "' "
        . "style='display:inline-flex;align-items:center;gap:4px;background:#f3f4f6;"
        . "color:#374151;border-radius:5px;padding:6px 14px;text-decoration:none;"
        . "font-size:13px;border:1px solid #d1d5db;'>"
        . "<i class='ti ti-calendar'></i> " . __('Calendário', 'hrvacation') . "</a>";

    echo "<a href='" . htmlspecialchars($tl) . "' "
        . "style='display:inline-flex;align-items:center;gap:4px;background:#f3f4f6;"
        . "color:#374151;border-radius:5px;padding:6px 14px;text-decoration:none;"
        . "font-size:13px;border:1px solid #d1d5db;'>"
        . "<i class='ti ti-timeline'></i> " . __('Linha do tempo', 'hrvacation') . "</a>";

    echo "</div>";
}

// Listagem dos afastamentos com nome do colaborador.
Period::showList();

Html::footer();
