<?php

namespace GlpiPlugin\Hrvacation;

use GLPIKey;

/**
 * Bloqueio e liberação de contas no Active Directory local via LDAPS,
 * executados diretamente pelo GLPI.
 *
 * Segurança:
 *  - Conexão sempre criptografada (ldaps://) e com certificado VERIFICADO
 *    contra a autoridade certificadora interna configurada. Sem o certificado
 *    da AC, o plugin se recusa a conectar.
 *  - Senha da conta de serviço criptografada com a GLPIKey; descriptografada
 *    apenas em memória no momento do bind. Nunca exibida nem registrada.
 *  - Só altera contas encontradas DENTRO da OU configurada.
 *  - Recusa contas protegidas (adminCount=1: administradores e afins).
 *  - Altera apenas o bit ACCOUNTDISABLE do userAccountControl.
 */
class AdLdap
{
    /** Bit ACCOUNTDISABLE do userAccountControl. */
    const UAC_DISABLED = 0x2;

    /**
     * Compatibilidade com AC que assina com SHA-1 (opção legado).
     * SECLEVEL=0 é o único nível em que o OpenSSL 3 aceita assinatura SHA-1;
     * as cifras continuam restritas às fortes e o TLS mínimo é 1.2.
     * A AC e o nome do servidor continuam sendo verificados.
     */
    const LEGACY_CIPHERS = 'HIGH:!aNULL:!MD5:!RC4:@SECLEVEL=0';

    private static $link = null;
    private static $link_key = null;

    // ------------------------------------------------------------ segredo

    public static function isConfigured(array $c)
    {
        return !empty($c['ad_host'])
            && !empty($c['ad_base_dn'])
            && !empty($c['ad_bind_user'])
            && !empty($c['ad_bind_password'])
            && !empty($c['ad_ca_cert']);
    }

    public static function encryptSecret($plain)
    {
        return (new GLPIKey())->encrypt((string) $plain);
    }

    protected static function decryptSecret(array $c)
    {
        $plain = @(new GLPIKey())->decrypt((string) ($c['ad_bind_password'] ?? ''));
        if ($plain === null || $plain === '' || $plain === false) {
            throw new \RuntimeException(
                'Não foi possível descriptografar a senha da conta de serviço. '
                . 'Se a chave do GLPI foi alterada, cadastre a senha novamente.'
            );
        }
        return $plain;
    }

    // ---------------------------------------------------- certificado da AC

    /**
     * Valida um certificado PEM e devolve um resumo (sujeito e validade).
     *
     * @return array ['subject' => string, 'expires' => string]
     */
    public static function inspectCa($pem)
    {
        $pem = trim((string) $pem);
        if (strpos($pem, '-----BEGIN CERTIFICATE-----') === false) {
            throw new \RuntimeException('O certificado deve estar no formato PEM (Base-64), começando com -----BEGIN CERTIFICATE-----.');
        }
        if (function_exists('openssl_x509_parse')) {
            $info = @openssl_x509_parse($pem);
            if (!$info) {
                throw new \RuntimeException('Certificado inválido ou corrompido.');
            }
            return [
                'subject' => $info['subject']['CN'] ?? ($info['name'] ?? '?'),
                'expires' => date('Y-m-d', (int) $info['validTo_time_t']),
            ];
        }
        return ['subject' => '?', 'expires' => ''];
    }

    /**
     * Grava o certificado da AC num arquivo (a biblioteca LDAP exige arquivo).
     * O certificado não é secreto; o arquivo só é regravado se mudar.
     */
    protected static function caFile(array $c)
    {
        $dir = (defined('GLPI_PLUGIN_DOC_DIR') ? GLPI_PLUGIN_DOC_DIR : sys_get_temp_dir()) . '/hrvacation';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            throw new \RuntimeException("Não foi possível criar o diretório {$dir}.");
        }
        $pem  = trim((string) $c['ad_ca_cert']) . "\n";
        $file = $dir . '/ad-ca.pem';
        if (!is_file($file) || hash_file('sha256', $file) !== hash('sha256', $pem)) {
            if (@file_put_contents($file, $pem, LOCK_EX) === false) {
                throw new \RuntimeException("Não foi possível gravar o certificado em {$file}.");
            }
            @chmod($file, 0640);
        }
        return $file;
    }

    // ------------------------------------------------------------- conexão

    /**
     * Conecta e autentica (uma vez por execução). Qualquer falha aqui
     * interrompe a rodada inteira, para não acumular tentativas de senha
     * errada e bloquear a conta de serviço pela política do domínio.
     */
    public static function connect(array $c)
    {
        if (!function_exists('ldap_connect')) {
            throw new \RuntimeException('Extensão LDAP do PHP indisponível no servidor do GLPI.');
        }
        if (!self::isConfigured($c)) {
            throw new \RuntimeException('Configuração do Active Directory incompleta.');
        }

        $key = $c['ad_host'] . '|' . $c['ad_bind_user'];
        if (self::$link !== null && self::$link_key === $key) {
            return self::$link;
        }

        $port   = (int) ($c['ad_port'] ?? 636) ?: 636;
        $cafile = self::caFile($c);

        // Algumas builds do PHP não oferecem contexto TLS por conexão
        // (LDAP_OPT_X_TLS_NEWCTX). Nesse caso, as opções de certificado são
        // aplicadas globalmente, ANTES de conectar. Continua exigindo
        // certificado válido emitido pela AC configurada.
        $per_link = defined('LDAP_OPT_X_TLS_NEWCTX');
        $legacy   = !empty($c['ad_allow_sha1']);
        if (!$per_link) {
            ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, $cafile);
            ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND);
            self::applyTlsHardening(null, $legacy);
        }

        $link = @ldap_connect('ldaps://' . $c['ad_host'] . ':' . $port);
        if ($link === false) {
            throw new \RuntimeException('Endereço do controlador de domínio inválido.');
        }

        ldap_set_option($link, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($link, LDAP_OPT_REFERRALS, 0);
        if (defined('LDAP_OPT_NETWORK_TIMEOUT')) {
            ldap_set_option($link, LDAP_OPT_NETWORK_TIMEOUT, 10);
        }
        ldap_set_option($link, LDAP_OPT_TIMELIMIT, 20);

        if ($per_link) {
            // Contexto TLS exclusivo desta conexão.
            ldap_set_option($link, LDAP_OPT_X_TLS_CACERTFILE, $cafile);
            ldap_set_option($link, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND);
            self::applyTlsHardening($link, $legacy);
            ldap_set_option($link, LDAP_OPT_X_TLS_NEWCTX, 0);
        }

        $password = self::decryptSecret($c);
        $ok = @ldap_bind($link, $c['ad_bind_user'], $password);
        unset($password);

        if (!$ok) {
            $errno = ldap_errno($link);
            $diag  = '';
            if (defined('LDAP_OPT_DIAGNOSTIC_MESSAGE')) {
                @ldap_get_option($link, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diag);
            }
            @ldap_unbind($link);

            if ($errno === -1) {
                throw new \RuntimeException(
                    'Não foi possível conectar ao controlador de domínio por LDAPS. '
                    . 'Verifique o endereço (deve ser o nome que consta no certificado), '
                    . 'a porta 636 e o certificado da AC.'
                    . ($legacy ? '' : ' Se a AC interna assina com SHA-1, habilite a opção de compatibilidade.')
                );
            }
            if ($errno === 49) {
                throw new \RuntimeException(
                    'Usuário ou senha da conta de serviço inválidos (ou conta bloqueada/expirada no AD).'
                );
            }
            throw new \RuntimeException('Falha na autenticação LDAP: ' . ldap_err2str($errno)
                . ($diag ? " ({$diag})" : ''));
        }

        self::$link     = $link;
        self::$link_key = $key;
        return $link;
    }

    /**
     * TLS 1.2 no mínimo e, se habilitado, aceitação de assinatura SHA-1.
     */
    protected static function applyTlsHardening($link, $legacy)
    {
        if (defined('LDAP_OPT_X_TLS_PROTOCOL_MIN') && defined('LDAP_OPT_X_TLS_PROTOCOL_TLS1_2')) {
            ldap_set_option($link, LDAP_OPT_X_TLS_PROTOCOL_MIN, LDAP_OPT_X_TLS_PROTOCOL_TLS1_2);
        }
        if ($legacy) {
            if (!defined('LDAP_OPT_X_TLS_CIPHER_SUITE')) {
                throw new \RuntimeException('Esta versão do PHP não permite ajustar a compatibilidade SHA-1.');
            }
            ldap_set_option($link, LDAP_OPT_X_TLS_CIPHER_SUITE, self::LEGACY_CIPHERS);
        }
    }

    // ---------------------------------------------------------- operações

    /**
     * Localiza a conta DENTRO da OU configurada.
     */
    protected static function findUser($link, array $c, $login)
    {
        $filter = sprintf(
            '(&(objectCategory=person)(objectClass=user)(sAMAccountName=%s))',
            ldap_escape($login, '', LDAP_ESCAPE_FILTER)
        );
        $sr = @ldap_search($link, $c['ad_base_dn'], $filter,
            ['distinguishedName', 'userAccountControl', 'adminCount', 'sAMAccountName']);
        if ($sr === false) {
            throw new \RuntimeException('Falha na busca no AD: ' . ldap_error($link));
        }
        $entries = ldap_get_entries($link, $sr);
        if (($entries['count'] ?? 0) === 0) {
            throw new NotFoundException(
                "Conta '{$login}' não encontrada na OU configurada do AD. Se existir em outra OU, não é alterada."
            );
        }
        if ($entries['count'] > 1) {
            throw new \RuntimeException("Mais de uma conta '{$login}' encontrada; nada foi alterado.");
        }
        return $entries[0];
    }

    /**
     * Bloqueia (enabled=false) ou libera (enabled=true) a conta.
     *
     * @return string mensagem de resultado
     */
    public static function setAccountEnabled(array $c, $login, $enabled)
    {
        $link  = self::connect($c);
        $entry = self::findUser($link, $c, $login);
        $dn    = $entry['dn'];

        if ((int) ($entry['admincount'][0] ?? 0) === 1) {
            throw new \RuntimeException(
                "Conta '{$login}' é protegida (administrador ou grupo privilegiado); não será alterada."
            );
        }

        $uac = (int) ($entry['useraccountcontrol'][0] ?? 0);
        if ($uac === 0) {
            throw new \RuntimeException("Não foi possível ler o estado da conta '{$login}'.");
        }

        $new = $enabled ? ($uac & ~self::UAC_DISABLED) : ($uac | self::UAC_DISABLED);
        $estado = $enabled ? 'habilitada' : 'desabilitada';

        if ($new === $uac) {
            return "Conta {$login} já estava {$estado} no AD.";
        }

        if (!@ldap_mod_replace($link, $dn, ['userAccountControl' => (string) $new])) {
            $errno = ldap_errno($link);
            if ($errno === 50) {
                throw new \RuntimeException(
                    "Sem permissão para alterar '{$login}'. Confira a delegação da conta de serviço na OU."
                );
            }
            throw new \RuntimeException('Falha ao alterar a conta: ' . ldap_err2str($errno));
        }

        return "Conta {$login} {$estado} no AD.";
    }

    /**
     * Testa conexão, autenticação e permissão de escrita, sem alterar contas.
     * A permissão é conferida pelo atributo calculado allowedAttributesEffective,
     * que lista o que a conta de serviço pode gravar em um usuário da OU.
     *
     * @return array ['ok' => bool, 'messages' => string[]]
     */
    public static function test(array $c)
    {
        self::$link = null;
        $msgs = [];

        $link   = self::connect($c);
        $msgs[] = 'Conexão LDAPS e certificado OK.'
            . (!empty($c['ad_allow_sha1']) ? ' (compatibilidade SHA-1 ativa)' : '');
        $msgs[] = 'Autenticação da conta de serviço OK.';

        $sr = @ldap_read($link, $c['ad_base_dn'], '(objectClass=*)', ['distinguishedName']);
        if ($sr === false) {
            return ['ok' => false, 'messages' => array_merge($msgs,
                ['OU não encontrada ou sem permissão de leitura: ' . ldap_error($link)])];
        }
        $msgs[] = 'OU encontrada.';

        $sr = @ldap_search($link, $c['ad_base_dn'],
            '(&(objectCategory=person)(objectClass=user)(!(adminCount=1)))',
            ['sAMAccountName'], 0, 1);
        $entries = $sr ? ldap_get_entries($link, $sr) : ['count' => 0];
        if (($entries['count'] ?? 0) === 0) {
            return ['ok' => false, 'messages' => array_merge($msgs,
                ['Nenhum usuário comum encontrado na OU para conferir a permissão.'])];
        }

        $dn = $entries[0]['dn'];
        $sr = @ldap_read($link, $dn, '(objectClass=*)', ['allowedAttributesEffective']);
        $e  = $sr ? ldap_get_entries($link, $sr) : [];
        $allowed = array_map('strtolower', array_filter(
            (array) ($e[0]['allowedattributeseffective'] ?? []),
            'is_string'
        ));

        if (in_array('useraccountcontrol', $allowed, true)) {
            $msgs[] = 'Permissão para bloquear/liberar contas na OU confirmada.';
            return ['ok' => true, 'messages' => $msgs];
        }

        $msgs[] = 'A conta de serviço NÃO tem permissão de gravar userAccountControl nesta OU. '
            . 'Revise a delegação de controle.';
        return ['ok' => false, 'messages' => $msgs];
    }
}
