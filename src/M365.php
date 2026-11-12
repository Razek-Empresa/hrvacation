<?php

namespace GlpiPlugin\Hrvacation;

use GLPIKey;

/**
 * Cliente mínimo do Microsoft Graph para bloquear e liberar contas no
 * Microsoft 365 (Entra ID), usando um app registration com client secret.
 *
 * Proteção do segredo:
 *  - Armazenado criptografado com a GLPIKey (chave do servidor GLPI).
 *  - Descriptografado apenas em memória, no momento de pedir o token.
 *  - Nunca é exibido, registrado em log ou devolvido por nenhum endpoint.
 *  - O token de acesso vive só em memória, durante a requisição.
 *
 * Permissões de aplicativo necessárias (e suficientes):
 *  - User.Read.All                  localizar a conta
 *  - User.EnableDisableAccount.All  bloquear e liberar (accountEnabled)
 *  - User.RevokeSessions.All        encerrar sessões ativas no bloqueio
 */
class M365
{
    const GRAPH = 'https://graph.microsoft.com/v1.0';

    /** Permissões mínimas esperadas no token. */
    const REQUIRED_ROLES = [
        'User.Read.All',
        'User.EnableDisableAccount.All',
        'User.RevokeSessions.All',
    ];

    /** Permissões amplas que não deveriam estar no app. */
    const BROAD_ROLES = [
        'User.ReadWrite.All',
        'Directory.ReadWrite.All',
        'Directory.Read.All',
        'Mail.ReadWrite',
        'Mail.Read',
        'RoleManagement.ReadWrite.Directory',
        'Application.ReadWrite.All',
    ];

    private static $token     = null;
    private static $token_exp = 0;
    private static $token_key = null;

    // ------------------------------------------------------------ segredo

    public static function isConfigured(array $c)
    {
        return !empty($c['m365_tenant_id'])
            && !empty($c['m365_client_id'])
            && !empty($c['m365_client_secret']);
    }

    public static function encryptSecret($plain)
    {
        return (new GLPIKey())->encrypt((string) $plain);
    }

    protected static function decryptSecret(array $c)
    {
        $plain = @(new GLPIKey())->decrypt((string) ($c['m365_client_secret'] ?? ''));
        if ($plain === null || $plain === '' || $plain === false) {
            throw new \RuntimeException(
                'Não foi possível descriptografar o client secret. '
                . 'Se a chave do GLPI foi alterada, cadastre o segredo novamente.'
            );
        }
        return $plain;
    }

    // --------------------------------------------------------------- HTTP

    protected static function http($method, $url, array $headers, $body = null)
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Extensão cURL do PHP indisponível.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Falha de conexão com a Microsoft: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $json = json_decode((string) $resp, true);
        return [$code, is_array($json) ? $json : []];
    }

    /**
     * Extrai uma mensagem de erro segura (sem tokens ou dados sensíveis).
     */
    protected static function errorText(array $json, $code)
    {
        if (!empty($json['error']['message'])) {
            $msg = ($json['error']['code'] ?? 'Erro') . ': ' . $json['error']['message'];
        } elseif (!empty($json['error_description'])) {
            // Mantém só a primeira linha (as demais trazem IDs de rastreio).
            $msg = strtok((string) $json['error_description'], "\r\n");
        } else {
            $msg = 'Resposta HTTP ' . $code;
        }
        return mb_substr($msg, 0, 300);
    }

    // -------------------------------------------------------------- token

    protected static function getToken(array $c)
    {
        $cachekey = $c['m365_tenant_id'] . '|' . $c['m365_client_id'];
        if (self::$token !== null && self::$token_key === $cachekey && time() < self::$token_exp - 60) {
            return self::$token;
        }

        $url  = 'https://login.microsoftonline.com/' . rawurlencode($c['m365_tenant_id']) . '/oauth2/v2.0/token';
        $body = http_build_query([
            'client_id'     => $c['m365_client_id'],
            'client_secret' => self::decryptSecret($c),
            'scope'         => 'https://graph.microsoft.com/.default',
            'grant_type'    => 'client_credentials',
        ]);

        [$code, $json] = self::http('POST', $url, ['Content-Type: application/x-www-form-urlencoded'], $body);
        unset($body);

        if ($code !== 200 || empty($json['access_token'])) {
            throw new \RuntimeException('Falha na autenticação: ' . self::errorText($json, $code));
        }

        self::$token     = $json['access_token'];
        self::$token_exp = time() + (int) ($json['expires_in'] ?? 3000);
        self::$token_key = $cachekey;
        return self::$token;
    }

    /**
     * Lê as permissões concedidas a partir do próprio token (claim "roles").
     */
    protected static function tokenRoles($token)
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return [];
        }
        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        return is_array($payload['roles'] ?? null) ? $payload['roles'] : [];
    }

    protected static function graph($method, $path, $token, $body = null)
    {
        $headers = ['Authorization: Bearer ' . $token];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        return self::http($method, self::GRAPH . $path, $headers, $body);
    }

    // ------------------------------------------------------------ operações

    /**
     * Configura a resposta automática agendada, conforme a regra do plugin.
     * O Exchange liga e desliga a mensagem sozinho nos horários informados.
     *
     * @param array $window ['start' => 'Y-m-d H:i', 'end' => 'Y-m-d H:i']
     * @param array $rule   AutoReplyRule::fromConfig()
     */
    public static function setAutoReply(array $c, $upn, array $window, $html, array $rule)
    {
        $token = self::getToken($c);
        $body  = json_encode([
            'automaticRepliesSetting' => [
                'status'                 => 'scheduled',
                'externalAudience'       => $rule['audience'],
                'internalReplyMessage'   => $html,
                'externalReplyMessage'   => $rule['audience'] === 'none' ? '' : $html,
                'scheduledStartDateTime' => ['dateTime' => str_replace(' ', 'T', $window['start']) . ':00', 'timeZone' => $rule['timezone']],
                'scheduledEndDateTime'   => ['dateTime' => str_replace(' ', 'T', $window['end']) . ':00', 'timeZone' => $rule['timezone']],
            ],
        ], JSON_UNESCAPED_UNICODE);

        [$code, $json] = self::graph('PATCH', '/users/' . rawurlencode($upn) . '/mailboxSettings', $token, $body);
        self::checkMailboxResponse($code, $json, $upn);

        return sprintf(
            'Resposta automática agendada para %s: de %s até %s (%s; %s).',
            $upn,
            AutoReplyRule::fmt($window['start']),
            AutoReplyRule::fmt($window['end']),
            AutoReplyRule::TIMEZONES[$rule['timezone']],
            mb_strtolower(AutoReplyRule::AUDIENCES[$rule['audience']])
        );
    }

    /**
     * Status atual da resposta automática: disabled, alwaysEnabled ou scheduled.
     */
    public static function getAutoReplyStatus(array $c, $upn)
    {
        $token = self::getToken($c);
        [$code, $json] = self::graph('GET', '/users/' . rawurlencode($upn) . '/mailboxSettings/automaticRepliesSetting', $token);
        self::checkMailboxResponse($code, $json, $upn);
        return (string) ($json['status'] ?? 'disabled');
    }

    /**
     * Desliga a resposta automática.
     */
    public static function clearAutoReply(array $c, $upn)
    {
        $token = self::getToken($c);
        $body  = json_encode(['automaticRepliesSetting' => ['status' => 'disabled']]);

        [$code, $json] = self::graph('PATCH', '/users/' . rawurlencode($upn) . '/mailboxSettings', $token, $body);
        self::checkMailboxResponse($code, $json, $upn);

        return "Resposta automática desligada para {$upn}.";
    }

    protected static function checkMailboxResponse($code, array $json, $upn)
    {
        if ($code >= 200 && $code < 300) {
            return;
        }
        if ($code === 403 || $code === 401) {
            throw new \RuntimeException(
                "Sem permissão para a caixa de {$upn}. Verifique se ela pertence ao grupo liberado no RBAC do Exchange. "
                . self::errorText($json, $code)
            );
        }
        if ($code === 404) {
            throw new NotFoundException("Usuário sem caixa de correio no Microsoft 365 ({$upn}).");
        }
        throw new \RuntimeException('Falha ao configurar a resposta automática: ' . self::errorText($json, $code));
    }

    /**
     * Bloqueia (enabled=false) ou libera (enabled=true) a conta.
     * No bloqueio, encerra também as sessões ativas.
     *
     * @return string mensagem de resultado
     */
    public static function setAccountEnabled(array $c, $upn, $enabled)
    {
        $token = self::getToken($c);
        $path  = '/users/' . rawurlencode($upn);

        [$code, $json] = self::graph('GET', $path . '?$select=id,accountEnabled,userPrincipalName', $token);
        if ($code === 404) {
            throw new NotFoundException("Usuário sem conta no Microsoft 365 ({$upn}).");
        }
        if ($code !== 200) {
            throw new \RuntimeException('Falha ao localizar a conta: ' . self::errorText($json, $code));
        }

        $already = ((bool) ($json['accountEnabled'] ?? false)) === (bool) $enabled;
        if (!$already) {
            [$code, $json] = self::graph('PATCH', $path, $token, json_encode(['accountEnabled' => (bool) $enabled]));
            if ($code !== 204 && $code !== 200) {
                throw new \RuntimeException('Falha ao alterar a conta: ' . self::errorText($json, $code));
            }
        }

        if ($enabled) {
            return $already
                ? "Conta {$upn} já estava habilitada no Microsoft 365."
                : "Conta {$upn} habilitada no Microsoft 365.";
        }

        // Bloqueio: encerra sessões mesmo que a conta já estivesse desabilitada.
        [$code, $json] = self::graph('POST', $path . '/revokeSignInSessions', $token, '{}');
        $sess = ($code >= 200 && $code < 300)
            ? 'sessões encerradas'
            : 'falha ao encerrar sessões (' . self::errorText($json, $code) . ')';

        return ($already
            ? "Conta {$upn} já estava desabilitada no Microsoft 365; "
            : "Conta {$upn} desabilitada no Microsoft 365; ") . $sess . '.';
    }

    /**
     * Testa a conexão e confere as permissões do app, sem alterar nenhuma conta.
     *
     * @return array ['ok' => bool, 'messages' => string[]]
     */
    public static function test(array $c)
    {
        $msgs = [];
        if (!self::isConfigured($c)) {
            return ['ok' => false, 'messages' => ['Preencha Tenant ID, Client ID e Client secret.']];
        }

        $token = self::getToken($c);
        $msgs[] = 'Autenticação OK.';

        $roles   = self::tokenRoles($token);
        $missing = array_diff(self::REQUIRED_ROLES, $roles);
        $broad   = array_intersect(self::BROAD_ROLES, $roles);

        $ok = empty($missing);
        if ($missing) {
            $msgs[] = 'Permissões faltando (ou sem consentimento do administrador): ' . implode(', ', $missing) . '.';
        } else {
            $msgs[] = 'Permissões necessárias concedidas.';
        }
        if ($broad) {
            $msgs[] = 'ATENÇÃO: o app tem permissões além do necessário: ' . implode(', ', $broad)
                . '. Remova-as no Entra ID.';
        }
        if (!empty($c['automation_autoreply'])) {
            if (in_array('MailboxSettings.ReadWrite', $roles, true)) {
                $msgs[] = 'Permissão da resposta automática (MailboxSettings.ReadWrite) concedida.';
            } else {
                $ok = false;
                $msgs[] = 'Resposta automática ativada, mas a permissão MailboxSettings.ReadWrite não foi concedida ao app. '
                    . 'Adicione-a no Entra ID e conceda o consentimento do administrador.';
            }
        }

        [$code, $json] = self::graph('GET', '/users?$top=1&$select=id', $token);
        if ($code === 200) {
            $msgs[] = 'Leitura de usuários OK.';
        } else {
            $ok = false;
            $msgs[] = 'Falha ao ler usuários: ' . self::errorText($json, $code);
        }

        return ['ok' => $ok, 'messages' => $msgs];
    }
}
