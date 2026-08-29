<?php
/**
 * IPs contratados — inventário e cadastro.
 *
 * "Alocado" é TER CLIENTE VINCULADO; "disponível" é não ter. Não existe coluna de
 * situação, então os cartões do topo e a coluna Cliente da tabela leem o mesmo fato e
 * não têm como discordar. Ver o docblock da migration 043.
 */
$filtro = (array) $this->session->userdata('f_ips');

$fKeyword = isset($filtro['keyword']) ? trim((string) $filtro['keyword']) : '';
$fSituacao = isset($filtro['situation']) ? (string) $filtro['situation'] : '';

// Chip só do que está ESCONDIDO no offcanvas. A busca não vira chip: o campo está à
// vista e preenchido — mesma regra da listagem de clientes.
$chips = [];
if ($fSituacao !== '' && isset($situacoes[$fSituacao])) $chips[] = 'Situação: ' . $situacoes[$fSituacao];

// "Tem filtro aplicado?" é pergunta diferente de "tem chip?": a busca visível também
// recorta a listagem e também precisa do LIMPAR.
$temFiltro = ($fKeyword !== '' || !empty($chips));

$total = (int) $resumo['total'];
$alocados = (int) $resumo['alocados'];
$disponiveis = (int) $resumo['disponiveis'];

/**
 * Percentual sobre o total do inventário. Uma casa decimal, como os cards do
 * Dashboard: com a base grande, arredondar para inteiro faz toda faixa pequena virar
 * "0%". A guarda de total zero é o que impede a divisão por zero na base vazia.
 */
$percentual = function ($parte, $todo) {
  if ((int) $todo <= 0) return 0.0;
  return round(($parte / $todo) * 100, 1);
};

/**
 * Largura da barra, em INTEIRO — e o inteiro não é estética, é o que impede a barra
 * de sumir. O projeto chama setlocale(LC_ALL, 'pt_BR') em MY_Controller::renovar_sessao()
 * e em Login.php, e setlocale é PROCESS-WIDE: num worker que já atendeu um login,
 * `echo 62.5` imprime "62,5" e o CSS vira `width: 62,5%`, que é inválido — a barra
 * zera, de forma intermitente conforme o worker que atender a requisição. Por isso o
 * float só aparece em number_format(), que leva os separadores explícitos.
 *
 * Piso de 2% só para valor > 0: uma faixa de 0,4% arredondaria para uma barra
 * invisível e o card diria "não há nada aqui" justamente onde há — mas zero tem de
 * ficar zero, senão a tela inventa movimento.
 */
$largura = function ($pct, $parte) {
  return ($parte > 0) ? (int) max(2, round($pct)) : 0;
};

$pctAlocados = $percentual($alocados, $total);
$pctDisponiveis = $percentual($disponiveis, $total);

$larguraAlocados = $largura($pctAlocados, $alocados);
$larguraDisponiveis = $largura($pctDisponiveis, $disponiveis);

// Barra empilhada do card Total: a segunda fatia é o RESTO da primeira, e não o
// próprio arredondamento — dois arredondamentos independentes somam 101 num caso como
// 33,5 + 66,5, e a fatia excedente estoura a barra.
$stackAlocados = ($total > 0) ? (int) round($pctAlocados) : 0;
$stackDisponiveis = ($total > 0) ? (100 - $stackAlocados) : 0;

// Vermelho só no fato operacional "acabou o estoque", nunca num limiar inventado: não
// sabemos qual reserva é confortável para esta operação.
$corDisponibilidade = ($total > 0 && $disponiveis === 0) ? 'danger' : 'success';

$badgeTipo = ['J' => 'bg-primary', 'F' => 'bg-info'];
$rotuloTipo = ['J' => 'Jurídica', 'F' => 'Física'];
?>
<div class="row mb-2 mb-xl-2">
  <div class="col-auto text-start">
    <h1 class="h3 mb-0">IPs contratados</h1>
    <p class="text-muted mb-2"><small>Um IP é considerado <strong>alocado</strong> quando tem cliente vinculado.</small></p>
  </div>
</div>

<div class="row">
  <div class="col-12 col-sm-6 col-xl-3 mb-3">
    <div class="card flex-fill h-100">
      <div class="card-body py-3">
        <div class="d-flex align-items-center mb-1">
          <i class="mdi mdi-ip-network-outline text-primary fs-2 me-2"></i>
          <div>
            <span class="text-muted">Total de IPs</span>
            <small class="d-block text-muted">Cadastrados nesta empresa</small>
          </div>
        </div>
        <h2 class="mb-1 <?php echo $total > 0 ? 'text-primary' : 'text-muted'; ?>"><?php echo numero($total); ?></h2>
        <?php // Barra empilhada: mostra a composição do inventário sem repetir os outros dois cards. ?>
        <div class="progress mb-1" style="height: 10px;">
          <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo $stackAlocados; ?>%;" aria-valuenow="<?php echo $alocados; ?>" aria-valuemin="0" aria-valuemax="<?php echo $total; ?>" aria-label="Alocados"></div>
          <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $stackDisponiveis; ?>%;" aria-valuenow="<?php echo $disponiveis; ?>" aria-valuemin="0" aria-valuemax="<?php echo $total; ?>" aria-label="Disponíveis"></div>
        </div>
        <small class="text-muted"><?php echo numero($alocados); ?> alocado(s) · <?php echo numero($disponiveis); ?> disponível(is)</small>
      </div>
    </div>
  </div>

  <div class="col-12 col-sm-6 col-xl-3 mb-3">
    <div class="card flex-fill h-100">
      <div class="card-body py-3">
        <div class="d-flex align-items-center mb-1">
          <i class="mdi mdi-account-check-outline text-info fs-2 me-2"></i>
          <div>
            <span class="text-muted">Alocados</span>
            <small class="d-block text-muted">Com cliente vinculado</small>
          </div>
        </div>
        <h2 class="mb-1 <?php echo $alocados > 0 ? 'text-info' : 'text-muted'; ?>"><?php echo numero($alocados); ?></h2>
        <div class="progress mb-1" style="height: 10px;">
          <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo $larguraAlocados; ?>%;" aria-valuenow="<?php echo $alocados; ?>" aria-valuemin="0" aria-valuemax="<?php echo $total; ?>" aria-label="Alocados"></div>
        </div>
        <small class="text-muted"><strong><?php echo number_format($pctAlocados, 1, ',', '.'); ?>%</strong> do total</small>
      </div>
    </div>
  </div>

  <div class="col-12 col-sm-6 col-xl-3 mb-3">
    <div class="card flex-fill h-100">
      <div class="card-body py-3">
        <div class="d-flex align-items-center mb-1">
          <i class="mdi mdi-check-circle-outline text-success fs-2 me-2"></i>
          <div>
            <span class="text-muted">Disponíveis</span>
            <small class="d-block text-muted">Sem cliente vinculado</small>
          </div>
        </div>
        <h2 class="mb-1 <?php echo $disponiveis > 0 ? 'text-success' : 'text-muted'; ?>"><?php echo numero($disponiveis); ?></h2>
        <div class="progress mb-1" style="height: 10px;">
          <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $larguraDisponiveis; ?>%;" aria-valuenow="<?php echo $disponiveis; ?>" aria-valuemin="0" aria-valuemax="<?php echo $total; ?>" aria-label="Disponíveis"></div>
        </div>
        <small class="text-muted"><strong><?php echo number_format($pctDisponiveis, 1, ',', '.'); ?>%</strong> do total</small>
      </div>
    </div>
  </div>

  <div class="col-12 col-sm-6 col-xl-3 mb-3">
    <div class="card flex-fill h-100">
      <div class="card-body py-3">
        <div class="d-flex align-items-center mb-1">
          <i class="mdi mdi-chart-donut text-<?php echo $corDisponibilidade; ?> fs-2 me-2"></i>
          <div>
            <span class="text-muted">Disponibilidade</span>
            <small class="d-block text-muted">Percentual livre do inventário</small>
          </div>
        </div>
        <?php // Com a base vazia o número é travessão, e não "0,0%": não há escassez onde não há inventário. ?>
        <h2 class="mb-1 <?php echo $total > 0 ? 'text-' . $corDisponibilidade : 'text-muted'; ?>">
          <?php echo ($total > 0) ? number_format($pctDisponiveis, 1, ',', '.') . '%' : '—'; ?>
        </h2>
        <div class="progress mb-1" style="height: 10px;">
          <div class="progress-bar bg-<?php echo $corDisponibilidade; ?>" role="progressbar" style="width: <?php echo $larguraDisponiveis; ?>%;" aria-valuenow="<?php echo $disponiveis; ?>" aria-valuemin="0" aria-valuemax="<?php echo $total; ?>" aria-label="Disponibilidade"></div>
        </div>
        <small class="text-muted">
          <?php if ($total > 0) { ?>
            <?php echo numero($disponiveis); ?> de <?php echo numero($total); ?> livre(s)
          <?php } else { ?>
            Nenhum IP cadastrado ainda
          <?php } ?>
        </small>
      </div>
    </div>
  </div>
</div>

<div class="card flex-fill">
  <div class="card-body">
    <div class="row flex-fill">
      <div class="col-12">
        <div class="tab">
          <ul class="nav nav-pills mt-2" role="tablist">
            <li class="nav-item"><a class="nav-link active" href="#tab-1" data-bs-toggle="tab" role="tab" aria-selected="true">Listagem</a></li>
            <li class="nav-item"><a class="nav-link" href="#tab-2" data-bs-toggle="tab" role="tab"><i class="fa fa-plus"></i> Novo</a></li>
          </ul>
          <div class="tab-content" style="box-shadow: none; padding: 10px 0 0 0;">

            <div class="tab-pane active" id="tab-1" role="tabpanel">
              <div class="row g-2 align-items-center mb-2">
                <div class="col-12 col-md-9">
                  <form method="POST" action="<?php echo base_url('ips-contratados/post_filtrar'); ?>" role="search">
                    <div class="input-group">
                      <input type="text" class="form-control" name="f_ips[keyword]" value="<?php echo htmlspecialchars($fKeyword, ENT_QUOTES, 'UTF-8'); ?>" placeholder="IP, cliente, documento ou observação..." autocomplete="off" aria-label="Buscar IPs">
                      <button class="btn btn-primary" type="submit" title="Esvazie o campo e busque de novo para ver todos"><i class="fa fa-search"></i> BUSCAR</button>
                    </div>
                  </form>
                </div>
                <div class="col-12 col-md-3 text-md-end">
                  <button type="button" class="btn btn-outline-primary" data-bs-toggle="offcanvas" data-bs-target="#offcanvas_filtros_ips" aria-controls="offcanvas_filtros_ips">
                    <i class="fa fa-filter"></i> FILTROS
                    <?php if (!empty($chips)) { ?><span class="badge bg-primary ms-1"><?php echo count($chips); ?></span><?php } ?>
                  </button>
                  <?php if ($temFiltro) { ?>
                    <button type="submit" form="form_filtros_ips" name="acao" value="limpar" class="btn btn-outline-secondary" title="Remove a busca e os filtros"><i class="fa fa-times"></i> LIMPAR</button>
                  <?php } ?>
                </div>
              </div>

              <?php if (!empty($chips)) { ?>
                <div class="mb-3">
                  <?php foreach ($chips as $chip) { ?>
                    <span class="badge bg-light text-dark border me-1"><?php echo htmlspecialchars($chip, ENT_QUOTES, 'UTF-8'); ?></span>
                  <?php } ?>
                </div>
              <?php } ?>

              <?php // Os selects do offcanvas são form-select puro, SEM select2: o dropdown
              // do select2 é anexado ao body e briga com o empilhamento do offcanvas. ?>
              <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvas_filtros_ips" aria-labelledby="offcanvas_filtros_ips_titulo">
                <div class="offcanvas-header border-bottom">
                  <h5 class="offcanvas-title" id="offcanvas_filtros_ips_titulo"><i class="fa fa-filter"></i> Filtros</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
                </div>
                <form id="form_filtros_ips" method="POST" name="form" action="<?php echo base_url('ips-contratados/post_filtrar'); ?>">
                  <div class="offcanvas-body">
                    <div class="form-group mb-3">
                      <label class="form-label">Situação</label>
                      <select name="f_ips[situation]" class="form-select">
                        <option value="">-- MOSTRAR TODOS (<?php echo numero($total); ?>) --</option>
                        <?php foreach ($situacoes as $slug => $rotulo) {
                          $qtd = ($slug === 'alocado') ? $alocados : $disponiveis; ?>
                          <option value="<?php echo $slug; ?>" <?php if ($fSituacao === $slug) echo 'selected=""'; ?>><?php echo $rotulo; ?> (<?php echo numero($qtd); ?>)</option>
                        <?php } ?>
                      </select>
                      <small class="form-text text-muted">Derivada do vínculo com o cliente — não é um campo do cadastro.</small>
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
                        <th style="min-width: 130px;">IP</th>
                        <th>Cliente</th>
                        <th class="text-center" style="min-width: 130px;">Documento</th>
                        <th class="text-center">Tipo</th>
                        <th>Observações</th>
                        <th class="text-center">Cadastrado em</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($results as $row) { ?>
                        <tr>
                          <td align="center">
                            <div class="d-flex justify-content-center align-items-center gap-2">
                              <a class="btn btn-sm btn-outline-primary" href="<?php echo base_url('ips-contratados/editar?id=' . (int) $row->id); ?>" title="Editar IP"><i class="fa fa-pencil"></i></a>
                              <button type="button" class="btn btn-sm btn-outline-danger js-excluir" data-id="<?php echo (int) $row->id; ?>" data-ip="<?php echo htmlspecialchars($row->ip, ENT_QUOTES, 'UTF-8'); ?>" data-cliente="<?php echo htmlspecialchars((string) $row->customer_name, ENT_QUOTES, 'UTF-8'); ?>" title="Excluir IP"><i class="fa fa-trash"></i></button>
                            </div>
                          </td>
                          <td><code><?php echo htmlspecialchars($row->ip, ENT_QUOTES, 'UTF-8'); ?></code></td>
                          <td>
                            <?php if (!empty($row->id_customer)) { ?>
                              <a href="<?php echo base_url('clientes/info?id=' . (int) $row->id_customer); ?>" title="Abrir a visão geral do cliente">
                                <small><?php echo htmlspecialchars((string) $row->customer_name, ENT_QUOTES, 'UTF-8'); ?></small>
                              </a>
                              <?php if (!empty($row->customer_byname)) { ?>
                                <small class="d-block text-muted"><?php echo htmlspecialchars($row->customer_byname, ENT_QUOTES, 'UTF-8'); ?></small>
                              <?php } ?>
                            <?php } else { ?>
                              <span class="badge bg-success">Disponível</span>
                            <?php } ?>
                          </td>
                          <td align="center">
                            <?php if (!empty($row->customer_document)) { ?>
                              <small><?php echo cnpj($row->customer_document); ?></small>
                            <?php } else { ?>
                              <span class="text-muted">—</span>
                            <?php } ?>
                          </td>
                          <td align="center">
                            <?php if (!empty($row->customer_type)) { ?>
                              <span class="badge <?php echo isset($badgeTipo[$row->customer_type]) ? $badgeTipo[$row->customer_type] : 'bg-dark'; ?>" title="<?php echo isset($rotuloTipo[$row->customer_type]) ? $rotuloTipo[$row->customer_type] : ''; ?>"><?php echo htmlspecialchars($row->customer_type, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php } else { ?>
                              <span class="text-muted">—</span>
                            <?php } ?>
                          </td>
                          <td><small class="text-muted"><?php echo htmlspecialchars((string) $row->comments, ENT_QUOTES, 'UTF-8'); ?></small></td>
                          <td align="center"><small><?php echo data($row->created); ?></small></td>
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
                    <p class="mb-0">
                      <?php echo numero($total_results); ?> IP(s) no filtro atual, de <strong><?php echo numero($total); ?></strong> cadastrado(s) nesta empresa.
                      Clique no nome do cliente para abrir a visão geral dele.
                    </p>
                  </div>
                </div>
              <?php } else { ?>
                <div class="alert alert-secondary mb-0 alert-dismissible" role="alert">
                  <button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button>
                  <div class="alert-message">
                    <?php if ($temFiltro) { ?>
                      Nenhum IP encontrado com a busca/filtros aplicados. Clique em <strong>LIMPAR</strong> para ver todos.
                    <?php } else { ?>
                      Nenhum IP cadastrado. Use a aba <strong>Novo</strong> para incluir o primeiro — o vínculo com o cliente é opcional.
                    <?php } ?>
                  </div>
                </div>
              <?php } ?>
            </div>

            <div class="tab-pane" id="tab-2" role="tabpanel">
              <form method="POST" action="<?php echo base_url('ips-contratados/post_salvar'); ?>" name="form">
                <div class="row">
                  <div class="col-xs-12 col-md-4">
                    <div class="form-group mb-3">
                      <label class="form-label"><span class="required" aria-required="true"> * </span> IP</label>
                      <input type="text" class="form-control" name="ip" maxlength="15" required="" placeholder="187.45.192.10" autocomplete="off">
                      <small class="form-text text-muted">Apenas IPv4. Não pode repetir dentro desta empresa.</small>
                    </div>
                  </div>
                  <div class="col-xs-12 col-md-8">
                    <div class="form-group mb-3">
                      <label class="form-label">Cliente</label>
                      <?php // O style="width: 100%" é obrigatório: a aba nasce escondida e, sem
                      // largura explícita, o select2 mede um elemento de tamanho zero. ?>
                      <select name="id_customer" class="form-control select2" style="width: 100%">
                        <option value="0">-- SEM VÍNCULO (IP DISPONÍVEL) --</option>
                        <?php foreach ($customers as $cliente) { ?>
                          <option value="<?php echo (int) $cliente->id; ?>">
                            <?php echo htmlspecialchars($cliente->name, ENT_QUOTES, 'UTF-8'); ?><?php echo !empty($cliente->document) ? ' — ' . cnpj($cliente->document) : ''; ?>
                          </option>
                        <?php } ?>
                      </select>
                      <small class="form-text text-muted">Vincular a um cliente é o que marca o IP como alocado. Deixe sem vínculo para mantê-lo disponível.</small>
                    </div>
                  </div>
                </div>
                <div class="row">
                  <div class="col-xs-12 col-md-12">
                    <div class="form-group mb-3">
                      <label class="form-label">Observações</label>
                      <textarea class="form-control" name="comments" rows="2" maxlength="500"></textarea>
                    </div>
                  </div>
                </div>
                <div class="row">
                  <div class="col-xs-12 col-md-12">
                    <hr>
                  </div>
                </div>
                <div class="row">
                  <div class="col"><button class="btn btn-primary w-100" type="submit"><i class="fa fa-save"></i> SALVAR</button></div>
                  <div class="col"></div>
                  <div class="col"></div>
                </div>
              </form>
            </div>

          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<form method="POST" id="form_excluir" action="<?php echo base_url('ips-contratados/post_excluir'); ?>">
  <input type="hidden" name="id" value="">
</form>

<script>
  document.addEventListener("DOMContentLoaded", function() {
    // Abre a aba do formulário quando a URL traz #tab-2. É por onde o post_salvar
    // devolve quem errou o IP: sem isso a mensagem apareceria sobre a Listagem, e o
    // campo a corrigir ficaria numa aba fechada.
    if (window.location.hash === '#tab-2') {
      var gatilho = document.querySelector('a[href="#tab-2"][data-bs-toggle="tab"]');
      if (gatilho) gatilho.click();
    }

    $(document).on('click', '.js-excluir', function() {
      var id = $(this).data('id');
      var ip = $(this).data('ip');
      var cliente = $(this).data('cliente');

      var html = 'O IP <strong>' + ip + '</strong> será excluído <strong>definitivamente</strong>.';
      if (cliente) {
        html += '<br><small>Ele está alocado para <strong>' + cliente + '</strong>.</small>';
      }
      html += '<br><small>Esta ação não pode ser desfeita.</small>';

      Swal.fire({
        title: 'Excluir IP?',
        html: html,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#ccc',
        cancelButtonText: 'Cancelar',
        confirmButtonText: 'Excluir'
      }).then(function(result) {
        if (result.value) {
          $('#form_excluir input[name="id"]').val(id);
          $('#form_excluir').submit();
        }
      });
    });
  });
</script>
