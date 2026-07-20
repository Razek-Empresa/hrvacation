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

---

## Requisitos

| Item | Versão |
|------|--------|
| GLPI | 10.0.0+ ou 11.0.x |
| PHP  | 7.4+ |

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

Dois gatilhos trabalham juntos:

1. **Ao cadastrar** — se o afastamento começa hoje ou já começou, o chamado de bloqueio
   abre imediatamente.
2. **Tarefa automática diária** (`vacationTickets`) — cuida dos afastamentos futuros.

O **requerente** de cada chamado é o **usuário do RH** que cadastrou o afastamento
(`users_id_recipient`). O colaborador afastado entra como **observador**.

Cada chamado é criado **uma única vez** — os IDs ficam gravados no afastamento.

---

## Cron automático

A tarefa automática roda em **modo GLPI (interno)** por padrão, mas isso depende
de acesso ao sistema. Para garantir execução diária em produção, agende no
crontab do servidor host:

```bash
crontab -e
```

Adicione (ajuste o nome do container e o horário):

```
0 7 * * * docker exec -u www-data SEU_CONTAINER php /var/glpi/bin/console glpi:cron:run >/dev/null 2>&1
```

Para testar na hora sem esperar o cron, vá em **Configurar › Ações automáticas ›
vacationTickets › botão "Executar"**.

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
│   └── Profile.php           # aba "Afastamentos" no formulário de Perfis
└── front/
    ├── period.php            # listagem própria com JOIN direto
    ├── period.form.php       # formulário (exibe e processa)
    ├── calendar.php          # calendário mensal
    ├── timeline.php          # linha do tempo (Gantt)
    └── config.form.php       # configuração do plugin
```

Tabelas: `glpi_plugin_hrvacation_periods` e `glpi_plugin_hrvacation_configs`.

---

## Notas técnicas

- Segue convenções do GLPI: namespace `GlpiPlugin\Hrvacation` (PSR-4), tabelas `glpi_plugin_hrvacation_*`, sem chaves estrangeiras.
- Consultas via **query builder** do GLPI; saída HTML escapada (compatível com GLPI 11).
- A listagem usa **JOIN direto no banco** para garantir exibição do nome do colaborador em todas as interfaces.
- Calendário e linha do tempo em PHP puro, sem dependências JS externas.
- Camada `front/` mantida (suportada pelo GLPI 11 por compatibilidade).
- Direito do plugin injetado na sessão a cada requisição para funcionar na interface simplificada.

---

## Changelog

| Versão | Mudanças |
|--------|----------|
| 2.1.2 | Configuração da tarefa para modo CLI e frequência de 1h; recuperação do usuário de RH criador do afastamento para chamados de liberação legados; remoção do colaborador como observador. |
| 2.1.1 | Exclusão de redirecionamento de e-mail e observações nos chamados de liberação; correção de validação na atualização de chamados e flexibilização do modo do cron (CLI/interno). |
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
