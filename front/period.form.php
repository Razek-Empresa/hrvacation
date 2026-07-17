<?php

if (defined('GLPI_ROOT')) {
    include(GLPI_ROOT . '/inc/includes.php');
} else {
    include('../../../inc/includes.php');
}

use GlpiPlugin\Hrvacation\Period;

$period = new Period();

if (isset($_POST['add'])) {
    $period->check(-1, CREATE, $_POST);
    $newid = $period->add($_POST);
    Html::redirect($period->getFormURLWithID($newid));
} elseif (isset($_POST['update'])) {
    $period->check($_POST['id'], UPDATE);
    $period->update($_POST);
    Html::back();
} elseif (isset($_POST['delete'])) {
    $period->check($_POST['id'], DELETE);
    $period->delete($_POST);
    $period->redirectToList();
} elseif (isset($_POST['purge'])) {
    $period->check($_POST['id'], PURGE);
    $period->delete($_POST, 1);
    $period->redirectToList();
} elseif (isset($_POST['restore'])) {
    $period->check($_POST['id'], DELETE);
    $period->restore($_POST);
    Html::back();
} else {
    Session::checkRight('plugin_hrvacation_period', READ);

    $interface    = $_SESSION['glpiactiveprofile']['interface'] ?? 'central';
    $is_helpdesk  = ($interface === 'helpdesk');
    $menu_type    = $is_helpdesk ? 'helpdesk' : 'tools';
    $id           = isset($_GET['id']) ? (int) $_GET['id'] : -1;

    Html::header(
        Period::getTypeName(1),
        $_SERVER['PHP_SELF'],
        $menu_type,
        Period::class
    );

    // Barra de navegação para a interface simplificada.
    if ($is_helpdesk) {
        $list_url = Period::getSearchURL(false);
        $base     = Period::getFormURL(false);
        $cal      = '/plugins/hrvacation/front/calendar.php';
        $tl       = '/plugins/hrvacation/front/timeline.php';

        echo "<div style='display:flex;align-items:center;gap:8px;padding:12px 16px;"
            . "background:#fff;border-bottom:1px solid #e5e7eb;flex-wrap:wrap;'>";

        // Voltar para a lista.
        echo "<a href='" . htmlspecialchars($list_url) . "' "
            . "style='display:inline-flex;align-items:center;gap:4px;background:#f3f4f6;"
            . "color:#374151;border-radius:5px;padding:6px 14px;text-decoration:none;"
            . "font-size:13px;border:1px solid #d1d5db;'>"
            . "<i class='ti ti-list'></i> " . __('Lista', 'hrvacation') . "</a>";

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

    $period->display(['id' => $id]);

    Html::footer();
}
