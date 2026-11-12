<?php

namespace GlpiPlugin\Hrvacation;

use CommonDBTM;
use CommonGLPI;
use Html;
use Profile as Glpi_Profile;
use ProfileRight;
use Session;

/**
 * Exibe e permite editar o direito do plugin no formulário de Perfil
 * (Administração > Perfis > aba "Afastamentos").
 *
 * Segue o padrão do GLPI 11: estende CommonDBTM, getTabNameForItem é método de
 * instância e displayTabContentForItem é estático.
 */
class Profile extends CommonDBTM
{
    /**
     * Usa o direito padrão de "perfil" do GLPI para controlar a edição.
     *
     * Tipado como "string" por exigência do GLPI 12 (ver Period::$rightname).
     */
    public static string $rightname = 'profile';

    public static function getTypeName($nb = 0)
    {
        return __('Afastamentos', 'hrvacation');
    }

    /**
     * Nome da aba no formulário de Perfil (método de instância no GLPI 11).
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Glpi_Profile && $item->getField('id')) {
            return self::createTabEntry(self::getTypeName());
        }
        return '';
    }

    /**
     * Conteúdo da aba: matriz de direitos do plugin para o perfil.
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Glpi_Profile && $item->getField('id')) {
            self::addDefaultProfileInfos(
                (int) $item->getID(),
                ['plugin_hrvacation_period' => 0]
            );
            self::showForProfile((int) $item->getID());
        }
        return true;
    }

    /**
     * Garante que o direito exista para o perfil (sem sobrescrever quem já tem).
     */
    public static function addDefaultProfileInfos($profiles_id, $rights, $drop_existing = false)
    {
        $profileRight = new ProfileRight();
        foreach ($rights as $right => $value) {
            $exists = countElementsInTable(
                'glpi_profilerights',
                ['profiles_id' => $profiles_id, 'name' => $right]
            );
            if ($exists && $drop_existing) {
                $profileRight->deleteByCriteria(['profiles_id' => $profiles_id, 'name' => $right]);
                $exists = 0;
            }
            if (!$exists) {
                $profileRight->add([
                    'profiles_id' => $profiles_id,
                    'name'        => $right,
                    'rights'      => $value,
                ]);
                if (isset($_SESSION['glpiactiveprofile']['id'])
                    && (int) $_SESSION['glpiactiveprofile']['id'] === (int) $profiles_id) {
                    $_SESSION['glpiactiveprofile'][$right] = $value;
                }
            }
        }
    }

    /**
     * Atualiza o direito do plugin na sessão ao trocar de perfil.
     */
    public static function initProfile()
    {
        if (isset($_SESSION['glpiactiveprofile']['id'])) {
            self::addDefaultProfileInfos(
                (int) $_SESSION['glpiactiveprofile']['id'],
                ['plugin_hrvacation_period' => 0]
            );
        }
    }

    /**
     * Renderiza a matriz de checkboxes do direito do plugin para um perfil.
     */
    public static function showForProfile($profiles_id = 0)
    {
        $canedit = Session::haveRightsOr('profile', [CREATE, UPDATE, PURGE]);

        echo "<div class='firstbloc'>";
        if ($canedit) {
            $form = new Glpi_Profile();
            echo "<form method='post' action='" . $form->getFormURL() . "'>";
        }

        $profile = new Glpi_Profile();
        $profile->getFromDB($profiles_id);

        $rights = [
            [
                'itemtype' => Period::class,
                'label'    => Period::getTypeName(Session::getPluralNumber()),
                'field'    => 'plugin_hrvacation_period',
            ],
        ];

        $profile->displayRightsChoiceMatrix($rights, [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => __('Afastamentos', 'hrvacation'),
        ]);

        if ($canedit) {
            echo "<div class='center'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo "</div>";
            Html::closeForm();
        }

        echo "</div>";
    }
}
