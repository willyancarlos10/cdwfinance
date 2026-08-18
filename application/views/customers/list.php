<?php
$filtro = (array) $this->session->userdata('f_customers');
$tipos = ['J' => 'Pessoa jurídica', 'F' => 'Pessoa física'];
$badgeTipo = ['J' => 'bg-primary', 'F' => 'bg-info'];

$fKeyword = isset($filtro['keyword']) ? trim((string) $filtro['keyword']) : '';
$fTipo = isset($filtro['type']) ? (string) $filtro['type'] : '';
$fServico = isset($filtro_avancado['id_service_type']) ? (int) $filtro_avancado['id_service_type'] : 0;
$fSituacao = isset($filtro_avancado['situation']) ? (string) $filtro_avancado['situation'] : '';
$fSemVinculoBc = !empty($filtro_avancado['bomcontrole_unlinked']);

// Chips do que está ESCONDIDO no offcanvas — sem eles o usuário não tem como
// saber por que a listagem está curta. A busca por palavra-chave não entra
// aqui: ela tem campo próprio, visível e preenchido, no topo do card.
$chips = [];
if ($fTipo !== '' && isset($tipos[$fTipo])) $chips[] = 'Tipo: ' . $tipos[$fTipo];
if ($fServico > 0) {
  foreach ($service_types as $st) {
    if ((int) $st->id === $fServico) $chips[] = 'Tipo de contrato: ' . $st->name;
  }
}
if ($fSituacao !== '' && isset($contract_situations[$fSituacao])) $chips[] = $contract_situations[$fSituacao];
if ($fSemVinculoBc) $chips[] = 'Contrato sem vínculo com o Bom Controle';

// "Tem algum filtro aplicado?" é pergunta diferente de "tem chip?": a busca
// visível também recorta a listagem e também precisa do LIMPAR.
$temFiltro = ($fKeyword !== '' || !empty($chips));
?>
<div class="row mb-2 mb-xl-2">
  <div class="col-auto text-start">
    <h1 class="h3 mb-0">Clientes</h1>
    <!-- <p class="text-muted mb-2">Gerencie os cadastros e acompanhe a visão geral de cada cliente.</p> -->
  </div>
  <div class="col-auto ms-auto text-end mt-n1">
    <?php
    // Relatórios da base de clientes. Todos exportam o que a listagem está
    // mostrando (mesmos filtros), então o botão fica desabilitado quando o
    // filtro não devolveu ninguém — planilha só com cabeçalho não ajuda.
    ?>
    <div class="btn-group">
      <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" <?php if (empty($results)) echo 'disabled'; ?>>
        <i class="mdi mdi-file-chart-outline"></i> RELATÓRIOS
      </button>
      <ul class="dropdown-menu dropdown-menu-end text-start">
        <li>
          <h6 class="dropdown-header">Base de clientes</h6>
        </li>
        <li>
          <a class="dropdown-item" href="<?php echo base_url('clientes/exportar_excel'); ?>">
            <i class="fa fa-file-excel text-success"></i> Exportar para o Excel
            <br><small class="text-muted"><?php echo numero($est_count); ?> cliente(s)<?php echo $temFiltro ? ' — considera a busca e os filtros aplicados' : ''; ?></small>
          </a>
        </li>
      </ul>
    </div>
    <!-- <button type="button" class="btn btn-outline-secondary" id="btn_copiar_link" data-link="<?php echo $public_link; ?>" title="Copiar o link público de cadastro desta empresa para enviar aos clientes"><i class="mdi mdi-link-variant"></i> COPIAR LINK</button> -->
    <a class="btn btn-primary" target="_blank" href="<?php echo $public_link; ?>" title="Abre o cadastro público desta empresa — o mesmo link que o cliente usa"><i class="fa fa-plus"></i> NOVO CLIENTE</a>
  </div>
</div>

<?php
// Os cards de indicadores (clientes por status e contratos vigentes por tipo
// de serviço) saíram desta tela e viraram o dashboard (Painel::index +
// views/dashboard.php). Aqui fica só a base de clientes.
?>
<div class="card flex-fill">
  <div class="card-body py-3">
    <div class="row g-2 align-items-center mb-2">
      <!-- <div class="col-12 col-xl-4">
        <h5 class="card-title mb-0">Base de clientes</h5>
        <span class="text-muted"><?php echo numero($est_count); ?> cliente(s) <?php echo $temFiltro ? 'no filtro' : 'cadastrado(s)'; ?>.</span>
      </div> -->

      <?php
      // Busca por palavra-chave fora do offcanvas: é o filtro usado o tempo
      // todo e escondê-lo custava dois cliques por consulta. Formulário
      // próprio, com o mesmo destino do offcanvas — o post_filtrar faz merge
      // sobre o filtro da sessão, então buscar aqui NÃO derruba o tipo de
      // pessoa nem os filtros de contrato que estiverem marcados lá dentro.
      ?>
      <div class="col-12 col-md-10">
        <form method="POST" action="<?php echo base_url('clientes/post_filtrar'); ?>" role="search">
          <div class="input-group">
            <input type="text" class="form-control" name="f_customers[keyword]" value="<?php echo htmlspecialchars($fKeyword, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Nome, nome fantasia, documento ou e-mail..." autocomplete="off" aria-label="Buscar clientes">
            <button class="btn btn-primary" type="submit" title="Esvazie o campo e busque de novo para ver a base inteira"><i class="fa fa-search"></i> BUSCAR</button>
          </div>
        </form>
      </div>

      <div class="col-12 col-md-2 text-md-end">
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="offcanvas" data-bs-target="#offcanvas_filtros_clientes" aria-controls="offcanvas_filtros_clientes">
          <i class="fa fa-filter"></i> FILTROS
          <?php if (!empty($chips)) { ?><span class="badge bg-primary ms-1"><?php echo count($chips); ?></span><?php } ?>
        </button>
        <?php if ($temFiltro) { ?>
          <button type="submit" form="form_filtros_clientes" name="acao" value="limpar" class="btn btn-outline-secondary" title="Remove a busca e todos os filtros"><i class="fa fa-times"></i> LIMPAR</button>
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

    <?php
    // O formulário mora no offcanvas para liberar o espaço da tela; o botão
    // LIMPAR acima usa `form=` para submeter daqui de fora sem duplicar rota.
    ?>
    <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvas_filtros_clientes" aria-labelledby="offcanvas_filtros_clientes_titulo">
      <div class="offcanvas-header border-bottom">
        <h5 class="offcanvas-title" id="offcanvas_filtros_clientes_titulo"><i class="fa fa-filter"></i> Filtros</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fechar"></button>
      </div>
      <form id="form_filtros_clientes" method="POST" name="form" action="<?php echo base_url('clientes/post_filtrar'); ?>">
        <div class="offcanvas-body">
          <?php
          // A busca por palavra-chave não é repetida aqui: ela vive no topo do
          // card. Dois campos com o mesmo nome em formulários diferentes
          // discordariam — o último submetido venceria, e o usuário veria a
          // busca voltar sozinha ao valor antigo ao usar o outro formulário.
          ?>
          <div class="form-group mb-3">
            <label class="form-label">Tipo de pessoa</label>
            <select name="f_customers[type]" class="form-select">
              <option value="">-- MOSTRAR TODOS --</option>
              <?php foreach ($tipos as $alias => $rotulo) { ?>
                <option <?php if ($fTipo === $alias) echo 'selected=""'; ?> value="<?php echo $alias; ?>"><?php echo $rotulo; ?></option>
              <?php } ?>
            </select>
          </div>

          <div class="form-group mb-3">
            <label class="form-label">Tipo de contrato</label>
            <select name="<?php echo $filtro_avancado_campo; ?>[id_service_type]" class="form-select">
              <option value="0">-- MOSTRAR TODOS --</option>
              <?php foreach ($service_types as $st) { ?>
                <option <?php if ($fServico === (int) $st->id) echo 'selected=""'; ?> value="<?php echo (int) $st->id; ?>"><?php echo htmlspecialchars($st->name, ENT_QUOTES, 'UTF-8'); ?></option>
              <?php } ?>
            </select>
            <small class="form-text text-muted">Considera os contratos de qualquer situação — inclusive os encerrados.</small>
          </div>

          <div class="form-group mb-3">
            <label class="form-label">Situação contratual</label>
            <select name="<?php echo $filtro_avancado_campo; ?>[situation]" class="form-select">
              <option value="">-- MOSTRAR TODOS --</option>
              <?php foreach ($contract_situations as $alias => $rotulo) { ?>
                <option <?php if ($fSituacao === $alias) echo 'selected=""'; ?> value="<?php echo $alias; ?>"><?php echo $rotulo; ?></option>
              <?php } ?>
            </select>
            <small class="form-text text-muted">Cliente vigente é o que tem pelo menos um contrato com status <strong>vigente</strong>.</small>
          </div>

          <div class="form-group mb-3">
            <label class="form-label">Integração</label>
            <?php
            // O hidden garante que a chave chegue ao POST mesmo com o switch
            // desmarcado: checkbox desmarcado não é enviado pelo navegador, e o
            // post_filtrar distingue "veio deste formulário" de "veio da busca
            // rápida do topo" pela presença da chave.
            ?>
            <input type="hidden" name="<?php echo $filtro_avancado_campo; ?>[bomcontrole_unlinked]" value="0">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" id="f_bomcontrole_unlinked" name="<?php echo $filtro_avancado_campo; ?>[bomcontrole_unlinked]" value="1" <?php if ($fSemVinculoBc) echo 'checked=""'; ?>>
              <label class="form-check-label" for="f_bomcontrole_unlinked">Somente clientes com contrato sem vínculo com o Bom Controle</label>
            </div>
            <small class="form-text text-muted">Considera os contratos <strong>não encerrados</strong> e cobrados pelo Bom Controle — contrato migrado para o faturamento próprio não é pendência.</small>
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
              <th class="text-center" style="width: 50px;">ID</th>
              <th>Nome</th>
              <th>Nome fantasia</th>
              <th class="text-center">F/J</th>
              <th class="text-center" style="min-width: 130px;">Documento</th>
              <th class="text-center">Contratos</th>
              <th class="text-center">Data</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($results as $row) { ?>
              <tr>
                <td align="center">
                  <a class="btn btn-sm btn-outline-primary w-100" href="<?php echo base_url('clientes/info?id=' . (int) $row->id); ?>" title="Visão geral"><i class="fa fa-eye"></i> ABRIR</a>
                  <!-- <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('clientes/editar?id=' . (int) $row->id); ?>" title="Editar"><i class="fa fa-edit"></i></a> -->
                </td>
                <td align="center"><?php echo (int) $row->id; ?></td>
                <td>
                  <a href="<?php echo base_url('clientes/info?id=' . (int) $row->id); ?>"><small><?php echo htmlspecialchars($row->name, ENT_QUOTES, 'UTF-8'); ?></small></a>
                </td>
                <td><small><?php echo htmlspecialchars((string) $row->byname, ENT_QUOTES, 'UTF-8'); ?></small></td>
                <td align="center">
                  <span class="badge <?php echo isset($badgeTipo[$row->type]) ? $badgeTipo[$row->type] : 'bg-dark'; ?>"><?php echo $row->type; ?></span>
                </td>
                <td align="center"><small><?php echo cnpj($row->document); ?></small></td>
                <td align="center">
                  <?php if ((int) $row->active_contracts_count > 0) { ?>
                    <span class="badge bg-success"><?php echo numero((int) $row->active_contracts_count); ?> vigente(s)</span>
                  <?php } else { ?>
                    <span class="badge bg-secondary">Sem contrato vigente</span>
                  <?php } ?>
                </td>
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
          <div class="row">
            <div class="col-12 col-sm-6">
              <p class="mb-0">Total: <strong><?php echo numero($est_count); ?></strong> cliente(s).</p>
            </div>
          </div>
        </div>
      </div>
    <?php } else { ?>
      <div class="alert alert-secondary alert-dismissible" role="alert">
        <button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button>
        <div class="alert-message">
          <?php if ($temFiltro) { ?>
            Nenhum cliente encontrado com a busca/filtros aplicados. Clique em <strong>LIMPAR</strong> para ver a base inteira.
          <?php } else { ?>
            Nenhum cliente encontrado. Clique em <strong>NOVO CLIENTE</strong> para abrir o cadastro público — é o mesmo link da tela de login, que você pode compartilhar com seus clientes.
          <?php } ?>
        </div>
      </div>
    <?php } ?>
  </div>
</div>

<script>
  document.addEventListener("DOMContentLoaded", function() {
    function notificar(tipo, mensagem) {
      window.notyf.open({
        type: tipo,
        message: mensagem,
        duration: 7000,
        ripple: true,
        dismissible: true,
        position: {
          x: 'top',
          y: 'top'
        }
      });
    }

    $('#btn_copiar_link').on('click', function() {
      var link = $(this).data('link');
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(link).then(function() {
          notificar('success', 'Link de cadastro copiado!');
        }, function() {
          window.prompt('Copie o link de cadastro:', link);
        });
      } else {
        window.prompt('Copie o link de cadastro:', link);
      }
    });
  });
</script>