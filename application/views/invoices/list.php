<?php
$filtros = (array) $this->session->userdata('f_invoices');
$avancado = (array) $this->session->userdata($filtro_avancado);

$busca = isset($filtros['keyword']) ? (string) $filtros['keyword'] : '';

// Os chips mostram só o que está ESCONDIDO no offcanvas. A busca não vira chip:
// o campo está à vista e preenchido, e repetir viraria ruído.
$chips = [];

$situacaoAtual = isset($avancado['situation']) ? (string) $avancado['situation'] : '';
if ($situacaoAtual !== '' && isset($situations[$situacaoAtual])) {
  $chips[] = 'Situação: ' . $situations[$situacaoAtual];
}

$origemAtual = isset($avancado['origin']) ? (string) $avancado['origin'] : '';
if ($origemAtual !== '' && isset($origins[$origemAtual])) {
  $chips[] = 'Tipo: ' . $origins[$origemAtual];
}

$de = isset($avancado['vencimento_de']) ? (string) $avancado['vencimento_de'] : '';
$ate = isset($avancado['vencimento_ate']) ? (string) $avancado['vencimento_ate'] : '';
if ($de !== '') $chips[] = 'Vence a partir de ' . date('d/m/Y', strtotime($de));
if ($ate !== '') $chips[] = 'Vence até ' . date('d/m/Y', strtotime($ate));

$temFiltro = ($busca !== '' || !empty($chips));

$badgeSituacao = [
  'paga' => 'bg-success',
  'vencida' => 'bg-danger',
  'a_vencer' => 'bg-primary',
  'cancelada' => 'bg-secondary',
];
?>
<div class="row mb-2 mb-xl-3">
  <div class="col-auto d-none d-sm-block">
    <h3><strong>Faturas</strong></h3>
  </div>
</div>

<div class="row">
  <div class="col-12">
    <div class="card">
      <div class="card-body">

        <div class="row mb-3">
          <div class="col-12 col-lg-9">
            <form method="POST" action="<?php echo base_url('faturas/post_filtrar'); ?>">
              <div class="input-group">
                <input type="text" class="form-control" name="f_invoices[keyword]" placeholder="Buscar por cliente, documento ou descrição" value="<?php echo htmlspecialchars($busca, ENT_QUOTES, 'UTF-8'); ?>">
                <button class="btn btn-primary" type="submit"><i class="mdi mdi-magnify"></i> BUSCAR</button>
              </div>
            </form>
          </div>
          <div class="col-12 col-lg-3 text-lg-end mt-2 mt-lg-0">
            <button class="btn btn-outline-primary" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvas_filtros_faturas">
              <i class="mdi mdi-filter-variant"></i> FILTROS
              <?php if (!empty($chips)) { ?><span class="badge bg-primary"><?php echo count($chips); ?></span><?php } ?>
            </button>
            <?php if ($temFiltro) { ?>
              <button class="btn btn-outline-secondary" type="submit" form="form_filtros_faturas" name="acao" value="limpar">
                <i class="mdi mdi-close"></i> LIMPAR
              </button>
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

        <?php if (!empty($results)) { ?>
          <div class="row mb-3">
            <div class="col-12 col-md-4">
              <div class="card bg-light mb-0">
                <div class="card-body py-2">
                  <small class="text-muted">Faturas no filtro</small>
                  <h4 class="mb-0"><?php echo (int) $totais['quantidade']; ?></h4>
                </div>
              </div>
            </div>
            <div class="col-12 col-md-4">
              <div class="card bg-light mb-0">
                <div class="card-body py-2">
                  <small class="text-muted">Valor total</small>
                  <h4 class="mb-0">R$ <?php echo reais($totais['total']); ?></h4>
                </div>
              </div>
            </div>
            <div class="col-12 col-md-4">
              <div class="card bg-light mb-0">
                <div class="card-body py-2">
                  <small class="text-muted">Vencido em aberto</small>
                  <h4 class="mb-0 <?php echo $totais['vencido'] > 0 ? 'text-danger' : ''; ?>">R$ <?php echo reais($totais['vencido']); ?></h4>
                </div>
              </div>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-striped table-bordered table-hover">
              <thead>
                <tr>
                  <th>Cliente</th>
                  <th class="text-center">Competência</th>
                  <th class="text-center">Tipo</th>
                  <th class="text-center">Parcela</th>
                  <th class="text-center">Vencimento</th>
                  <th class="text-end">Valor</th>
                  <th class="text-center">Situação</th>
                  <th class="text-center">Registro</th>
                  <th class="text-center">Boleto</th>
                  <th>Descrição</th>
                  <th class="text-center">Ações</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($results as $fatura) {
                  $sit = (string) $fatura->situation;
                ?>
                  <tr>
                    <td>
                      <a href="<?php echo base_url('clientes/info?id=' . (int) $fatura->id_customer); ?>">
                        <?php echo htmlspecialchars((string) $fatura->customer_name, ENT_QUOTES, 'UTF-8'); ?>
                      </a><br />
                      <small class="text-muted"><?php echo cnpj((string) $fatura->customer_document); ?></small>
                    </td>
                    <td class="text-center"><?php echo date('m/Y', strtotime($fatura->competence)); ?></td>
                    <?php // Recorrência é a esmagadora maioria das linhas — como selo, ela
                          // viraria ruído em toda a tabela e a avulsa deixaria de saltar.
                          // Mesma regra do `origin` no histórico do contrato: só vira selo o
                          // que foge do normal. O título traz a descrição da cobrança, que é
                          // o que explica de onde a linha veio. ?>
                    <td class="text-center">
                      <?php if ((int) $fatura->id_charge > 0) { ?>
                        <span class="badge bg-warning text-dark" title="<?php echo htmlspecialchars((string) $fatura->charge_description, ENT_QUOTES, 'UTF-8'); ?>">Avulsa</span>
                      <?php } else { ?>
                        <small class="text-muted">Recorrência</small>
                      <?php } ?>
                    </td>
                    <td class="text-center">
                      <?php
                      // "1/1" em toda linha de contrato mensal seria ruído que
                      // esconde as poucas linhas de fato parceladas.
                      $total = (int) $fatura->installments_total;
                      echo $total > 1
                        ? (int) $fatura->installment_number . '/' . $total
                        : '<span class="text-muted">—</span>';
                      ?>
                    </td>
                    <td class="text-center"><?php echo date('d/m/Y', strtotime($fatura->due_date)); ?></td>
                    <td class="text-end">R$ <?php echo reais($fatura->value); ?></td>
                    <td class="text-center">
                      <span class="badge <?php echo isset($badgeSituacao[$sit]) ? $badgeSituacao[$sit] : 'bg-secondary'; ?>">
                        <?php echo isset($situations[$sit]) ? $situations[$sit] : $sit; ?>
                      </span>
                    </td>

                    <?php
                    // `registration` é DERIVADO na crm_invoices_v (migration
                    // 035): o mesmo limiar serve ao badge, à fila e a um filtro
                    // futuro. Recalcular aqui abriria espaço para a tela dizer
                    // "registrada" enquanto a fila ainda a procura.
                    $registro = (string) $fatura->registration;
                    $pronta = ($registro === 'registrada');
                    $aberta = ((string) $fatura->status === 'aberta');

                    $badgeRegistro = [
                      'sem_psp' => 'bg-light text-dark border',
                      'nao_registrada' => 'bg-secondary',
                      // "registrando" é estado NORMAL da emissão assíncrona,
                      // não falha — daí azul, e não amarelo de alerta.
                      'registrando' => 'bg-info text-dark',
                      'registrada' => 'bg-success',
                    ];
                    ?>
                    <td class="text-center text-nowrap">
                      <span class="badge <?php echo isset($badgeRegistro[$registro]) ? $badgeRegistro[$registro] : 'bg-secondary'; ?>">
                        <?php echo isset($registration_labels[$registro]) ? $registration_labels[$registro] : $registro; ?>
                      </span>
                      <?php if (trim((string) $fatura->psp) !== '') { ?>
                        <br /><small class="text-muted"><?php echo htmlspecialchars(isset($psp_rotulos[(string) $fatura->psp]) ? $psp_rotulos[(string) $fatura->psp] : (string) $fatura->psp, ENT_QUOTES, 'UTF-8'); ?></small>
                      <?php } ?>
                      <?php
                      // A ação só aparece onde há o que resolver. Cobrança
                      // REGISTRADA não oferece troca: o boleto está de pé e o
                      // cliente pode já tê-lo recebido — trocar ali significa
                      // cancelar no banco e emitir outro, que é operação de
                      // exceção e não merece um atalho a um clique no meio da
                      // listagem. Quando for preciso mesmo, o caminho é
                      // cancelar a fatura e gerar de novo.
                      //
                      // `registrando` mostra só ATUALIZAR: a cobrança existe,
                      // falta o boleto (emissão assíncrona) — trocar de
                      // provedor aqui cancelaria uma cobrança boa por
                      // impaciência.
                      $acaoRegistro = '';
                      if ($aberta && !empty($psp_disponiveis)) {
                        if ($registro === 'nao_registrada' || $registro === 'sem_psp') {
                          $acaoRegistro = 'registrar / trocar';
                        } elseif ($registro === 'registrando') {
                          $acaoRegistro = 'atualizar';
                        }
                      }
                      ?>
                      <?php if ($acaoRegistro !== '') { ?>
                        <br />
                        <button type="button" class="btn btn-sm btn-link p-0 btn-trocar-psp"
                          data-id="<?php echo (int) $fatura->id; ?>"
                          data-psp="<?php echo htmlspecialchars((string) $fatura->psp, ENT_QUOTES, 'UTF-8'); ?>"
                          data-cobranca="<?php echo trim((string) $fatura->psp_charge_id) !== '' ? '1' : '0'; ?>">
                          <?php echo $acaoRegistro; ?>
                        </button>
                      <?php } ?>
                    </td>
                    <td class="text-center text-nowrap">
                      <?php // O PDF só existe depois de a cobrança estar registrada. Em
                            // "registrando" o banco ainda não gerou o arquivo, e o botão
                            // levaria a um erro que o próprio estado já explica. ?>
                      <?php if ($pronta) { ?>
                        <button type="button" class="btn btn-sm btn-outline-danger btn-boleto" data-id="<?php echo (int) $fatura->id; ?>" title="Abrir o boleto">
                          <i class="mdi mdi-file-pdf-box"></i>
                        </button>
                      <?php } else { ?>
                        <span class="text-muted">—</span>
                      <?php } ?>
                    </td>
                    <td>
                      <small><?php echo htmlspecialchars((string) $fatura->description, ENT_QUOTES, 'UTF-8'); ?></small>
                    </td>
                    <td class="text-center text-nowrap">
                      <?php // Só fatura aberta e já com provedor tem o que resolver no banco. ?>
                      <?php if ($registro === 'nao_registrada' || $registro === 'registrando') { ?>
                        <?php if ($aberta) { ?>
                          <button type="button" class="btn btn-sm btn-outline-primary btn-cobranca" data-id="<?php echo (int) $fatura->id; ?>" title="<?php echo $registro === 'registrando' ? 'Buscar o boleto no banco' : 'Registrar a cobrança no banco'; ?>">
                            <i class="mdi mdi-cash-sync"></i>
                          </button>
                        <?php } ?>
                      <?php } ?>
                      <?php // ESCONDIDOS, não removidos:
                            //
                            // - o atalho para o contrato: a coluna Cliente já
                            //   leva ao cadastro, e daqui a navegação de fato
                            //   usada é a inversa (do contrato para as faturas,
                            //   pela aba);
                            // - a BAIXA MANUAL: o pagamento passa a ser
                            //   reconhecido sozinho pelo webhook (etapa C) e
                            //   pela conciliação (etapa D). Um botão "marcar
                            //   como paga" ao lado disso convida a criar uma
                            //   segunda verdade sobre o mesmo pagamento — o
                            //   sistema diria "paga" sem que dinheiro nenhum
                            //   tenha entrado.
                            //
                            // O DESFAZER continua visível: enquanto a baixa for
                            // automática, é o único caminho para corrigir uma
                            // conciliação errada.
                      ?>
                      <?php /*
                      <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('contratos/info?id=' . (int) $fatura->id_contract); ?>" title="Abrir o contrato">
                        <i class="mdi mdi-file-document-outline"></i>
                      </a>
                      */ ?>
                      <?php if ((string) $fatura->status === 'aberta') { ?>
                        <?php /*
                        <button type="button" class="btn btn-sm btn-outline-success btn-status" data-id="<?php echo (int) $fatura->id; ?>" data-acao="pagar" title="Marcar como paga">
                          <i class="mdi mdi-check"></i>
                        </button>
                        */ ?>
                        <button type="button" class="btn btn-sm btn-outline-danger btn-status" data-id="<?php echo (int) $fatura->id; ?>" data-acao="cancelar" title="Cancelar">
                          <i class="mdi mdi-close"></i>
                        </button>
                      <?php } elseif ((string) $fatura->status === 'paga') { ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-status" data-id="<?php echo (int) $fatura->id; ?>" data-acao="reabrir" title="Desfazer a baixa">
                          <i class="mdi mdi-undo"></i>
                        </button>
                      <?php } ?>
                    </td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>

          <div class="clearfix text-center">
            <?php echo $this->pagination->create_links(); ?>
          </div>
        <?php } else { ?>
          <div class="alert alert-secondary" role="alert">
            <div class="alert-message">
              <?php if ($temFiltro) { ?>
                Nenhuma fatura encontrada com os filtros aplicados.
              <?php } else { ?>
                Nenhuma fatura gerada ainda, as faturas nascem dos contratos com faturamento pelo CDW Finance.
              <?php } ?>
            </div>
          </div>
        <?php } ?>

      </div>
    </div>
  </div>
</div>

<!-- Offcanvas de filtros: selects sem select2 de propósito — o dropdown dele é
     anexado ao body e briga com o empilhamento do offcanvas. -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvas_filtros_faturas">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title">Filtros</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
  </div>
  <form method="POST" id="form_filtros_faturas" action="<?php echo base_url('faturas/post_filtrar'); ?>">
    <div class="offcanvas-body">
      <div class="mb-3">
        <label class="form-label">Situação</label>
        <select class="form-select" name="<?php echo $filtro_avancado; ?>[situation]">
          <option value="">Todas</option>
          <?php foreach ($situations as $slug => $rotulo) { ?>
            <option value="<?php echo $slug; ?>" <?php if ($situacaoAtual === $slug) echo 'selected=""'; ?>><?php echo $rotulo; ?></option>
          <?php } ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Tipo</label>
        <select class="form-select" name="<?php echo $filtro_avancado; ?>[origin]">
          <option value="">Todos</option>
          <?php foreach ($origins as $slug => $rotulo) { ?>
            <option value="<?php echo $slug; ?>" <?php if ($origemAtual === $slug) echo 'selected=""'; ?>><?php echo $rotulo; ?></option>
          <?php } ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Vencimento a partir de</label>
        <input type="text" class="form-control" data-mask="00/00/0000" name="<?php echo $filtro_avancado; ?>[vencimento_de]" value="<?php echo $de !== '' ? date('d/m/Y', strtotime($de)) : ''; ?>">
      </div>
      <div class="mb-3">
        <label class="form-label">Vencimento até</label>
        <input type="text" class="form-control" data-mask="00/00/0000" name="<?php echo $filtro_avancado; ?>[vencimento_ate]" value="<?php echo $ate !== '' ? date('d/m/Y', strtotime($ate)) : ''; ?>">
      </div>
    </div>
    <div class="offcanvas-header border-top">
      <button type="submit" class="btn btn-primary w-100 me-2"><i class="mdi mdi-filter"></i> FILTRAR</button>
      <button type="submit" class="btn btn-outline-secondary w-100" name="acao" value="limpar">LIMPAR FILTROS</button>
    </div>
  </form>
</div>

<form method="POST" id="form_status_fatura" action="<?php echo base_url('faturas/post_status'); ?>">
  <input type="hidden" name="id" id="status_fatura_id">
  <input type="hidden" name="acao" id="status_fatura_acao">
</form>

<?php // Modal do provedor. Só é renderizado quando há provedor ativo — sem
      // credencial cadastrada não há troca possível, e um modal com select
      // vazio só produziria um erro no clique. ?>
<?php if (!empty($psp_disponiveis)) { ?>
<div class="modal fade" id="modal_trocar_psp" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Provedor da cobrança</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label" for="trocar_psp_select">Registrar esta fatura em</label>
          <select class="form-select" id="trocar_psp_select">
            <?php foreach ($psp_disponiveis as $pspSlug => $pspNome) { ?>
              <option value="<?php echo htmlspecialchars($pspSlug, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($pspNome, ENT_QUOTES, 'UTF-8'); ?></option>
            <?php } ?>
          </select>
          <small class="text-muted">
            Manter o mesmo provedor e confirmar <strong>força o registro</strong> — útil quando a emissão falhou.
          </small>
        </div>
        <?php // O aviso aparece só quando há cobrança viva: é o único caso em
              // que confirmar dispara um cancelamento no banco anterior. ?>
        <div class="alert alert-warning d-none" id="trocar_psp_aviso" role="alert">
          <div class="alert-message">
            Esta fatura <strong>já tem cobrança registrada</strong>. Ao trocar de provedor, ela é
            <strong>cancelada no provedor atual</strong> antes de a nova ser emitida — se o cancelamento
            falhar, a troca é abortada, para não deixar dois boletos da mesma fatura em aberto.
          </div>
        </div>
        <p class="text-muted mb-0">
          O contrato não muda: a alteração vale só para esta fatura.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">VOLTAR</button>
        <button type="button" class="btn btn-primary" id="btn_confirmar_troca_psp">CONFIRMAR</button>
      </div>
    </div>
  </div>
</div>
<?php } ?>

<?php // Visualizador do boleto. O iframe aponta para o endpoint de streaming,
      // que serve do banco — e NÃO para uma data: URL com o base64 embutido:
      // navegadores bloqueiam navegação para data: em PDF, e o HTML da página
      // carregaria ~120 KB por boleto aberto. ?>
<div class="modal fade" id="modal_boleto" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Boleto <span class="text-muted" id="boleto_titulo_fatura"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body p-0">
        <?php // Altura fixa: sem ela o iframe nasce com 150px e o boleto fica
              // ilegível dentro de um modal grande e vazio. ?>
        <iframe id="boleto_visualizador" src="" title="Boleto" style="width: 100%; height: 75vh; border: 0;"></iframe>
      </div>
      <div class="modal-footer">
        <a href="#" class="btn btn-outline-secondary" id="btn_boleto_nova_aba" target="_blank" rel="noopener">
          <i class="mdi mdi-open-in-new"></i> ABRIR EM NOVA ABA
        </a>
        <a href="#" class="btn btn-primary" id="btn_boleto_baixar">
          <i class="mdi mdi-download"></i> BAIXAR
        </a>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">FECHAR</button>
      </div>
    </div>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    var textos = {
      pagar: {
        titulo: 'Marcar como paga?',
        html: 'A fatura passa a constar como quitada. A baixa automática pelo Bom Controle ainda não existe — esta é a marcação manual.',
        botao: 'Marcar como paga'
      },
      cancelar: {
        titulo: 'Cancelar a fatura?',
        html: 'O <strong>boleto é cancelado no banco</strong> primeiro — se isso falhar, a fatura' +
          ' continua em aberto, para não deixar uma cobrança de pé sem fatura.<br><br>' +
          'Cancelada, ela deixa de contar como valor a receber e <strong>não pode ser reaberta</strong>:' +
          ' permanece no histórico do contrato, e a competência dela não é gerada de novo.',
        botao: 'Cancelar fatura'
      },
      reabrir: {
        titulo: 'Desfazer a baixa?',
        html: 'A fatura volta a constar como em aberto.',
        botao: 'Desfazer'
      }
    };

    $('.btn-status').on('click', function() {
      var acao = $(this).data('acao');
      var id = $(this).data('id');
      var texto = textos[acao];
      if (!texto) return;

      Swal.fire({
        title: texto.titulo,
        html: texto.html,
        icon: 'question',
        showCancelButton: true,
        cancelButtonText: 'Voltar',
        confirmButtonText: texto.botao
      }).then(function(result) {
        if (!result.value) return;

        // Só o CANCELAR vai ao banco (cancela a cobrança antes de fechar a
        // fatura) e pode levar segundos. As outras transições são locais e
        // instantâneas — um modal piscando ali seria ruído.
        //
        // Não há hide: a página redireciona, e o modal morre com ela.
        if (acao === 'cancelar') {
          $('#modal_loading').modal('show');
        }

        $('#status_fatura_id').val(id);
        $('#status_fatura_acao').val(acao);
        $('#form_status_fatura').submit();
      });
    });
  });
    // --- Cobrança no PSP -------------------------------------------
    // Um botão só: registrar e consultar são a mesma pergunta para quem clica
    // ("resolve essa cobrança"), e qual das duas roda é decidido pelo estado
    // da fatura no servidor, nunca pela tela.
    $('.btn-cobranca').on('click', function() {
      var $botao = $(this);
      var id = $botao.data('id');

      $botao.prop('disabled', true).find('i').removeClass('mdi-cash-sync').addClass('mdi-loading mdi-spin');

      $.post('<?php echo base_url('faturas/json_postcobranca'); ?>', {
          id: id
        }, null, 'json')
        .done(function(retorno) {
          if (typeof handleRedirect === 'function' && handleRedirect(retorno)) return;

          if (retorno && retorno.success) {
            // Recarrega só quando há o que mostrar: a emissão é assíncrona, e
            // recarregar para o mesmo "processando" faria o usuário achar que
            // o botão não fez nada.
            if (retorno.data && retorno.data.pronta === false) {
              Swal.fire('Ainda processando', retorno.message, 'info');
              return;
            }
            // O aviso NÃO sai daqui: a linha seguinte recarrega a tela para o
            // boleto e o PIX aparecerem, e um toast não sobrevive ao reload.
            // Quem avisa é o flashdata gravado no servidor.
            window.location.reload();
            return;
          }

          Swal.fire('Não foi possível', (retorno && retorno.message) ? retorno.message : 'Falha ao falar com o provedor.', 'error');
        })
        .fail(function(xhr) {
          console.log(xhr.responseText);
          Swal.fire('Erro', 'Falha de comunicação ao registrar a cobrança.', 'error');
        })
        .always(function() {
          $botao.prop('disabled', false).find('i').removeClass('mdi-loading mdi-spin').addClass('mdi-cash-sync');
        });
    });


    // --- Troca de provedor / forçar registro -----------------------
    // Registrar e trocar são a MESMA confirmação: quem decide o que fazer é a
    // regra no servidor, pelo estado da fatura. Duas ações na tela para o
    // mesmo botão obrigariam o usuário a saber em que estado a fatura está.
    (function() {
      var $modal = $('#modal_trocar_psp');
      if (!$modal.length) return;

      var faturaAlvo = 0;

      $('.btn-trocar-psp').on('click', function() {
        var $b = $(this);
        faturaAlvo = $b.data('id');

        var pspAtual = String($b.data('psp') || '');
        if (pspAtual !== '') $('#trocar_psp_select').val(pspAtual);

        // O aviso do cancelamento só vale quando existe cobrança viva.
        $('#trocar_psp_aviso').toggleClass('d-none', String($b.data('cobranca')) !== '1');

        bootstrap.Modal.getOrCreateInstance($modal[0]).show();
      });

      $('#btn_confirmar_troca_psp').on('click', function() {
        var $botao = $(this);
        var psp = $('#trocar_psp_select').val();
        if (!faturaAlvo || !psp) return;

        $botao.prop('disabled', true).text('PROCESSANDO...');

        $.post('<?php echo base_url('faturas/json_posttrocarpsp'); ?>', {
            id: faturaAlvo,
            psp: psp
          }, null, 'json')
          .done(function(retorno) {
            if (typeof handleRedirect === 'function' && handleRedirect(retorno)) return;

            if (retorno && retorno.success) {
              // Pronta: recarrega e o flashdata do servidor avisa. Ainda não
              // pronta é o estado normal da emissão assíncrona — aí o modal
              // fica aberto com a explicação, para o usuário não achar que
              // precisa repetir.
              if (retorno.data && retorno.data.pronta) {
                window.location.reload();
                return;
              }
              Swal.fire('Registrado', retorno.message, 'info');
              bootstrap.Modal.getOrCreateInstance($modal[0]).hide();
              return;
            }

            Swal.fire('Não foi possível', (retorno && retorno.message) ? retorno.message : 'Falha ao falar com o provedor.', 'error');
          })
          .fail(function(xhr) {
            console.log(xhr.responseText);
            Swal.fire('Erro', 'Falha de comunicação ao trocar o provedor.', 'error');
          })
          .always(function() {
            $botao.prop('disabled', false).text('CONFIRMAR');
          });
      });
    })();

    // --- Boleto (PDF) ------------------------------------------------
    // Delegado no document porque nas abas a tabela é redesenhada a cada
    // página: um bind direto morreria no primeiro ANTERIOR/PRÓXIMA.
    $(document).on('click', '.btn-boleto', function() {
      var $botao = $(this);
      var id = $botao.data('id');
      var $icone = $botao.find('i');
      var classeOriginal = $icone.attr('class');

      $botao.prop('disabled', true);
      $icone.attr('class', 'mdi mdi-loading mdi-spin');

      // Garante o arquivo ANTES de abrir o modal. Se a busca falhasse dentro
      // do iframe, o usuário veria a página de erro do navegador no lugar do
      // boleto, sem explicação — aqui a falha vira mensagem.
      $.post('<?php echo base_url('faturas/json_postboleto'); ?>', {
          id: id
        }, null, 'json')
        .done(function(retorno) {
          if (typeof sessaoExpirou === 'function' && sessaoExpirou(retorno)) return;
          if (typeof handleRedirect === 'function' && handleRedirect(retorno)) return;

          if (!retorno || !retorno.success) {
            Swal.fire('Boleto indisponível', (retorno && retorno.message) ? retorno.message : 'Não foi possível obter o boleto.', 'error');
            return;
          }

          var base = '<?php echo base_url('faturas/boleto/'); ?>' + encodeURIComponent(id);

          $('#boleto_titulo_fatura').text('#' + id);
          $('#boleto_visualizador').attr('src', base);
          $('#btn_boleto_nova_aba').attr('href', base);
          $('#btn_boleto_baixar').attr('href', base + '/download');

          bootstrap.Modal.getOrCreateInstance(document.getElementById('modal_boleto')).show();
        })
        .fail(function(xhr) {
          console.log(xhr.responseText);
          Swal.fire('Erro', 'Falha de comunicação ao buscar o boleto.', 'error');
        })
        .always(function() {
          $botao.prop('disabled', false);
          $icone.attr('class', classeOriginal);
        });
    });

    // Zera o iframe ao fechar: sem isso o PDF anterior continua carregado em
    // memória e aparece por um instante na próxima abertura, antes do novo.
    $('#modal_boleto').on('hidden.bs.modal', function() {
      $('#boleto_visualizador').attr('src', '');
    });
</script>