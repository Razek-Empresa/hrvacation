# Afastamentos / Bloqueio de acessos — Plugin GLPI

Plugin para o **GLPI** onde o RH cadastra **afastamentos** de colaboradores
(licenças, afastamentos médicos, viagens, etc.) e, com base nas datas, o sistema
**abre chamados automaticamente**:

- um **chamado de bloqueio** dos acessos no início do afastamento;
- um **chamado de liberação** dos acessos no retorno.

Suporta afastamentos **fracionados em até 3 períodos**, cada um gerando seu
próprio par de chamados. Cada chamado já nasce com **tarefas separadas**
(bloquear AD, Sectra, Office 365, redirecionar e-mail, etc.),
totalmente configuráveis pela interface.

Opcionalmente, o próprio GLPI **executa** o bloqueio e a liberação: desabilita e
habilita a conta no **Active Directory** local (LDAPS) e no **Microsoft 365**
(Entra ID), e agenda a **resposta automática de ausência** na caixa de correio.
Tudo com simulação, limites de segurança e registro de cada ação no chamado.

> Compatível com **GLPI 10.0.x** e **GLPI 11.0.x**.

---

## Índice

- [Recursos](#recursos)
- [Requisitos](#requisitos)
- [Instalação](#instalação)
- [Configuração](#configuração)
- [Permissões](#permissões)
- [Uso](#uso)
- [Fracionado](#fracionado)
- [Como os chamados são abertos](#como-os-chamados-são-abertos)
- [Cron automático](#cron-automático)
- [Automação de acessos](#automação-de-acessos)
- [Integração com Microsoft 365](#integração-com-microsoft-365)
- [Integração com Active Directory](#integração-com-active-directory)
- [Resposta automática de ausência](#resposta-automática-de-ausência)
- [Cancelamento ao excluir](#cancelamento-ao-excluir)
- [Estrutura de arquivos](#estrutura-de-arquivos)
- [Notas técnicas](#notas-técnicas)
- [Changelog](#changelog)
- [Licença](#licença)

---

## Recursos

- **Cadastro de afastamentos** com seleção de data de início e quantidade de dias (término calculado automaticamente).
- **Afastamento fracionado** em até 3 períodos, cada um com datas e chamados independentes.
- **Calendário mensal** marcando início (verde ▸) e fim (vermelho ◂) de cada afastamento.
- **Linha do tempo (Gantt)** com barras por colaborador para enxergar sobreposições.
- **Listagem** com ID, nome do colaborador, início, término e links para os chamados.
- **Abertura automática de chamados** de bloqueio e liberação por período.
- **Tarefas configuráveis** por chamado (uma por linha), com lista de bloqueio e espelho de liberação.
- **Antecedência configurável** para abrir cada chamado.
- **Abertura imediata** ao cadastrar afastamentos retroativos ou que começam hoje.
- **Requerente = usuário do RH** que cadastrou o afastamento; colaborador entra como observador.
- **Cancelamento automático** dos chamados vinculados ao excluir um afastamento.
- **Categoria, grupo responsável e tipo** dos chamados definidos na configuração.
- Acesso pela **interface padrão** (Ferramentas) e pela **interface simplificada** (Plug-ins).
- **Bloqueio e liberação automáticos** no Active Directory (LDAPS) e no Microsoft 365, executados pelo GLPI.
- **Resposta automática de ausência** agendada no Microsoft 365, com regra e mensagem editáveis.
- **Segurança da automação:** modo simulação, data de corte, disjuntor, lista de exceção, limite de tentativas e segredos criptografados.

---

## Requisitos

| Item | Versão |
|------|--------|
| GLPI | 10.0.0+ ou 11.0.x |
| PHP  | 7.4+, com as extensões `curl`, `ldap` e `openssl` (para a automação) |

A automação é opcional. Para usá-la: um app registrado no Entra ID (Microsoft
365) e/ou uma conta de serviço no AD com LDAPS habilitado.

---

## Instalação

1. Copie a pasta `hrvacation` para `plugins/` ou `marketplace/` do GLPI:

   ```
   <glpi>/marketplace/hrvacation/setup.php
   ```

   A pasta `hrvacation` deve ficar direto dentro de `plugins/` (ou `marketplace/`), sem nível extra.

2. Ajuste o dono dos arquivos:

   ```bash
   chown -R www-data:www-data <caminho>/hrvacation
   ```

3. No GLPI, vá em **Configurar › Plugins**, localize **Afastamentos / Bloqueio de acessos**
   e clique em **Instalar** e **Ativar**.

4. Conceda permissão ao perfil do RH em **Administração › Perfis**
   (veja a seção [Permissões](#permissões)).

---

## Configuração

Acesse **Configurar › Plugins › engrenagem do Afastamentos**:

| Campo | Função |
|-------|--------|
| **Antecedência — bloqueio (dias)** | Quantos dias antes do início abrir o chamado. `0` = no próprio dia. |
| **Antecedência — liberação (dias)** | Quantos dias antes do término abrir o chamado. |
| **Categoria do chamado de bloqueio** | Categoria ITIL do chamado de bloqueio. |
| **Categoria do chamado de liberação** | Categoria ITIL do chamado de liberação. |
| **Grupo responsável** | Grupo técnico atribuído aos chamados. |
| **Tipo do chamado** | Incidente ou Requisição (padrão: Requisição). |
| **Tarefas do chamado de bloqueio** | Uma tarefa por linha — cada linha vira uma tarefa "a fazer". |
| **Tarefas do chamado de liberação** | Idem, pré-preenchido com o espelho do bloqueio. |

Tarefas padrão de bloqueio:

```
Bloquear acesso Active Directory
Bloquear acesso sectra Razek
Bloquear acesso sectra SmartMed
Bloquear acesso sectra Medfield
Bloquear acesso Office 365
Configurar mensagem de ausência Office 365
Redirecionar Email
```

### Automação

| Campo | Função |
|-------|--------|
| **Automação ativa** | Liga a execução automática. Ao ativar, define a data de corte. |
| **Modo simulação (dry-run)** | Registra nos chamados o que seria feito, sem executar. |
| **Bloquear no Microsoft 365** | Desabilita/habilita a conta no Entra ID. |
| **Bloquear no Active Directory** | Desabilita/habilita a conta no AD local. |
| **Limite de contas por execução** | Disjuntor: acima disso, nenhum bloqueio é executado. |
| **Contas nunca bloqueadas** | Login do GLPI ou e-mail, um por linha. |

As seções **Microsoft 365**, **Active Directory** e **Resposta automática de
ausência** da mesma tela têm as credenciais e regras de cada integração,
cada uma com botão de teste que não altera nenhuma conta.

---

## Permissões

Acesse **Administração › Perfis › (perfil RH) › aba "Afastamentos"**:

| Nível | O que libera |
|-------|--------------|
| **Ler** | Ver listagem, calendário, linha do tempo e abrir afastamentos. |
| **Criar** | Cadastrar novos afastamentos. |
| **Atualizar** | Editar afastamentos existentes. |
| **Excluir** | Enviar para a lixeira (cancela chamados vinculados). |
| **Purgar** | Excluir definitivamente. |

> Sem o direito marcado, o menu **Afastamentos** não aparece para o usuário.

> A configuração do plugin usa o direito padrão de **configuração** do GLPI, separado.

> **Interface simplificada:** o plugin aparece em **Plug-ins** para perfis helpdesk.
> O direito é injetado na sessão a cada requisição, sem necessidade de logout/login.

---

## Uso

- **Interface padrão:** menu **Ferramentas › Afastamentos**.
- **Interface simplificada:** menu **Plug-ins › Afastamentos / Bloqueio de acessos**.
- Botões no topo: **+ Adicionar**, **Calendário** e **Linha do tempo**.
- Na listagem, clique no **ID** ou no **nome do colaborador** para abrir o afastamento.

### Cadastrar um afastamento

1. Clique em **+ Adicionar**.
2. Selecione o **colaborador**.
3. Informe a **data de início** e a **quantidade de dias** — o término é calculado automaticamente.
4. Opcionalmente, informe para quem **redirecionar o e-mail** e **comentários**.
5. Se necessário, marque **Fracionado** (veja abaixo).
6. Clique em **Adicionar**.

---

## Fracionado

Marque o checkbox **Fracionado** para revelar mais dois períodos (P2 e P3).
Cada período tem sua própria data de início e quantidade de dias.
O término de cada período é calculado automaticamente.

Cada período ativo gera seus **próprios chamados** de bloqueio e liberação
(até 6 chamados no total). Os títulos dos chamados dos períodos 2 e 3
ficam com o sufixo **[Período 2]** e **[Período 3]** para fácil identificação.

---

## Como os chamados são abertos

- **Bloqueio:** abre quando o início do período chega (hoje, antecedência ou retroativo)
  e o afastamento ainda não terminou.
- **Liberação:** abre quando o término entra na janela de antecedência.
  Períodos com término há mais de **365 dias** são ignorados, para não gerar
  chamados retroativos de registros históricos.

Dois gatilhos trabalham juntos:

1. **Ao cadastrar** — se o afastamento começa hoje ou já começou, o chamado de bloqueio
   abre imediatamente.
2. **Tarefa automática diária** (`vacationTickets`) — cuida dos afastamentos futuros.

O **requerente** de cada chamado é o **usuário do RH** que cadastrou o afastamento
(`users_id_recipient`). O colaborador afastado entra como **observador**.

Cada chamado é criado **uma única vez** — os IDs ficam gravados no afastamento.

---

## Cron automático

A tarefa automática (`vacationTickets`) roda em **modo GLPI (interno)** por padrão,
o que depende de alguém acessar o sistema. Para garantir execução diária em
produção, agende no crontab do servidor host:

```bash
crontab -e
```

Adicione (ajuste o nome do container e o caminho do GLPI):

```
* * * * * docker exec -u www-data SEU_CONTAINER php /var/www/glpi/front/cron.php >/dev/null 2>&1
```

> Rodar a cada minuto é seguro: o `cron.php` só executa cada tarefa quando o
> intervalo configurado dela já passou. Como o `vacationTickets` está definido
> para uma vez por dia, ele roda uma vez por dia.

Em imagens oficiais `glpi/glpi`, o GLPI fica em `/var/www/glpi` e **não** há
`bin/console` — use o `front/cron.php` como acima.

Para testar na hora, vá em **Configurar › Ações automáticas › vacationTickets ›
botão "Executar"**.

### Solução de problemas

Se a tarefa ficar travada em "Em execução" após um erro, o GLPI não a executa
novamente. Resete o estado:

```sql
UPDATE glpi_crontasks SET state = 0 WHERE name = 'vacationTickets';
```

Para inspecionar o histórico de execuções e mensagens de erro:

```sql
SELECT date, content FROM glpi_crontasklogs
WHERE crontasks_id = (SELECT id FROM glpi_crontasks WHERE name = 'vacationTickets')
ORDER BY id DESC LIMIT 20;
```

---

## Automação de acessos

Quando habilitada, a automação age no momento em que o chamado é aberto (ao
cadastrar ou pela tarefa automática) e registra cada ação como tarefa no
chamado. Regras comuns aos três destinos (AD, Microsoft 365 e resposta
automática):

- **Data de corte:** só chamados abertos depois da ativação da automação são
  processados; o histórico segue o processo manual.
- **Modo simulação:** com o dry-run ligado, nada é alterado; cada chamado recebe a
  descrição do que seria feito. Ao desligá-lo, as ações simuladas ainda pendentes
  são executadas.
- **Disjuntor:** se houver mais bloqueios pendentes que o limite, nada é executado
  na rodada e o motivo fica registrado.
- **Lista de exceção:** contas listadas nunca são alteradas; o chamado registra que
  foram puladas.
- **Tentativas:** cada operação é tentada até 3 vezes. O chamado recebe uma tarefa
  na primeira falha e outra se a última também falhar; falha ao liberar é marcada
  como "intervenção manual necessária".
- **Conta inexistente:** se o colaborador não tem conta no destino (por exemplo,
  sem Microsoft 365), é registrado uma única vez que não há nada a fazer.
- **Independência:** cada destino é controlado separadamente; a falha de um não
  afeta os outros.

---

## Integração com Microsoft 365

Quando um chamado de bloqueio é aberto, o GLPI desabilita a conta do
colaborador no Microsoft 365 e encerra as sessões ativas. No chamado de
liberação, habilita a conta novamente. Cada ação vira uma tarefa no chamado.

### Permissões do app (Entra ID)

Permissões de **aplicativo** do Microsoft Graph, com consentimento do administrador:

| Permissão | Uso |
|-----------|-----|
| `User.Read.All` | Localizar a conta |
| `User.EnableDisableAccount.All` | Bloquear e liberar a conta |
| `User.RevokeSessions.All` | Encerrar sessões ativas no bloqueio |
| `MailboxSettings.ReadWrite` | Resposta automática de ausência (só se a função estiver ativada) |

O app não consegue alterar senhas, grupos, licenças nem ler e-mails. Com a resposta automática ativada, ele também pode alterar as configurações das caixas de correio, o que torna a proteção do segredo ainda mais importante.

### Proteção do segredo

- Criptografado no banco com a chave do GLPI (`GLPIKey`); o backup do banco sozinho não o expõe.
- Nunca é exibido novamente após salvo; para trocar, digite um novo.
- Não é gravado no histórico do GLPI nem devolvido por nenhum endpoint.
- Recriptografado automaticamente se a chave do GLPI for trocada (`glpi:security:change_key`).
- O botão **Salvar e testar conexão** confere a autenticação e as permissões concedidas, e avisa se o app tiver permissões além do necessário.

> Faça backup do arquivo de chave do GLPI (`glpicrypt.key`). Sem ele, o
> segredo não pode ser lido e precisa ser cadastrado de novo.

### Identificação da conta

A conta no Microsoft 365 é localizada pelo **e-mail do usuário no GLPI**.
Usuários sem e-mail cadastrado geram um erro no chamado; após cadastrar o
e-mail, a operação é retentada automaticamente.

---

## Integração com Active Directory

Quando um chamado de bloqueio é aberto, o GLPI desabilita a conta do
colaborador no AD local; no chamado de liberação, habilita novamente. Tudo
acontece dentro do GLPI, sem servidor ou serviço adicional.

### Pré-requisitos

- Controlador de domínio com **LDAPS** (porta 636) e certificado emitido pela AC interna.
- Container do GLPI capaz de resolver o nome do controlador e alcançar a porta 636.
- **Conta de serviço** dedicada, fora de grupos administrativos, com senha longa.
- **Delegação de controle** na OU dos colaboradores: somente *Ler* e *Gravar*
  `userAccountControl` em objetos Usuário.

### Proteções

- Conexão sempre criptografada e com o certificado do controlador verificado
  contra a AC interna; sem o certificado da AC, o plugin recusa a conexão.
- Senha criptografada com a chave do GLPI, nunca exibida, fora do histórico e
  incluída na rotação de chaves.
- Só contas **dentro da OU configurada** são alteradas.
- Contas protegidas (`adminCount=1`) são recusadas, além de a delegação já não
  alcançá-las.
- Apenas o bit de conta desabilitada do `userAccountControl` é alterado.
- Se a autenticação falhar, a rodada para imediatamente, evitando bloquear a
  conta de serviço por tentativas repetidas.
- O botão **Salvar e testar conexão com o AD** confere conexão, certificado,
  autenticação e permissão de escrita na OU, sem alterar nenhuma conta.

> **AC que assina com SHA-1:** o OpenSSL moderno recusa esses certificados. A
> opção "Aceitar certificado assinado com SHA-1" libera apenas essa regra; a AC e
> o nome do servidor continuam verificados. O ideal é migrar a AC para SHA-256 e
> desmarcar a opção.

> Uma sessão do Windows já aberta não é encerrada no momento do bloqueio; ela
> deixa de funcionar ao bloquear a tela, sair ou expirar a autenticação.

---

## Resposta automática de ausência

No chamado de bloqueio, o GLPI agenda a resposta automática na caixa de correio
do colaborador; o próprio Exchange liga e desliga a mensagem nos horários
definidos. Funciona mesmo com a conta bloqueada.

A regra fica explícita e editável na configuração, com um resumo em linguagem
simples na própria tela:

| Campo | Padrão |
|-------|--------|
| Início da mensagem | Primeiro dia do afastamento, 00h |
| Encerramento | Dia do retorno (dia seguinte ao último dia), 00h |
| Fuso horário | Brasília |
| Quem recebe | Somente pessoas da empresa |
| No chamado de liberação | Manter até o encerramento agendado |
| Ao excluir o afastamento | Desligar na hora |
| Resposta já configurada pelo colaborador | Substituir |

A mensagem é um modelo com os marcadores `{nome}`, `{inicio}`, `{fim}`,
`{retorno}` e `{contato}` (frase com a pessoa indicada em "Redirecionar e-mail
para", vazia se não houver).

Exige a permissão de aplicativo `MailboxSettings.ReadWrite` no app do Entra ID.

> Se as datas de um afastamento forem alteradas depois de a mensagem ter sido
> agendada, ela não é atualizada automaticamente: exclua e cadastre novamente.

---

## Cancelamento ao excluir

Ao **excluir** um afastamento, todos os chamados vinculados (até 6, nos 3 períodos)
são **cancelados automaticamente** com a solução "Afastamento cancelado pelo RH",
movendo-os para *Solucionado*. Chamados já fechados são ignorados.

---

## Estrutura de arquivos

```
hrvacation/
├── setup.php                 # registro, init, versão, injeção de direitos na sessão
├── hook.php                  # instalação/desinstalação, tabelas, migração, cron
├── README.md
├── src/
│   ├── Period.php            # itemtype + formulário + calendário + timeline + cron + fracionado
│   ├── Config.php            # configuração (linha única)
│   ├── Profile.php           # aba "Afastamentos" no formulário de Perfis
│   ├── Automation.php        # fila de operações, regras de segurança e registro nos chamados
│   ├── M365.php              # cliente do Microsoft Graph (contas e resposta automática)
│   ├── AdLdap.php            # cliente LDAPS do Active Directory
│   ├── AutoReplyRule.php     # regra da resposta automática (cálculo e resumo)
│   └── NotFoundException.php # conta inexistente no destino (sem nova tentativa)
└── front/
    ├── period.php            # listagem própria com JOIN direto
    ├── period.form.php       # formulário (exibe e processa)
    ├── calendar.php          # calendário mensal
    ├── timeline.php          # linha do tempo (Gantt)
    └── config.form.php       # configuração do plugin
```

Tabelas: `glpi_plugin_hrvacation_periods`, `glpi_plugin_hrvacation_configs` e
`glpi_plugin_hrvacation_executions` (auditoria das ações automáticas).

---

## Notas técnicas

- Segue convenções do GLPI: namespace `GlpiPlugin\Hrvacation` (PSR-4), tabelas `glpi_plugin_hrvacation_*`, sem chaves estrangeiras.
- Consultas via **query builder** do GLPI; saída HTML escapada (compatível com GLPI 11).
- A listagem usa **JOIN direto no banco** para garantir exibição do nome do colaborador em todas as interfaces.
- Calendário e linha do tempo em PHP puro, sem dependências JS externas.
- Camada `front/` mantida (suportada pelo GLPI 11 por compatibilidade).
- Direito do plugin injetado na sessão a cada requisição para funcionar na interface simplificada.
- Segredos (client secret do Microsoft 365 e senha da conta de serviço do AD) gravados criptografados com a `GLPIKey`, fora do histórico e registrados em `secured_fields` para a rotação de chaves.
- Toda a automação roda dentro do GLPI: não há endpoint público, worker externo nem token de API.

---

## Changelog

| Versão | Mudanças |
|--------|----------|
| 2.8.1 | Conta ou caixa inexistente no destino gera um único registro "sem ação", sem novas tentativas. Erros reais geram tarefa só na 1ª e na última tentativa; alerta de intervenção manual apenas após a última. Situações em português nas tarefas; registros sem informação útil ficam só no histórico interno. |
| 2.8.0 | Regra da resposta automática editável na configuração: horário de início e de encerramento, fuso horário, quem recebe, comportamento no chamado de liberação e na exclusão, e se substitui uma resposta já configurada pelo colaborador. Resumo da regra exibido na tela com um exemplo de datas. |
| 2.7.2 | Liberação não desliga mais a resposta automática antes do retorno: o agendamento a encerra no dia da volta; o plugin só desliga se a data de retorno já tiver passado. Exclusão do afastamento continua desligando na hora. |
| 2.7.1 | Resposta automática com a permissão concedida direto no app do Entra ID; o teste de conexão confere se ela foi concedida. |
| 2.7.0 | Resposta automática de ausência no Microsoft 365: configurada no bloqueio com agendamento até o dia do retorno, somente para remetentes internos, com modelo editável; desligada na liberação e ao excluir o afastamento. Exige a permissão MailboxSettings.ReadWrite no app do Entra ID, conferida pelo teste de conexão. |
| 2.6.2 | Opção de compatibilidade com AC interna que assina com SHA-1 (desligada por padrão): aceita a assinatura legada mantendo a verificação da AC e do nome do servidor, cifras fortes e TLS 1.2 no mínimo. |
| 2.6.1 | Compatibilidade com builds do PHP sem contexto TLS por conexão (LDAP_OPT_X_TLS_NEWCTX), mantendo a verificação obrigatória do certificado. |
| 2.6.0 | Active Directory executado direto pelo GLPI via LDAPS, com certificado verificado contra a AC interna; senha da conta de serviço criptografada (GLPIKey); só altera contas dentro da OU configurada e recusa contas protegidas; teste que confere a permissão de escrita sem alterar contas. Removidos o worker PowerShell, o endpoint público e o token. |
| 2.5.0 | Endpoint do worker liberado no firewall do GLPI 11 (sem sessão); token enviado no cabeçalho e guardado com DPAPI no Windows; worker dedicado ao AD, com modo de simulação local. |
| 2.4.3 | Data de corte da automação: apenas chamados abertos após a ativação são processados, evitando agir sobre o histórico. |
| 2.4.2 | Removido o campo "Domínio padrão": a conta no Microsoft 365 é localizada somente pelo e-mail do usuário no GLPI. |
| 2.4.1 | Lista de contas nunca bloqueadas aceita login do GLPI ou e-mail, sem diferenciar maiúsculas; avaliação centralizada no GLPI também para o worker do AD. |
| 2.4.0 | Microsoft 365 executado direto pelo GLPI (app do Entra ID com client secret); segredo criptografado com a GLPIKey, mascarado na tela, fora do histórico e incluído na rotação de chaves; permissões mínimas (User.Read.All, User.EnableDisableAccount.All, User.RevokeSessions.All); teste de conexão que confere as permissões concedidas; aviso de expiração do segredo. Worker PowerShell passa a cuidar apenas do AD. |
| 2.3.0 | Integração com Microsoft 365 (Entra ID) via worker PowerShell com autenticação por certificado; AD e M365 habilitáveis separadamente; controle de execução por destino, com limite de 3 tentativas e sem reenvio em dry-run; disjuntor corrigido; identificação no M365 pelo e-mail do usuário. |
| 2.2.1 | Tarefa automática registrada em modo externo (CLI); o modo escolhido pelo administrador é preservado nas atualizações. |
| 2.2.0 | Base da automação: endpoint para worker externo, tabela de auditoria de execuções e configurações de segurança (dry-run, limite, lista de exceção, token). |
| 2.1.4 | Correção da query do cron (`!= null` nunca casa em SQL) que impedia o processamento dos períodos. |
| 2.1.3 | Correção crítica: gravação do vínculo do chamado no período (updates parciais eram abortados pela validação). |
| 2.1.2 | Ajuste da seleção de períodos pendentes no cron. |
| 2.1.1 | Janela de liberação ampliada de 30 para 365 dias; tratamento de exceções no cron com log detalhado. |
| 2.1.0 | Dropdown de quantidade de dias (1–200) no lugar da data final; suporte a afastamento fracionado em até 3 períodos com chamados independentes. |
| 2.0.3 | Listagem própria com JOIN direto no banco, resolvendo exibição do nome do colaborador. |
| 2.0.2 | Tentativa de exibição via `getSpecificValueToDisplay`. |
| 2.0.1 | Remoção de `joinparams` para dedução automática do JOIN. |
| 2.0.0 | Requerente dos chamados = usuário do RH; colaborador entra como observador. |
| 1.9.9 | Gravação de `users_id_recipient` no cadastro. |
| 1.9.7 | Barra de navegação no formulário da interface simplificada. |
| 1.9.5 | Barra de ações (Adicionar / Calendário / Linha do tempo) na listagem simplificada. |
| 1.9.4 | Correção crítica: direito injetado na sessão a cada requisição. |
| 1.9.3 | Carregamento do `includes.php` via `GLPI_ROOT`. |
| 1.9.2 | Correção do contexto de menu `helpdesk` vs `tools`. |
| 1.9.1 | Troca de `redefine_menus` por `helpdesk_menu_entry`. |
| 1.9.0 | Entrada "Afastamentos" na interface simplificada (menu Plug-ins). |
| 1.8.1 | Redirecionamento de e-mail por seleção de usuário. |
| 1.8.0 | Listagem com colunas padrão; calendário marca apenas início e fim; campo de redirecionamento. |
| 1.7.2 | Correções na aba de permissões do perfil (GLPI 11). |
| 1.7.0 | Aba "Afastamentos" em Administração › Perfis. |
| 1.6.2 | Textos: "mensagem de ausência" em vez de "mensagem de férias". |
| 1.6.1 | Ícone `ti ti-calendar-off`. |
| 1.6.0 | Renomeado de "férias" para "afastamento"; reordenação do formulário. |
| 1.5.1 | Correção do salvamento da configuração ("XML not well formed"). |
| 1.5.0 | Abertura imediata para retroativos; cron em modo GLPI; ID clicável na lista. |
| 1.4.1 | Correção de idempotência na atualização (direitos de perfil duplicados). |
| 1.4.0 | Cancelamento automático dos chamados ao excluir um afastamento. |
| 1.3.0 | Tarefas automáticas configuráveis por chamado. |
| 1.2.1 | Correção do roteamento das telas. |
| 1.2.0 | Linha do tempo (Gantt). |
| 1.1.0 | Compatibilidade com GLPI 11. |
| 1.0.0 | Versão inicial: cadastro, calendário e abertura automática de chamados. |

---

## Licença

GPLv3+ — mesma licença do GLPI.

---

Desenvolvido por **TI Razek**.
