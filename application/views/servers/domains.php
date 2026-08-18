<?php
$filtro = (array) $this->session->userdata('f_server_domains');
$badgeTipo = [
  'whm' => 'bg-secondary',
  'directadmin' => 'bg-primary',
  'cloudpanel' => 'bg-info',
  'manual' => 'bg-dark',
];

/** Catálogo das faixas de WHOIS — usado no chip e no select do offcanvas. */
$faixas = [
  'vencido' => 'Vencidos',
  'vence_30' => 'Vencem em 30 dias',
  'ok' => 'Em dia',
  'livre' => 'Livres (não registrados)',
  'pendente' => 'Não consultados',
  'erro' => 'Com erro na consulta',
  'sem_vencimento' => 'Sem vencimento informado',
];
$situacoes = ['ativo' => 'Ativo', 'suspenso' => 'Suspenso'];

$fKeyword = isset($filtro['keyword']) ? trim((string) $filtro['keyword']) : '';
$fServidor = isset($filtro['id_server']) ? (int) $filtro['id_server'] : 0;
$fStatus = isset($filtro['status']) ? (string) $filtro['status'] : '';
$fBucket = isset($filtro['whois_bucket']) ? (string) $filtro['whois_bucket'] : '';

// Chips do que está ESCONDIDO no offcanvas — sem eles o usuário não tem como
// saber por que a listagem está curta. A busca não entra aqui: ela tem campo
// próprio, visível e preenchido, no topo do card.
$chips = [];
if ($fServidor > 0) {
  foreach ($servers as $s) {
    if ((int) $s->id === $fServidor) $chips[] = 'Servidor: ' . $s->name;
  }
}
if ($fStatus !== '' && isset($situacoes[$fStatus])) $chips[] = 'Situação: ' . $situacoes[$fStatus];
if ($fBucket !== '' && isset($faixas[$fBucket])) $chips[] = 'WHOIS: ' . $faixas[$fBucket];

// "Tem algum filtro aplicado?" é pergunta diferente de "tem chip?": a busca
// visível também recorta a listagem e também precisa do LIMPAR.
$temFiltro = ($fKeyword !== '' || !empty($chips));

/**
 * Exibe MB em GB quando passa de 1024, para a coluna não virar um paredão de
 * números. Sem valor vindo do painel, mostra travessão.
 */
function formatar_disco($mb)
{
  if ($mb === NULL || $mb === '') return '—';
  $mb = (float) $mb;
  if ($mb >= 1024) return number_format($mb / 1024, 2, ',', '.') . ' GB';
  return number_format($mb, 0, ',', '.') . ' MB';
}

/**
 * Cor da data de vencimento do WHOIS. A faixa vem pronta da view
 * (`whois_bucket`), então os limiares vivem só no SQL — filtro e cor não podem
 * divergir.
 */
if (!function_exists('classe_vencimento_whois')) {
  function classe_vencimento_whois($bucket)
  {
    switch ($bucket) {
      case 'vencido':
        return 'text-danger fw-bold';
      case 'vence_30':
        return 'text-danger';
      default:
        return 'text-success';
    }
  }
}

/** "faltam 12 dia(s)" / "vencido há 3 dia(s)" abaixo da data. */
if (!function_exists('prazo_vencimento_whois')) {
  function prazo_vencimento_whois($data)
  {
    $hoje = new DateTime('today');
    $alvo = DateTime::createFromFormat('Y-m-d', substr((string) $data, 0, 10));
    if (!$alvo) return '';

    $alvo->setTime(0, 0, 0);
    $dias = (int) $hoje->diff($alvo)->format('%r%a');

    if ($dias < 0) return 'vencido há ' . abs($dias) . ' dia(s)';
    if ($dias === 0) return 'vence hoje';
    return 'faltam ' . $dias . ' dia(s)';
  }
}
?>
<div class="row mb-2 mb-xl-2">
  <div class="col-auto text-start">
    <h1 class="h3 mb-3">Domínios</h1>
    <!-- <small class="text-muted">Retrato da última sincronização de cada servidor.</small> -->
  </div>
  <div class="col-auto ms-auto text-end mt-n1">
    <a class="btn btn-outline-secondary" href="<?php echo base_url('servidores'); ?>"><i class="fa fa-server"></i> SERVIDORES</a>
  </div>
</div>

<div class="card flex-fill">
  <div class="card-body py-3">
    <?php
    // Busca por palavra-chave fora do offcanvas: é o filtro usado o tempo todo
    // e escondê-lo custava dois cliques por consulta. Formulário próprio, com o
    // mesmo destino do offcanvas — o post_filtrar_dominios faz merge sobre o
    // filtro da sessão, então buscar aqui NÃO derruba servidor, situação nem
    // faixa de WHOIS que estejam marcados lá dentro.
    //
    // A ordenação viaja neste mesmo formulário, em `ordem`, FORA do array
    // `f_server_domains`: aquele array vira `WHERE campo = valor` no
    // Global_model, e ordenação não é filtro. Ela fica visível de propósito —
    // não é filtro, então não vira chip nem entra no LIMPAR, e escondida atrás
    // do botão FILTROS ninguém acharia.
    $ordemAtual = isset($ordenacao_atual) ? (string) $ordenacao_atual : '';
    ?>
    <div class="row g-2 align-items-end">
      <div class="col-12 col-md-5">
        <form id="form_busca_dominios" method="POST" action="<?php echo base_url('servidores/post_filtrar_dominios'); ?>" role="search">
          <div class="input-group">
            <input type="text" class="form-control" name="f_server_domains[keyword]" value="<?php echo htmlspecialchars($fKeyword, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Domínio, usuário ou IP..." autocomplete="off" aria-label="Buscar domínios">
            <button class="btn btn-primary" type="submit" title="Esvazie o campo e busque de novo para ver todos"><i class="fa fa-search"></i> BUSCAR</button>
          </div>
          <?php
          // O select da ordem mora DENTRO do formulário da busca (submete junto
          // e é reenviado a cada busca, preservando a escolha). Sem select2: o
          // dropdown dele é anexado ao body e brigaria com o empilhamento do
          // offcanvas — e aqui não há ganho, são cinco opções fixas.
          ?>
          <select name="ordem" class="form-select form-select-sm mt-2 js-ordem-dominios" aria-label="Ordenar a listagem">
            <?php foreach ($ordenacoes as $valor => $rotulo) { ?>
              <option value="<?php echo htmlspecialchars($valor, ENT_QUOTES, 'UTF-8'); ?>" <?php if ($ordemAtual === (string) $valor) echo 'selected=""'; ?>>Ordenar por: <?php echo htmlspecialchars($rotulo, ENT_QUOTES, 'UTF-8'); ?></option>
            <?php } ?>
          </select>
        </form>
      </div>

      <div class="col-12 col-md-7 text-md-end">
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="offcanvas" data-bs-target="#offcanvas_filtros_dominios" aria-controls="offcanvas_filtros_dominios">
          <i class="fa fa-filter"></i> FILTROS
          <?php if (!empty($chips)) { ?><span class="badge bg-primary ms-1"><?php echo count($chips); ?></span><?php } ?>
        </button>
        <?php if ($temFiltro) { ?>
          <button type="submit" form="form_filtros_dominios" name="acao" value="limpar" class="btn btn-outline-secondary" title="Remove a busca e todos os filtros"><i class="fa fa-times"></i> LIMPAR</button>
        <?php } ?>
      </div>
    </div>

    <?php if (!empty($chips)) { ?>
      <div class="mt-3">
        <?php foreach ($chips as $chip) { ?>
          <span class="badge bg-light text-dark border me-1"><?php echo htmlspecialchars($chip, ENT_QUOTES, 'UTF-8'); ?></span>
        <?php } ?>
      </div>
    <?php } ?>

    <?php
    // O formulário mora no offcanvas para liberar o espaço da tela; o botão
    // LIMPAR acima usa `form=` para submeter daqui de fora sem duplicar rota.
    ?>
    <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvas_filtros_dominios" aria-labelledby="offcanvas_filtros_dominios_titulo">
      <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" id="offcanvas_filtros_dominios_titulo"><i class="fa fa-filter"></i> Filtros</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
      </div>
      <form id="form_filtros_dominios" method="POST" action="<?php echo base_url('servidores/post_filtrar_dominios'); ?>">
        <div class="offcanvas-body">
          <?php
          // A busca não é repetida aqui: ela vive no topo do card. Dois campos
          // com o mesmo nome em formulários diferentes discordariam — o último
          // submetido venceria, e o usuário veria a busca voltar sozinha ao
          // valor antigo ao usar o outro formulário.
          //
          // Os três selects são `form-select` puro, sem select2, e todos enviam
          // sempre (o "mostrar todos" é opção de valor vazio, não ausência do
          // campo), para o merge do post_filtrar_dominios conseguir apagar um
          // filtro que estava marcado.
          ?>
          <div class="form-group mb-3">
            <label class="form-label">Servidor</label>
            <select name="f_server_domains[id_server]" class="form-select">
              <option value="">-- MOSTRAR TODOS --</option>
              <?php foreach ($servers as $s) { ?>
                <option <?php if ($fServidor === (int) $s->id) echo 'selected=""'; ?> value="<?php echo (int) $s->id; ?>"><?php echo htmlspecialchars($s->name, ENT_QUOTES, 'UTF-8'); ?></option>
              <?php } ?>
            </select>
          </div>

          <div class="form-group mb-3">
            <label class="form-label">Situação</label>
            <select name="f_server_domains[status]" class="form-select">
              <option value="">-- MOSTRAR TODAS --</option>
              <?php foreach ($situacoes as $alias => $rotulo) { ?>
                <option <?php if ($fStatus === $alias) echo 'selected=""'; ?> value="<?php echo $alias; ?>"><?php echo $rotulo; ?></option>
              <?php } ?>
            </select>
            <small class="form-text text-muted">Estado da conta no painel de hospedagem.</small>
          </div>

          <div class="form-group mb-3">
            <label class="form-label">WHOIS</label>
            <select name="f_server_domains[whois_bucket]" class="form-select">
              <option value="">-- MOSTRAR TODOS --</option>
              <?php foreach ($faixas as $valor => $rotulo) { ?>
                <option value="<?php echo $valor; ?>" <?php if ($fBucket === $valor) echo 'selected=""'; ?>><?php echo $rotulo; ?></option>
              <?php } ?>
            </select>
            <small class="form-text text-muted">Faixa de vencimento do registro, pela última consulta ao registrador.</small>
          </div>
        </div>
        <div class="offcanvas-header border-top d-block">
          <button type="submit" class="btn btn-primary w-100 mb-2"><i class="fa fa-filter"></i> FILTRAR</button>
          <button type="submit" name="acao" value="limpar" class="btn btn-outline-secondary w-100"><i class="fa fa-times"></i> LIMPAR FILTROS</button>
        </div>
      </form>
    </div>

    <?php if (!empty($results)) { ?>
      <hr>
      <div class="table-responsive">
        <table class="table table-striped table-bordered table-hover">
          <thead>
            <tr>
              <th class="text-center" style="width: 90px;">Ações</th>
              <th>Domínio</th>
              <th>Cliente</th>
              <th>Servidor</th>
              <th class="text-center">Conta</th>
              <th class="text-center">Plano</th>
              <th class="text-center">Em uso</th>
              <th class="text-center">IP</th>
              <th class="text-center">Situação</th>
              <th class="text-center">Vencimento</th>
              <th class="text-center">NS</th>
              <th class="text-center">Sincronizado</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($results as $row) {
              // A faixa 'nacional' saiu da view na migration 014: .br passou a
              // ser consultado pelo RDAP. O TLD agora serve só para dizer, no
              // tooltip, qual origem o botão vai chamar.
              $nacional = (substr(mb_strtolower($row->domain), -3) === '.br');
            ?>
              <tr id="linha_dominio_<?php echo (int) $row->id; ?>">
                <td align="center" class="text-nowrap">
                  <button type="button" class="btn btn-sm btn-outline-primary js-whois" data-id="<?php echo (int) $row->id; ?>" data-dominio="<?php echo htmlspecialchars($row->domain, ENT_QUOTES, 'UTF-8'); ?>" title="Consultar <?php echo $nacional ? 'o RDAP do Registro.br' : 'a API Ninjas'; ?> agora">
                    <i class="fa fa-search"></i>
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-secondary js-historico-whois" data-id="<?php echo (int) $row->id; ?>" title="Histórico de alterações do WHOIS">
                    <i class="fa fa-history"></i>
                  </button>
                </td>
                <td>
                  <a href="http://<?php echo htmlspecialchars($row->domain, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer">
                    <?php echo htmlspecialchars($row->domain, ENT_QUOTES, 'UTF-8'); ?>
                  </a>
                  <?php if (!empty($row->contact_email)) { ?>
                    <br /><small class="text-muted"><?php echo htmlspecialchars($row->contact_email, ENT_QUOTES, 'UTF-8'); ?></small>
                  <?php } ?>
                  <?php if (!empty($row->whois_registrar)) { ?>
                    <br /><small class="text-muted js-registrar"><i class="fa fa-globe"></i> <?php echo htmlspecialchars($row->whois_registrar, ENT_QUOTES, 'UTF-8'); ?></small>
                  <?php } ?>
                </td>
                <td>
                  <?php
                  // Vem do vínculo com o domínio do contrato. Sem vínculo (ou
                  // com o domínio ainda fora de qualquer contrato) fica em
                  // branco — é lacuna de cadastro, não erro.
                  $clientes = isset($customers_by_domain[(int) $row->id]) ? $customers_by_domain[(int) $row->id] : [];
                  if (empty($clientes)) { ?>
                    <small class="text-muted">—</small>
                  <?php } else {
                    // Com mais de um cliente no mesmo domínio o documento entra
                    // junto: a base tem cadastros duplicados de mesma razão
                    // social, e o nome repetido sem nada que o distinga pareceria
                    // linha duplicada em vez de cadastro para conferir.
                    $varios = (count($clientes) > 1);
                    foreach ($clientes as $i => $cliente) { ?>
                      <?php if ($i > 0) echo '<br />'; ?>
                      <a href="<?php echo base_url('clientes/info?id=' . $cliente['id']); ?>"><small><?php echo htmlspecialchars($cliente['name'], ENT_QUOTES, 'UTF-8'); ?></small></a>
                      <?php if ($varios && !empty($cliente['document'])) { ?>
                        <small class="text-muted">(<?php echo cnpj($cliente['document']); ?>)</small>
                      <?php } ?>
                  <?php }
                  } ?>
                </td>
                <td>
                  <?php echo htmlspecialchars($row->server_name, ENT_QUOTES, 'UTF-8'); ?><br />
                  <span class="badge badge-xs <?php echo isset($badgeTipo[$row->source]) ? $badgeTipo[$row->source] : 'bg-dark'; ?>"><?php echo isset($server_types[$row->source]) ? $server_types[$row->source] : $row->source; ?></span>
                </td>
                <td align="center"><?php echo !empty($row->owner_username) ? htmlspecialchars($row->owner_username, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                <td align="center"><?php echo !empty($row->plan) ? htmlspecialchars($row->plan, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                <td align="center">
                  <?php echo formatar_disco($row->disk_used_mb); ?>
                  <?php if ($row->disk_limit_mb !== NULL && $row->disk_limit_mb !== '') { ?>
                    <br /><small class="text-muted">de <?php echo formatar_disco($row->disk_limit_mb); ?></small>
                  <?php } ?>
                </td>
                <td align="center"><?php echo !empty($row->ip) ? htmlspecialchars($row->ip, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                <td align="center">
                  <span class="badge bg-<?php echo ($row->status === 'ativo') ? 'success' : 'danger'; ?>"><?php echo ucfirst((string) $row->status); ?></span>
                  <?php if ($row->status !== 'ativo' && !empty($row->suspension_reason)) { ?>
                    <br /><small class="text-muted"><?php echo htmlspecialchars($row->suspension_reason, ENT_QUOTES, 'UTF-8'); ?></small>
                  <?php } ?>
                </td>
                <td align="center" class="js-cel-vencimento">
                  <?php if ($row->whois_bucket === 'pendente') { ?>
                    <small class="text-muted">Não consultado</small>
                  <?php } elseif ($row->whois_bucket === 'livre') { ?>
                    <?php // O vencimento antigo continua na coluna do banco, mas
                    // mostrá-lo aqui seria mentira: o registro não existe mais. ?>
                    <span class="badge bg-info" title="<?php echo htmlspecialchars((string) $row->whois_message, ENT_QUOTES, 'UTF-8'); ?>">Livre</span>
                    <br /><small class="text-muted">Não registrado</small>
                  <?php } elseif ($row->whois_bucket === 'erro') { ?>
                    <i class="fa fa-exclamation-triangle text-danger" title="<?php echo htmlspecialchars((string) $row->whois_message, ENT_QUOTES, 'UTF-8'); ?>"></i>
                    <br /><small class="text-muted">Falha na consulta</small>
                  <?php } elseif ($row->whois_bucket === 'sem_vencimento') { ?>
                    <small class="text-muted" title="O WHOIS respondeu, mas não informou data de vencimento para este TLD.">Não informado</small>
                  <?php } else { ?>
                    <span class="<?php echo classe_vencimento_whois($row->whois_bucket); ?>"><?php echo date('d/m/Y', strtotime($row->whois_expiration_date)); ?></span>
                    <br /><small class="text-muted"><?php echo prazo_vencimento_whois($row->whois_expiration_date); ?></small>
                  <?php } ?>
                </td>
                <td align="center" class="js-cel-ns">
                  <?php if (empty($row->whois_ns1)) { ?>
                    <small class="text-muted">—</small>
                  <?php } else { ?>
                    <small class="text-muted" title="<?php echo htmlspecialchars((string) $row->whois_nameservers, ENT_QUOTES, 'UTF-8'); ?>">
                      <?php echo htmlspecialchars($row->whois_ns1, ENT_QUOTES, 'UTF-8'); ?>
                      <?php if (!empty($row->whois_ns2)) { ?>
                        <br /><?php echo htmlspecialchars($row->whois_ns2, ENT_QUOTES, 'UTF-8'); ?>
                      <?php } ?>
                    </small>
                    <?php if (!empty($row->whois_ns_changed)) { ?>
                      <br /><span class="badge bg-warning" title="Nameservers alterados em <?php echo data($row->whois_ns_changed); ?>. Veja o histórico.">NS alterado</span>
                    <?php } ?>
                  <?php } ?>
                </td>
                <td align="center"><small class="text-muted"><?php echo !empty($row->last_sync) ? data($row->last_sync) : '—'; ?></small></td>
              </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>

      <div class="clearfix text-center">
        <?php echo $this->pagination->create_links(); ?>
      </div>

      <div class="alert alert-primary mb-0 alert-outline alert-dismissible" role="alert">
        <button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button>
        <div class="alert-message">
          <h4 class="alert-heading">Resumo</h4>
          <hr>
          <div class="row">
            <div class="col-12 col-sm-6">
              <p class="mb-0">Total: <strong><?php echo numero($est_count); ?></strong> domínio(s).</p>
            </div>
          </div>
        </div>
      </div>
    <?php } else { ?>
      <div class="alert alert-secondary alert-dismissible" role="alert">
        <button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button>
        <div class="alert-message">
          Nenhum domínio encontrado.
          <?php if (empty($servers)) { ?>
            Cadastre um servidor em <a href="<?php echo base_url('servidores'); ?>">Servidores</a> e sincronize.
          <?php } else { ?>
            Use o botão <strong>Sincronizar</strong> na tela de <a href="<?php echo base_url('servidores'); ?>">Servidores</a> para importar.
          <?php } ?>
        </div>
      </div>
    <?php } ?>
  </div>
</div>

<div class="modal fade" id="modal_whois" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">WHOIS — <span id="whois_dominio"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body" id="whois_corpo">
        <p class="text-muted mb-0">Consultando a API Ninjas...</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">FECHAR</button>
        <button type="button" class="btn btn-primary" id="btn_whois_atualizar"><i class="fa fa-rotate-right"></i> ATUALIZAR WHOIS</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="modal_historico_whois" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Histórico de WHOIS — <span id="historico_whois_dominio"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body" id="historico_whois_corpo">
        <p class="text-muted mb-0">Carregando...</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">FECHAR</button>
      </div>
    </div>
  </div>
</div>

<script>
  // DOMContentLoaded, e não $(function(){}): o app.js que traz o jQuery é
  // carregado no footer, ou seja, DEPOIS desta view. Referenciar $ aqui na hora
  // do parse estoura e nenhum handler chega a ser registrado.
  document.addEventListener("DOMContentLoaded", function() {
    // Trocar a ordem aplica na hora: o select vive no formulário da busca, que
    // leva junto a keyword atual — e o merge do servidor preserva os filtros do
    // offcanvas. Sem isto, mudar a ordem exigiria clicar em BUSCAR, que não é o
    // que o rótulo do botão promete.
    var selectOrdem = document.querySelector('.js-ordem-dominios');
    if (selectOrdem) {
      selectOrdem.addEventListener('change', function() {
        var form = document.getElementById('form_busca_dominios');
        if (form) form.submit();
      });
    }

    // Domínio aberto no modal, para o botão ATUALIZAR WHOIS repetir a consulta
    // sem depender de qual botão da tabela a abriu.
    var whoisAtual = null;

    // Faixas iguais às do PHP (classe_vencimento_whois): os limiares moram na
    // view do banco, aqui só a cor correspondente.
    function classeVencimento(bucket) {
      if (bucket === 'vencido') return 'text-danger fw-bold';
      if (bucket === 'vence_30') return 'text-danger';
      return 'text-success';
    }

    // Atualiza a linha da tabela no lugar. Sem isto, a listagem atrás do modal
    // continuaria mostrando o valor antigo até um refresh manual.
    function atualizarLinha(row) {
      if (!row) return;

      var $linha = $('#linha_dominio_' + row.id);
      if (!$linha.length) return;

      var $venc = $linha.find('.js-cel-vencimento').empty();
      if (row.bucket === 'livre') {
        // Espelha o ramo 'livre' do PHP: a consulta respondeu que o domínio não
        // está registrado, então a data antiga não vale mais como vencimento.
        $venc.append($('<span class="badge bg-info"></span>').text('Livre'))
          .append('<br>').append($('<small class="text-muted"></small>').text('Não registrado'));
      } else if (row.expiration) {
        $venc.append($('<span></span>').addClass(classeVencimento(row.bucket)).text(row.expiration));
      } else {
        $venc.append($('<small class="text-muted"></small>').text('Não informado'));
      }

      var $ns = $linha.find('.js-cel-ns').empty();
      if (row.ns1) {
        var $small = $('<small class="text-muted"></small>').attr('title', row.nameservers || '').text(row.ns1);
        if (row.ns2) $small.append('<br>').append(document.createTextNode(row.ns2));
        $ns.append($small);
        if (row.ns_changed) {
          $ns.append('<br>').append($('<span class="badge bg-warning"></span>')
            .attr('title', 'Nameservers alterados em ' + row.ns_changed + '. Veja o histórico.').text('NS alterado'));
        }
      } else {
        $ns.append('<small class="text-muted">—</small>');
      }
    }

    // Cada chamada bate na origem (API Ninjas ou RDAP) ignorando o intervalo de
    // reconsulta do cron — é sempre dado fresco, nunca cache.
    function consultarWhois() {
      if (!whoisAtual) return;

      var $corpo = $('#whois_corpo');
      var $atualizar = $('#btn_whois_atualizar');

      $corpo.html('<p class="text-muted mb-0"><i class="fa fa-spinner fa-spin"></i> Consultando o registro do domínio...</p>');
      $atualizar.prop('disabled', true);

      $.post('<?php echo base_url('servidores/json_postwhois'); ?>', {
        id: whoisAtual
      }, null, 'json').done(function(retorno) {
        if (!retorno || !retorno.success) {
          $corpo.html($('<div class="alert alert-danger mb-0"><div class="alert-message"></div></div>')
            .find('.alert-message').text(retorno && retorno.message ? retorno.message : 'Não foi possível consultar o WHOIS.').end());

          // Domínio livre chega por aqui: não há registro para exibir, mas o
          // servidor devolve a linha porque o estado foi gravado.
          if (retorno && retorno.data && retorno.data.row) atualizarLinha(retorno.data.row);
          return;
        }

        $corpo.empty();

        if (retorno.data.ns_changed) {
          $corpo.append('<div class="alert alert-warning"><div class="alert-message">Os nameservers deste domínio mudaram desde a última consulta. A alteração foi registrada no histórico.</div></div>');
        }

        var $tabela = $('<table class="table table-sm mb-0"></table>');
        var $tbody = $('<tbody></tbody>');

        $.each(retorno.data.fields, function(i, campo) {
          $tbody.append($('<tr></tr>')
            .append($('<th class="text-nowrap" style="width: 40%;"></th>').text(campo.label))
            .append($('<td></td>').text(campo.value)));
        });

        if (retorno.data.nameservers.length) {
          var $lista = $('<ul class="list-unstyled mb-0"></ul>');
          $.each(retorno.data.nameservers, function(i, ns) {
            $lista.append($('<li></li>').text(ns));
          });
          $tbody.append($('<tr></tr>')
            .append('<th class="text-nowrap">Nameservers</th>')
            .append($('<td></td>').append($lista)));
        }

        $tabela.append($tbody);
        $corpo.append($('<div class="table-responsive"></div>').append($tabela));
        $corpo.append('<small class="text-muted d-block mt-2">Consulta feita agora na origem. Os dados foram gravados e a listagem já reflete o resultado.</small>');

        atualizarLinha(retorno.data.row);
      }).fail(function() {
        $corpo.html('<div class="alert alert-danger mb-0"><div class="alert-message">Falha de comunicação ao consultar o WHOIS.</div></div>');
      }).always(function() {
        $atualizar.prop('disabled', false);
      });
    }

    $('.js-whois').on('click', function() {
      var $botao = $(this);

      whoisAtual = $botao.data('id');
      $('#whois_dominio').text($botao.data('dominio'));
      $('#modal_whois').modal('show');

      consultarWhois();
    });

    $('#btn_whois_atualizar').on('click', consultarWhois);

    $('.js-historico-whois').on('click', function() {
      var id = $(this).data('id');
      var $corpo = $('#historico_whois_corpo');

      $('#historico_whois_dominio').text('');
      $corpo.html('<p class="text-muted mb-0">Carregando...</p>');
      $('#modal_historico_whois').modal('show');

      $.get('<?php echo base_url('servidores/json_gethistoricowhois'); ?>', {
        id: id
      }, null, 'json').done(function(retorno) {
        if (!retorno || !retorno.success) {
          $corpo.html($('<p class="text-danger mb-0"></p>').text(retorno && retorno.message ? retorno.message : 'Não foi possível carregar o histórico.'));
          return;
        }

        $('#historico_whois_dominio').text(retorno.data.domain);

        if (!retorno.data.items.length) {
          $corpo.html('<p class="text-muted mb-0">Nenhuma alteração registrada. O histórico começa a partir da segunda consulta de cada domínio — a primeira apenas registra o retrato inicial.</p>');
          return;
        }

        var $tabela = $('<table class="table table-sm table-striped mb-0"></table>');
        $tabela.append('<thead><tr><th>Quando</th><th>Campo</th><th>De</th><th>Para</th></tr></thead>');
        var $corpoTabela = $('<tbody></tbody>');

        $.each(retorno.data.items, function(i, item) {
          var $linha = $('<tr></tr>');
          $linha.append($('<td class="text-nowrap"></td>').text(item.detected));
          $linha.append($('<td></td>').text(item.field));
          $linha.append($('<td><small class="text-muted"></small></td>').find('small').text(item.old_value || '—').end());
          $linha.append($('<td><small></small></td>').find('small').text(item.new_value || '—').end());
          $corpoTabela.append($linha);
        });

        $tabela.append($corpoTabela);
        $corpo.empty().append($('<div class="table-responsive"></div>').append($tabela));
      }).fail(function() {
        $corpo.html('<p class="text-danger mb-0">Falha de comunicação ao carregar o histórico.</p>');
      });
    });
  });
</script>
