<?php
// Mapas, e não ternário: com a chegada de 'encerrado' o antigo
// `$vigente ? 'Vigente' : 'Suspenso'` rotulava contrato encerrado como
// suspenso. Cinza para encerrado (e não vermelho) porque é estado terminal
// normal do negócio, não alerta — mesma lógica do cinza de "sem contrato" no
// card de domínios do dashboard.
$statusBadges = ['vigente' => 'bg-success', 'suspenso' => 'bg-warning', 'encerrado' => 'bg-secondary'];
$statusRotulos = ['vigente' => 'Vigente', 'suspenso' => 'Suspenso', 'encerrado' => 'Encerrado'];

$vigente = ($result->status === 'vigente');
$encerrado = ($result->status === 'encerrado');
$badgeStatus = isset($statusBadges[$result->status]) ? $statusBadges[$result->status] : 'bg-dark';
$rotuloStatus = isset($statusRotulos[$result->status]) ? $statusRotulos[$result->status] : $result->status;
$motivoEncerramento = (!empty($result->ended_reason) && isset($end_reasons[$result->ended_reason]))
  ? $end_reasons[$result->ended_reason]
  : $result->ended_reason;
$cicloRotulo = isset($cycles[$result->cycle]) ? $cycles[$result->cycle] : $result->cycle;

// Gb sem zeros à direita ("8", "0,8"), para exibição.
$gb = function ($valor, $decimal = ',') {
  $texto = number_format((float) $valor, 2, $decimal, '');
  return rtrim(rtrim($texto, '0'), $decimal);
};
$temEspaco = ((float) $result->space_gb) > 0;
?>
<div class="row mb-2 mb-xl-2">
  <div class="col-auto text-start">
    <a class="text-muted" href="<?php echo base_url('clientes/info?id=' . (int) $result->id_customer); ?>"><i class="fa fa-arrow-left"></i> Voltar para cliente</a>
    <h1 class="h3 mb-0 mt-1">
      Contrato #<?php echo (int) $result->id; ?>
      <span class="badge <?php echo $badgeStatus; ?> align-middle fs-6"><?php echo $rotuloStatus; ?></span>
    </h1>
    <p class="text-muted mb-2"><?php echo htmlspecialchars(!empty($result->customer_byname) ? $result->customer_byname : $result->customer_name, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php if ($encerrado) { ?>
      <p class="text-muted mb-2">
        <small>
          <i class="mdi mdi-close-circle-outline"></i>
          Encerrado em <strong><?php echo data($result->ended); ?></strong>
          <?php if (!empty($result->ended_user)) { ?>por <?php echo htmlspecialchars((string) $result->ended_user, ENT_QUOTES, 'UTF-8'); ?><?php } ?>
          <?php if (!empty($motivoEncerramento)) { ?>&middot; <?php echo htmlspecialchars((string) $motivoEncerramento, ENT_QUOTES, 'UTF-8'); ?><?php } ?>
        </small>
        <?php if (!empty($result->ended_comments)) { ?>
          <small class="d-block"><?php echo htmlspecialchars((string) $result->ended_comments, ENT_QUOTES, 'UTF-8'); ?></small>
        <?php } ?>
      </p>
    <?php } ?>
  </div>
  <div class="col-auto ms-auto text-end mt-n1">
    <?php if ($encerrado) { ?>
      <form method="POST" id="form_reabrir" action="<?php echo base_url('contratos/post_reabrir'); ?>" class="d-inline">
        <input type="hidden" name="id" value="<?php echo (int) $result->id; ?>">
        <button type="button" class="btn btn-outline-success" id="btn_reabrir"><i class="mdi mdi-restore"></i> REABRIR CONTRATO</button>
      </form>
      <small class="text-muted d-block mt-2">Contrato encerrado. Para suspender, editar ou excluir, reabra o contrato antes.</small>
    <?php } else { ?>
      <form method="POST" id="form_status" action="<?php echo base_url('contratos/post_status'); ?>" class="d-inline">
        <input type="hidden" name="id" value="<?php echo (int) $result->id; ?>">
        <input type="hidden" name="acao" id="campo_acao_status" value="<?php echo $vigente ? 'suspender' : 'reativar'; ?>">
        <?php if ($vigente) { ?>
          <button type="button" class="btn btn-warning" id="btn_status" data-acao="suspender"><i class="mdi mdi-pause-circle-outline"></i> SUSPENDER</button>
        <?php } else { ?>
          <button type="button" class="btn btn-success" id="btn_status" data-acao="reativar"><i class="fa fa-check"></i> REATIVAR</button>
        <?php } ?>
      </form>
      <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modal_encerrar"><i class="mdi mdi-close-circle-outline"></i> ENCERRAR CONTRATO</button>
      <form method="POST" id="form_excluir_contrato" action="<?php echo base_url('contratos/post_excluir'); ?>" class="d-inline">
        <input type="hidden" name="id" value="<?php echo (int) $result->id; ?>">
        <?php // Preenchido pelo Swal da confirmação: o contrato deixa de existir,
        // e o motivo digitado ali é o que sobra no histórico para explicar por quê. ?>
        <input type="hidden" name="comments" id="campo_motivo_exclusao" value="">
        <button type="button" class="btn btn-outline-danger" id="btn_excluir_contrato"><i class="fa fa-trash"></i> EXCLUIR CONTRATO</button>
      </form>
    <?php } ?>
  </div>
</div>

<div class="row">
  <div class="col-12 col-md-4 d-flex">
    <div class="card flex-fill">
      <div class="card-body py-3">
        <span class="text-muted">Valor</span>
        <h2 class="mb-0">R$ <?php echo reais($result->value); ?></h2>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-4 d-flex">
    <div class="card flex-fill">
      <div class="card-body py-3">
        <span class="text-muted">Ciclo</span>
        <h2 class="mb-0"><?php echo $cicloRotulo; ?></h2>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-4 d-flex">
    <div class="card flex-fill">
      <div class="card-body py-3">
        <span class="text-muted">Espaço</span>
        <h2 class="mb-0"><?php echo $gb($result->space_gb); ?> Gb</h2>
      </div>
    </div>
  </div>
</div>

<?php
$percentUso = $temEspaco ? min(100, ((float) $uso_gb / (float) $result->space_gb) * 100) : 0;
$corBarra = 'bg-success';
if ($percentUso >= 90) $corBarra = 'bg-danger';
elseif ($percentUso >= 70) $corBarra = 'bg-warning';
?>
<div class="card flex-fill">
  <div class="card-body py-3">
    <div class="row align-items-center">
      <div class="col">
        <span class="text-muted">Uso do cliente (domínios sincronizados)</span>
        <h5 class="mb-2"><?php echo number_format((float) $uso_gb, 2, ',', '.'); ?> Gb de <?php echo $gb($result->space_gb); ?> Gb contratados</h5>
      </div>
      <?php if (!$temEspaco) { ?>
        <div class="col-auto text-end">
          <span class="badge bg-light text-dark border">Sem espaço contratado definido</span>
        </div>
      <?php } ?>
    </div>
    <div class="progress mb-2" style="height: 8px;">
      <div class="progress-bar <?php echo $corBarra; ?>" role="progressbar" style="width: <?php echo (int) $percentUso; ?>%;" aria-valuenow="<?php echo (int) $percentUso; ?>" aria-valuemin="0" aria-valuemax="100"></div>
    </div>
    <span class="text-muted"><small>Somando <?php echo (int) $dominios_com_vinculo; ?> de <?php echo count($domains); ?> domínio(s) com correspondência nos servidores. Domínios sem correspondência não entram na soma.</small></span>
  </div>
</div>

<ul class="nav nav-pills" role="tablist">
  <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab_visao_geral" role="tab">Visão geral</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_extrato" role="tab">Extrato Bom Controle</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_faturas" role="tab">Faturas
      <?php // Contagem no rótulo: a aba é lazy, e sem o número não dá para saber
      // se há faturas sem abri-la. Zero NÃO vira badge — um "0" em toda
      // aba é ruído, e a ausência já diz o mesmo. 
      ?>
      <?php if (!empty($faturas_count)) { ?><span class="badge bg-secondary ms-1"><?php echo (int) $faturas_count; ?></span><?php } ?>
    </a></li>
  <li class="nav-item">
    <a class="nav-link" data-bs-toggle="tab" href="#tab_historicos" role="tab">
      Históricos<?php // Mesma regra do badge de Faturas: zero não vira selo.
                  if (!empty($history)) { ?><span class="badge bg-secondary ms-1"><?php echo count($history); ?></span><?php } ?>
    </a>
  </li>
</ul>

<div class="tab-content pt-3">
  <div class="tab-pane fade show active" id="tab_visao_geral" role="tabpanel">

    <div class="card flex-fill">
      <div class="card-body py-3">
        <h5 class="card-title mb-1">Dados gerais</h5>
        <p class="text-muted mb-3 lh-1"><small>Edite as informações principais do contrato.</small></p>

        <?php if ($encerrado) { ?>
          <div class="alert alert-secondary" role="alert">
            <div class="alert-message">
              Contrato encerrado: os dados gerais ficam somente leitura. O valor alimenta a barra de saídas do dashboard no mês do encerramento, e alterá-lo agora reescreveria um mês já fechado. Para editar, reabra o contrato.
            </div>
          </div>
        <?php } ?>

        <form method="POST" name="form" id="form_dados_gerais" action="<?php echo base_url('contratos/post_salvar'); ?>" enctype="multipart/form-data">
          <input type="hidden" name="id" value="<?php echo (int) $result->id; ?>">
          <?php // Campo desabilitado não é submetido — e o post_salvar recusa de qualquer forma.
          $roDados = $encerrado ? 'disabled' : ''; ?>
          <div class="row">
            <div class="col-12 col-md-3">
              <div class="form-group mb-3">
                <label class="form-label">* Data de criação</label>
                <input type="text" class="form-control" name="contract[created]" data-mask="00/00/0000" placeholder="dd/mm/aaaa" value="<?php echo !empty($result->created) ? date('d/m/Y', strtotime($result->created)) : ''; ?>" <?php echo $roDados; ?>>
              </div>
            </div>
            <div class="col-12 col-md-3">
              <div class="form-group mb-3">
                <label class="form-label">* Espaço contratado (Gb)</label>
                <input type="number" min="0" step="0.01" class="form-control" name="contract[space_gb]" value="<?php echo rtrim(rtrim(number_format((float) $result->space_gb, 2, '.', ''), '0'), '.'); ?>" <?php echo $roDados; ?>>
              </div>
            </div>
            <div class="col-12 col-md-3">
              <div class="form-group mb-3">
                <label class="form-label">* Valor (R$)</label>
                <input type="text" class="form-control moneymask" name="contract[value]" value="<?php echo reais($result->value); ?>" <?php echo $roDados; ?>>
              </div>
            </div>
            <div class="col-12 col-md-3">
              <div class="form-group mb-3">
                <label class="form-label">* Ciclo de pagamento</label>
                <select class="form-control" name="contract[cycle]" required <?php echo $roDados; ?>>
                  <?php foreach ($cycles as $alias => $rotulo) { ?>
                    <option <?php if ($result->cycle === $alias) echo 'selected=""'; ?> value="<?php echo $alias; ?>"><?php echo $rotulo; ?></option>
                  <?php } ?>
                </select>
              </div>
            </div>
            <div class="col-12">
              <div class="form-group mb-3">
                <label class="form-label">* Tipos de serviço <small class="text-muted">(marque um ou mais)</small></label>
                <div class="border rounded p-2" style="max-height: 180px; overflow-y: auto;">
                  <?php foreach ($service_types as $tipo) { ?>
                    <div class="form-check form-switch" style="float: left; padding-right: 10px;">
                      <input class="form-check-input js-tipo-servico" type="checkbox" role="switch" name="contract[service_types][]" value="<?php echo (int) $tipo->id; ?>" id="ct_tipo_<?php echo (int) $tipo->id; ?>" <?php if (in_array((int) $tipo->id, $selected_services, TRUE)) echo 'checked'; ?> <?php echo $roDados; ?>>
                      <label class="form-check-label" for="ct_tipo_<?php echo (int) $tipo->id; ?>"><?php echo htmlspecialchars($tipo->name, ENT_QUOTES, 'UTF-8'); ?></label>
                    </div>
                  <?php } ?>
                </div>
              </div>
            </div>
            <div class="col-12">
              <div class="form-group mb-3">
                <label class="form-label">Observações</label>
                <textarea class="form-control" name="contract[comments]" rows="3" maxlength="1000" <?php echo $roDados; ?>><?php echo htmlspecialchars((string) $result->comments, ENT_QUOTES, 'UTF-8'); ?></textarea>
              </div>
            </div>
          </div>
          <?php if (!$encerrado) { ?>
            <button type="submit" class="btn btn-primary"><i class="mdi mdi-content-save"></i> SALVAR DADOS GERAIS</button>
          <?php } ?>
        </form>
      </div>
    </div>

    <?php
    // ------------------------------------------------------------------
    // Faturamento
    // ------------------------------------------------------------------
    $faturaAqui = ((string) $result->billing_source === 'cdwfinance');
    $vinculadoErp = !empty($result->bomcontrole_contract_id);
    $temReajuste = ((string) $result->adjustment_index !== 'nenhum');
    $diaAtual = (int) $result->billing_day;
    ?>
    <?php if (!$encerrado) { ?>
      <div class="card flex-fill">
        <div class="card-body py-3">
          <div class="row">
            <div class="col">
              <h5 class="card-title mb-1">
                Faturamento
                <?php if ($faturaAqui) { ?>
                  <span class="badge bg-success">CDW Finance + NF Bom Controle</span>
                <?php } else { ?>
                  <span class="badge bg-secondary">Bom Controle</span>
                <?php } ?>
              </h5>
              <p class="text-muted mb-3 lh-1"><small>Define quem gera as cobranças deste contrato e como elas são emitidas.</small></p>
            </div>
            <?php // DUAS camadas, e as duas são necessárias:
                  //
                  //  - o `if` do PHP responde pelo estado SALVO: contrato do Bom
                  //    Controle nunca renderiza os botões;
                  //  - o `bloco-cdw` responde ao SELECT: trocar "Quem fatura" para o
                  //    ERP some com eles na hora, sem esperar o SALVAR. Sem isso os
                  //    botões continuavam à mostra prometendo uma ação que o servidor
                  //    ia recusar.
                  //
                  // A recusa no servidor continua existindo nos três (generateNow,
                  // Charge_model::lancar e json_postavisarreajuste): esconder botão
                  // não protege endpoint. ?>
            <?php if ($faturaAqui) { ?>
              <div class="col-auto text-end bloco-cdw">
                <button type="button" class="btn btn-outline-primary" id="btn_gerar_fatura"><i class="mdi mdi-file-plus-outline"></i> GERAR FATURA</button>
                <button type="button" class="btn btn-outline-primary" id="btn_lancar_cobranca" data-bs-toggle="modal" data-bs-target="#modal_cobranca"><i class="mdi mdi-cart-plus"></i> LANÇAR COBRANÇA</button>
                <?php if ($temReajuste && !empty($result->next_adjustment)) { ?>
                  <button type="button" class="btn btn-outline-secondary" id="btn_avisar_reajuste"><i class="mdi mdi-email-outline"></i> AVISAR REAJUSTE</button>
                <?php } ?>
              </div>
            <?php } ?>
          </div>

          <?php if (!$faturaAqui && $vinculadoErp) { ?>
            <div class="alert alert-warning" role="alert">
              <div class="alert-message">
                <strong>Atenção:</strong> este contrato está vinculado ao contrato <strong>#<?php echo (int) $result->bomcontrole_contract_id; ?></strong> do Bom Controle, que continua emitindo as cobranças por lá.
                Antes de passar o faturamento para o CDW Finance, <strong>encerre o contrato no painel do Bom Controle</strong> — se os dois ficarem ativos, o cliente será cobrado duas vezes pelo mesmo serviço.
              </div>
            </div>
          <?php } ?>

          <form method="POST" id="form_faturamento" action="<?php echo base_url('contratos/post_faturamento'); ?>">
            <input type="hidden" name="id" value="<?php echo (int) $result->id; ?>">
            <div class="row">
              <div class="col-12 col-md-3">
                <div class="form-group mb-3">
                  <label class="form-label">* Quem fatura</label>
                  <select class="form-control select2" name="billing[billing_source]" id="billing_source">
                    <option value="bomcontrole" <?php if (!$faturaAqui) echo 'selected=""'; ?>>Bom Controle (ERP)</option>
                    <option value="cdwfinance" <?php if ($faturaAqui) echo 'selected=""'; ?>>CDW Finance + NF Bom Controle</option>
                  </select>
                </div>
              </div>
              <div class="col-12 col-md-3 bloco-cdw">
                <div class="form-group mb-3">
                  <label class="form-label">* Banco</label>
                  <?php if (empty($psp_disponiveis)) { ?>
                    <select class="form-control" disabled>
                      <option>Nenhum provedor ativo</option>
                    </select>
                  <?php } else { ?>
                    <select class="form-control select2" name="billing[psp]" id="billing_psp">
                      <?php foreach ($psp_disponiveis as $pspSlug => $pspNome) { ?>
                        <option value="<?php echo htmlspecialchars($pspSlug, ENT_QUOTES, 'UTF-8'); ?>" <?php if ((string) $result->psp === $pspSlug) echo 'selected=""'; ?>><?php echo htmlspecialchars($pspNome, ENT_QUOTES, 'UTF-8'); ?></option>
                      <?php } ?>
                    </select>
                  <?php } ?>
                </div>
              </div>
              <div class="col-12 col-md-3 bloco-cdw">
                <div class="form-group mb-3">
                  <label class="form-label">* Dia do vencimento</label>
                  <input type="number" class="form-control" name="billing[billing_day]" id="billing_day" min="1" max="31" value="<?php echo $diaAtual > 0 ? $diaAtual : (int) $billing_day_sugerido; ?>">
                  <!-- <small class="form-text text-muted">Dia 31 vira o último dia nos meses curtos.</small> -->
                </div>
              </div>
              <div class="col-12 col-md-3 bloco-cdw">
                <div class="form-group mb-3">
                  <label class="form-label">* Competência inicial</label>
                  <input type="text" class="form-control" name="billing[next_competence]" id="next_competence" data-mask="00/00/0000" value="<?php echo !empty($result->next_competence) ? date('d/m/Y', strtotime($result->next_competence)) : date('d/m/Y', strtotime('first day of next month')); ?>">
                  <small class="form-text text-muted">Primeiro mês a faturar aqui.</small>
                </div>
              </div>
              <div class="col-12 col-md-3 bloco-cdw">
                <div class="form-group mb-3">
                  <label class="form-label">Parcelas</label>
                  <input type="number" class="form-control" name="billing[installments]" id="installments" min="1" max="<?php echo (int) $max_parcelas_ciclo; ?>" value="<?php echo max(1, (int) $result->installments); ?>" <?php if ((int) $max_parcelas_ciclo <= 1) echo 'readonly'; ?>>
                  <small class="form-text text-muted" id="hint_parcelas"></small>
                </div>
              </div>
              <div class="col-12 col-md-3 bloco-cdw">
                <div class="form-group mb-3">
                  <label class="form-label">Emitir NF-e</label>
                  <select class="form-control select2" name="billing[invoice_policy]" id="invoice_policy">
                    <?php foreach ($invoice_policies as $slug => $rotulo) { ?>
                      <option value="<?php echo $slug; ?>" <?php if ((string) $result->invoice_policy === $slug) echo 'selected=""'; ?>><?php echo $rotulo; ?></option>
                    <?php } ?>
                  </select>
                  <!-- <small class="form-text text-muted">O cadastro do cliente não trazia essa informação — confirme com o financeiro.</small> -->
                </div>
              </div>


              <?php // O separador e o título também são `bloco-cdw`: sem isso, com o
                    // faturamento no Bom Controle a tela mostrava o cabeçalho
                    // "Reajustes" seguido de espaço vazio, sugerindo campo que sumiu
                    // por bug. Reajuste é do motor daqui — no ERP quem reajusta é ele. ?>
              <div class="col-12 bloco-cdw">
                <hr>
                <h6 class="mb-1">Reajustes</h6>
              </div>
              <div class="col-12 col-md-3 bloco-cdw">
                <div class="form-group mb-3">
                  <label class="form-label">Reajuste anual</label>
                  <select class="form-control select2" name="billing[adjustment_index]" id="adjustment_index">
                    <?php foreach ($adjustment_indexes as $slug => $rotulo) { ?>
                      <option value="<?php echo $slug; ?>" <?php if ((string) $result->adjustment_index === $slug) echo 'selected=""'; ?>><?php echo $rotulo; ?></option>
                    <?php } ?>
                  </select>
                </div>
              </div>
              <div class="col-12 col-md-3 bloco-cdw bloco-reajuste">
                <div class="form-group mb-3">
                  <label class="form-label">* Próximo reajuste</label>
                  <input type="text" class="form-control" name="billing[next_adjustment]" id="next_adjustment" data-mask="00/00/0000" value="<?php echo !empty($result->next_adjustment) ? date('d/m/Y', strtotime($result->next_adjustment)) : date('d/m/Y', strtotime($proximo_aniversario)); ?>">
                  <!-- <small class="form-text text-muted">Próximo aniversário do contrato.</small> -->
                </div>
              </div>
            </div>

            <?php if (!$faturaAqui && $vinculadoErp) { ?>
              <div class="form-check mb-3 bloco-cdw">
                <input class="form-check-input" type="checkbox" name="billing[confirma_erp]" value="1" id="confirma_erp">
                <label class="form-check-label" for="confirma_erp">
                  Confirmo que o contrato <strong>#<?php echo (int) $result->bomcontrole_contract_id; ?></strong> já foi encerrado no Bom Controle.
                </label>
              </div>
            <?php } ?>

            <?php // Também `bloco-cdw`: os avisos de boleto, nota e reajuste saem das
                  // rotinas DAQUI (cron_enviar_faturas, cron_enviar_notas,
                  // cron_reajustar_contratos), e nenhuma delas olha contrato do Bom
                  // Controle. Deixar os campos à mostra prometia um aviso que não
                  // aconteceria.
                  //
                  // ESCONDER NÃO APAGA: os inputs continuam no formulário e são
                  // enviados normalmente, então o `post_faturamento` segue gravando o
                  // `notification_config` — que é o comportamento desejado desde a
                  // migration 033 ("quem avisa o cliente é pergunta independente de
                  // quem emite a cobrança, e apagar a lista ao virar a chave perderia
                  // cadastro sem avisar"). Ao voltar para o CDW Finance, a lista
                  // reaparece intacta. ?>
            <div class="bloco-cdw">
            <hr>
            <h6 class="mb-1">Notificações ao cliente</h6>
            <p class="text-muted mb-3">
              <small>Para quem avisar sobre este contrato: boleto emitido, nota fiscal, aviso de reajuste.</small>
            </p>

            <div class="row">
              <div class="col-12 col-lg-6">
                <label class="form-label">E-mails</label>
                <div id="repeater_emails">
                  <?php
                  // Ao menos uma linha para o repeater ter o que clonar — e para
                  // o campo não parecer ausente num contrato sem configuração.
                  $linhasEmail = !empty($notification_emails) ? $notification_emails : [['email' => '', 'type' => 'destinatario']];
                  foreach ($linhasEmail as $i => $linha) {
                  ?>
                    <div class="card border mb-2 linha-email">
                      <div class="card-body p-2">
                        <div class="row align-items-end g-2">
                          <div class="col-12 col-sm">
                            <label class="form-label mb-1"><small>E-mail</small></label>
                            <input type="email" class="form-control" name="notification[emails][<?php echo (int) $i; ?>][email]" value="<?php echo htmlspecialchars((string) $linha['email'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="cliente@empresa.com.br">
                          </div>
                          <div class="col-9 col-sm-4">
                            <label class="form-label mb-1"><small>Tipo</small></label>
                            <select class="form-select" name="notification[emails][<?php echo (int) $i; ?>][type]">
                              <?php foreach ($notification_types as $slug => $rotulo) { ?>
                                <option value="<?php echo $slug; ?>" <?php if ((string) $linha['type'] === $slug) echo 'selected=""'; ?>><?php echo $rotulo; ?></option>
                              <?php } ?>
                            </select>
                          </div>
                          <div class="col-3 col-sm-auto">
                            <button type="button" class="btn btn-outline-danger w-100 btn-remover-linha" title="Remover"><i class="mdi mdi-trash-can-outline"></i></button>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php } ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" id="btn_add_email"><i class="mdi mdi-plus"></i> ADICIONAR E-MAIL</button>
                <p class="text-muted mt-1 mb-3"><small>Havendo e-mails, ao menos um precisa ser <strong>Destinatário</strong>.</small></p>
              </div>

              <div class="col-12 col-lg-6">
                <label class="form-label">WhatsApp</label>
                <div id="repeater_whatsapps">
                  <?php
                  $linhasFone = !empty($notification_whatsapps) ? $notification_whatsapps : [['phone' => '']];
                  foreach ($linhasFone as $i => $linha) {
                  ?>
                    <div class="card border mb-2 linha-whatsapp">
                      <div class="card-body p-2">
                        <div class="row align-items-end g-2">
                          <div class="col">
                            <label class="form-label mb-1"><small>Telefone</small></label>
                            <input type="text" class="form-control phonemask" name="notification[whatsapps][<?php echo (int) $i; ?>][phone]" value="<?php echo htmlspecialchars((string) $linha['phone'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="(45) 99999-9999">
                          </div>
                          <div class="col-auto">
                            <button type="button" class="btn btn-outline-danger btn-remover-linha" title="Remover"><i class="mdi mdi-trash-can-outline"></i></button>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php } ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" id="btn_add_whatsapp"><i class="mdi mdi-plus"></i> ADICIONAR WHATSAPP</button>
                <p class="text-muted mt-1 mb-3"><small>Sem tipo: no WhatsApp cada número recebe a sua própria mensagem.</small></p>
              </div>
            </div>
            </div><?php // fim do bloco-cdw das Notificações ?>
            <button type="submit" class="btn btn-primary"><i class="mdi mdi-content-save"></i> SALVAR FATURAMENTO</button>
          </form>

          <?php if (!empty($charges)) { ?>
            <div class="bloco-cdw">
              <hr>
              <h6 class="mb-2">Cobranças avulsas</h6>
              <p class="text-muted mb-2"><small>Vendas pontuais deste contrato. Diferente da recorrência, não se repetem e não são reajustadas.</small></p>
              <div class="table-responsive">
                <table class="table table-striped table-bordered table-hover">
                  <thead>
                    <tr>
                      <th>Lançada em</th>
                      <th>Descrição</th>
                      <th class="text-end">Valor</th>
                      <th class="text-center">Parcelas</th>
                      <th class="text-center">Competência</th>
                      <th class="text-center">Situação</th>
                      <th class="text-center">Ações</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($charges as $cobranca) {
                      $cancelada = ((string) $cobranca->status === 'cancelada');
                    ?>
                      <tr>
                        <td><?php echo data($cobranca->created); ?></td>
                        <td>
                          <?php echo htmlspecialchars((string) $cobranca->description, ENT_QUOTES, 'UTF-8'); ?>
                          <?php if (!empty($cobranca->comments)) { ?>
                            <br /><small class="text-muted"><?php echo htmlspecialchars((string) $cobranca->comments, ENT_QUOTES, 'UTF-8'); ?></small>
                          <?php } ?>
                        </td>
                        <td class="text-end">R$ <?php echo reais($cobranca->value); ?></td>
                        <td class="text-center">
                          <?php echo (int) $cobranca->installments; ?>×
                          <br /><small class="text-muted"><?php echo (int) $cobranca->invoices_paid_count; ?>/<?php echo (int) $cobranca->invoices_count; ?> paga(s)</small>
                        </td>
                        <td class="text-center"><?php echo date('m/Y', strtotime($cobranca->competence)); ?></td>
                        <td class="text-center">
                          <span class="badge <?php echo $cancelada ? 'bg-secondary' : 'bg-success'; ?>"><?php echo $cancelada ? 'Cancelada' : 'Lançada'; ?></span>
                        </td>
                        <td class="text-center text-nowrap">
                          <?php if (!$cancelada) { ?>
                            <button type="button" class="btn btn-sm btn-outline-danger btn-cancelar-cobranca" data-id="<?php echo (int) $cobranca->id; ?>" data-descricao="<?php echo htmlspecialchars((string) $cobranca->description, ENT_QUOTES, 'UTF-8'); ?>" title="Cancelar a cobrança e as parcelas em aberto"><i class="mdi mdi-close"></i></button>
                          <?php } else { ?>
                            <span class="text-muted">—</span>
                          <?php } ?>
                        </td>
                      </tr>
                    <?php } ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php } ?>

          <?php
          // Serviço do catálogo do ERP — é o item que a emissão da cobrança vai
          // usar, e é ele que define o enquadramento fiscal da nota.
          $servicoVinculado = !empty($result->bomcontrole_service_id);
          ?>
          <div class="bloco-cdw">
            <hr>
            <div class="row">
              <div class="col">
                <h6 class="mb-1">Serviço no Bom Controle</h6>
                <?php if ($servicoVinculado) { ?>
                  <p class="mb-0">
                    <span class="badge bg-success">#<?php echo (int) $result->bomcontrole_service_id; ?></span>
                    <strong><?php echo htmlspecialchars((string) $result->bomcontrole_service_name, ENT_QUOTES, 'UTF-8'); ?></strong>
                  </p>
                <?php } else { ?>
                  <p class="text-muted mb-0"><small>
                      Nenhum serviço vinculado. A emissão do boleto e da nota fiscal precisa dele — é o serviço do catálogo do ERP que define o enquadramento fiscal da NF.
                    </small></p>
                <?php } ?>
              </div>
              <div class="col-auto text-end">
                <button type="button" class="btn btn-outline-primary" id="btn_vincular_servico_bc" <?php if (empty($bomcontrole_ativo)) echo 'disabled'; ?>>
                  <i class="mdi mdi-link-variant"></i> <?php echo $servicoVinculado ? 'TROCAR SERVIÇO' : 'VINCULAR SERVIÇO BOM CONTROLE'; ?>
                </button>
                <?php if ($servicoVinculado) { ?>
                  <button type="button" class="btn btn-outline-danger" id="btn_desvincular_servico_bc">
                    <i class="mdi mdi-link-variant-off"></i> DESVINCULAR
                  </button>
                <?php } ?>
              </div>
            </div>
            <?php if (empty($bomcontrole_ativo)) { ?>
              <div class="alert alert-secondary mt-2 mb-0" role="alert">
                <div class="alert-message">
                  <small>A integração com o Bom Controle está desativada para esta empresa — ative-a no cadastro da empresa para buscar o catálogo de serviços.</small>
                </div>
              </div>
            <?php } ?>
          </div>

          <?php if (!empty($adjustments)) { ?>
            <hr>
            <h6 class="mb-2">Histórico de reajustes</h6>
            <div class="table-responsive">
              <table class="table table-striped table-bordered table-hover">
                <thead>
                  <tr>
                    <th>Aplicado em</th>
                    <th>Índice</th>
                    <th class="text-end">Percentual</th>
                    <th>Janela</th>
                    <th class="text-end">De</th>
                    <th class="text-end">Para</th>
                    <th class="text-center">Avisado</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($adjustments as $reajuste) { ?>
                    <tr>
                      <td><?php echo date('d/m/Y', strtotime($reajuste->applied_at)); ?></td>
                      <td><?php echo htmlspecialchars(mb_strtoupper((string) $reajuste->index_slug), ENT_QUOTES, 'UTF-8'); ?></td>
                      <td class="text-end"><?php echo reais($reajuste->rate); ?>%</td>
                      <td><small><?php echo date('m/Y', strtotime($reajuste->competence_start)); ?> a <?php echo date('m/Y', strtotime($reajuste->competence_end)); ?></small></td>
                      <td class="text-end">R$ <?php echo reais($reajuste->value_before); ?></td>
                      <td class="text-end">R$ <?php echo reais($reajuste->value_after); ?></td>
                      <td class="text-center">
                        <?php if (!empty($reajuste->notified)) { ?>
                          <i class="mdi mdi-check-circle text-success" title="Cliente avisado"></i>
                        <?php } else { ?>
                          <i class="mdi mdi-alert-circle text-warning" title="Aplicado sem aviso prévio registrado"></i>
                        <?php } ?>
                      </td>
                    </tr>
                  <?php } ?>
                </tbody>
              </table>
            </div>
          <?php } ?>
        </div>
      </div>
    <?php } ?>

    <div class="card flex-fill">
      <div class="card-body py-3">
        <div class="row">
          <div class="col">
            <h5 class="card-title mb-1">Domínios</h5>
            <p class="text-muted mb-2 lh-1"><small>Inclua ou remova domínios deste contrato. A busca vincula automaticamente ao domínio sincronizado do servidor, quando existir. O mesmo domínio pode entrar mais de uma vez, desde que em servidores diferentes — é o caso do site num painel e das contas de e-mail em outro.</small></p>
          </div>
          <div class="col-auto text-end">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal_dominio"><i class="mdi mdi-plus"></i> ADICIONAR DOMÍNIO</button>
          </div>
        </div>
        <?php if (!empty($domains)) { ?>
          <div class="table-responsive">
            <table class="table table-striped table-bordered table-hover">
              <thead>
                <tr>
                  <th class="text-center" style="width: 210px;">Ações</th>
                  <th>Domínio</th>
                  <th class="text-center">Vínculo</th>
                  <th class="text-center">Em uso</th>
                  <th class="text-center">Vencimento</th>
                  <th>Local de registro</th>
                  <th class="text-center">Gerenciado CDW</th>
                  <th>Observações</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($domains as $d) { ?>
                  <tr data-dominio="<?php echo (int) $d->id; ?>">
                    <td align="center">
                      <button type="button" class="btn btn-sm btn-outline-secondary js-editar-dominio" data-id="<?php echo (int) $d->id; ?>" data-nome="<?php echo htmlspecialchars($d->domain, ENT_QUOTES, 'UTF-8'); ?>" data-vencimento="<?php echo !empty($d->due_date) ? date('d/m/Y', strtotime($d->due_date)) : ''; ?>" data-registro="<?php echo htmlspecialchars((string) $d->registrar, ENT_QUOTES, 'UTF-8'); ?>" data-gerenciado="<?php echo (int) $d->managed_cdw; ?>" data-observacoes="<?php echo htmlspecialchars((string) $d->comments, ENT_QUOTES, 'UTF-8'); ?>" title="Editar domínio"><i class="fa fa-edit"></i></button>
                      <button type="button" class="btn btn-sm btn-outline-primary js-whois-dominio" data-id="<?php echo (int) $d->id; ?>" data-dominio="<?php echo htmlspecialchars($d->domain, ENT_QUOTES, 'UTF-8'); ?>" title="Consultar WHOIS do domínio"><i class="fa fa-search"></i></button>
                      <button type="button" class="btn btn-sm btn-outline-info js-quota-dominio" data-id="<?php echo (int) $d->id; ?>" data-nome="<?php echo htmlspecialchars($d->domain, ENT_QUOTES, 'UTF-8'); ?>" <?php echo empty($d->id_server_domain) ? 'disabled title="Domínio sem vínculo com conta de servidor — não há cota a alterar"' : 'title="Alterar cota de disco da conta"'; ?>><i class="mdi mdi-server"></i></button>
                      <button type="button" class="btn btn-sm btn-outline-danger js-excluir-dominio" data-id="<?php echo (int) $d->id; ?>" data-nome="<?php echo htmlspecialchars($d->domain, ENT_QUOTES, 'UTF-8'); ?>" data-servidor="<?php echo !empty($d->id_server_domain) ? htmlspecialchars((string) $d->server_name, ENT_QUOTES, 'UTF-8') : ''; ?>" title="Excluir domínio"><i class="fa fa-trash"></i></button>
                    </td>
                    <td><?php echo htmlspecialchars($d->domain, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td align="center">
                      <?php if (!empty($d->id_server_domain)) { ?>
                        <span class="badge bg-success" title="Status no painel: <?php echo htmlspecialchars((string) $d->server_domain_status, ENT_QUOTES, 'UTF-8'); ?>"><i class="mdi mdi-server"></i> <?php echo htmlspecialchars((string) $d->server_name, ENT_QUOTES, 'UTF-8'); ?></span>
                      <?php } else { ?>
                        <span class="badge bg-secondary">Sem vínculo</span>
                      <?php } ?>
                    </td>
                    <td align="center"><?php echo ($d->server_disk_used_mb !== NULL && $d->id_server_domain) ? number_format((float) $d->server_disk_used_mb, 0, ',', '.') . ' MB' : '—'; ?></td>
                    <td align="center" class="js-cel-venc-dominio" data-id="<?php echo (int) $d->id; ?>"><?php echo !empty($d->due_date) ? date('d/m/Y', strtotime($d->due_date)) : '—'; ?></td>
                    <td><?php echo !empty($d->registrar) ? htmlspecialchars($d->registrar, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                    <td align="center">
                      <?php if ((int) $d->managed_cdw === 1) { ?>
                        <span class="badge bg-primary">Sim</span>
                      <?php } else { ?>
                        <span class="badge bg-light text-dark border">Não</span>
                      <?php } ?>
                    </td>
                    <td><?php echo !empty($d->comments) ? nl2br(htmlspecialchars($d->comments, ENT_QUOTES, 'UTF-8')) : '—'; ?></td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
        <?php } else { ?>
          <div class="text-center text-muted py-4">
            <i class="mdi mdi-web fs-1 d-block mb-2"></i>
            <h5 class="mb-1">Nenhum domínio cadastrado</h5>
            <p class="mb-0">Adicione o primeiro domínio do contrato.</p>
          </div>
        <?php } ?>
      </div>
    </div>

    <div class="card flex-fill">
      <div class="card-body py-3">
        <div class="row">
          <div class="col">
            <h5 class="card-title mb-1">Documentos</h5>
            <p class="text-muted mb-2 lh-1"><small>Inclua, consulte ou remova documentos do contrato.</small></p>
          </div>
          <div class="col-auto text-end">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal_documento"><i class="mdi mdi-paperclip"></i> ANEXAR ARQUIVO</button>
          </div>
        </div>

        <?php if (!empty($files)) { ?>
          <div class="table-responsive">
            <table class="table table-striped table-bordered table-hover">
              <thead>
                <tr>
                  <th class="text-center" style="width: 130px;">Ações</th>
                  <th>Nome</th>
                  <th class="text-center">Enviado por</th>
                  <th class="text-center">Criado em</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($files as $c) { ?>
                  <tr data-documento="<?php echo (int) $c->id; ?>">
                    <td align="center">
                      <a class="btn btn-sm btn-outline-primary" target="_blank" href="<?php echo base_url() . $c->file; ?>" title="Abrir documento"><i class="fa fa-external-link-alt"></i></a>
                      <button type="button" class="btn btn-sm btn-outline-danger js-excluir-documento" data-id="<?php echo (int) $c->id; ?>" data-nome="<?php echo htmlspecialchars($c->name, ENT_QUOTES, 'UTF-8'); ?>" title="Excluir documento"><i class="fa fa-trash"></i></button>
                    </td>
                    <td><?php echo htmlspecialchars($c->name, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td align="center"><?php echo !empty($c->created_user) ? htmlspecialchars($c->created_user, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                    <td align="center"><?php echo data($c->created); ?></td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
        <?php } else { ?>
          <div class="text-center text-muted py-4">
            <i class="mdi mdi-file-document-outline fs-1 d-block mb-2"></i>
            <h5 class="mb-1">Nenhum documento cadastrado</h5>
            <p class="mb-0">Clique em ANEXAR ARQUIVO para adicionar o primeiro documento do contrato.</p>
          </div>
        <?php } ?>
      </div>
    </div>

  </div>

  <div class="tab-pane fade" id="tab_extrato" role="tabpanel">
    <div class="card flex-fill">
      <div class="card-body py-3">
        <div class="row align-items-center mb-2">
          <div class="col">
            <h5 class="card-title mb-0">Extrato Bom Controle</h5>
            <?php if (!empty($result->bomcontrole_contract_id)) { ?>
              <small class="text-muted">
                Vinculado ao contrato Bom Controle #<?php echo (int) $result->bomcontrole_contract_id; ?>
                <?php if (!empty($result->bomcontrole_linked)) { ?> em <?php echo data($result->bomcontrole_linked); ?><?php } ?>
              </small>
            <?php } ?>
          </div>
          <div class="col-auto">
            <?php if (empty($result->bomcontrole_contract_id)) { ?>
              <button type="button" class="btn btn-primary btn-sm" id="btn_vincular_bc" <?php if (empty($bomcontrole_ativo)) echo 'disabled'; ?>><i class="mdi mdi-link-variant"></i> VINCULAR AO BOM CONTROLE</button>
            <?php } else { ?>
              <button type="button" class="btn btn-outline-primary btn-sm" id="btn_atualizar_extrato"><i class="mdi mdi-refresh"></i> ATUALIZAR</button>
              <button type="button" class="btn btn-outline-danger btn-sm" id="btn_desvincular_bc"><i class="mdi mdi-link-variant-off"></i> DESVINCULAR</button>
            <?php } ?>
          </div>
        </div>
        <div id="extrato_conteudo">
          <?php if (empty($bomcontrole_ativo)) { ?>
            <div class="alert alert-warning mb-0" role="alert">
              <div class="alert-message">
                Integração com o Bom Controle desativada para esta empresa. Ative-a no cadastro da empresa (aba Bom Controle) para consultar o extrato.
              </div>
            </div>
          <?php } elseif (empty($result->bomcontrole_contract_id)) { ?>
            <div class="text-center text-muted py-5">
              <i class="mdi mdi-cash-multiple fs-1 d-block mb-2"></i>
              <h5 class="mb-1">Nenhum contrato do Bom Controle vinculado</h5>
              <p class="mb-0">Clique em VINCULAR AO BOM CONTROLE para buscar pelos contratos do CPF/CNPJ deste cliente.</p>
            </div>
          <?php } ?>
        </div>
      </div>
    </div>
  </div>

  <div class="tab-pane fade" id="tab_faturas" role="tabpanel">
    <div class="card flex-fill">
      <div class="card-body py-3">
        <div class="row align-items-center mb-2">
          <div class="col">
            <h5 class="card-title mb-0">Faturas</h5>
            <small class="text-muted">Geradas pelo CDW Finance para este contrato.</small>
          </div>
          <div class="col-auto">
            <button type="button" class="btn btn-outline-primary btn-sm" id="btn_atualizar_faturas"><i class="mdi mdi-refresh"></i> ATUALIZAR</button>
          </div>
        </div>
        <div id="faturas_conteudo"></div>
      </div>
    </div>
  </div>

  <div class="tab-pane fade" id="tab_historicos" role="tabpanel">
    <div class="card flex-fill">
      <div class="card-body py-3">
        <?php
        // Cores por severidade do evento, no mesmo vocabulário dos selos de
        // situação usados na listagem de clientes e no card do Dashboard —
        // três vocabulários para o mesmo estado fariam a mesma mudança parecer
        // coisas diferentes conforme a tela.
        $histCores = [
          'critico' => ['badge' => 'bg-danger', 'borda' => 'border-danger'],
          'alerta' => ['badge' => 'bg-warning text-dark', 'borda' => 'border-warning'],
          'info' => ['badge' => 'bg-secondary', 'borda' => 'border-secondary'],
        ];
        ?>

        <?php if (empty($history)) { ?>
          <div class="text-center text-muted py-5">
            <i class="mdi mdi-history fs-1 d-block mb-2"></i>
            <h5 class="mb-1">Sem histórico ainda</h5>
            <p class="mb-0">
              As mudanças de estado deste contrato — suspensão, reativação, encerramento e exclusão —
              passam a ser registradas aqui, com a data, o autor e a origem.
            </p>
          </div>
        <?php } else { ?>
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Histórico de alterações</h5>
            <span class="text-muted small"><?php echo count($history); ?> registro(s)</span>
          </div>

          <div class="timeline">
            <?php foreach ($history as $h) {
              $meta = isset($history_events[$h->event]) ? $history_events[$h->event] : ['rotulo' => $h->event, 'severidade' => 'info'];
              $cor = isset($histCores[$meta['severidade']]) ? $histCores[$meta['severidade']] : $histCores['info'];
              $origem = isset($history_origins[$h->origin]) ? $history_origins[$h->origin] : $h->origin;
              // A origem só vira selo quando NÃO é o painel: "Painel" em toda
              // linha é ruído, e o que importa destacar é justamente a mudança
              // que não partiu de alguém clicando.
              $origemDestaque = ((string) $h->origin !== 'painel');
              ?>
              <div class="border-start border-3 <?php echo $cor['borda']; ?> ps-3 pb-3 mb-1">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                  <span class="badge <?php echo $cor['badge']; ?>"><?php echo htmlspecialchars($meta['rotulo'], ENT_QUOTES, 'UTF-8'); ?></span>

                  <?php if (!empty($h->status_from) && !empty($h->status_to)) { ?>
                    <span class="text-muted small">
                      <?php echo htmlspecialchars($h->status_from, ENT_QUOTES, 'UTF-8'); ?>
                      <i class="mdi mdi-arrow-right"></i>
                      <?php echo htmlspecialchars($h->status_to, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                  <?php } ?>

                  <?php if ($origemDestaque) { ?>
                    <span class="badge bg-dark" title="Mudança que não partiu do painel">
                      <i class="mdi mdi-robot"></i> <?php echo htmlspecialchars($origem, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                  <?php } ?>

                  <?php if (!empty($h->notified)) { ?>
                    <span class="badge bg-light text-muted border" title="Aviso por e-mail enfileirado em <?php echo date('d/m/Y H:i', strtotime($h->notified)); ?>">
                      <i class="mdi mdi-email-check-outline"></i> avisado
                    </span>
                  <?php } ?>
                </div>

                <div class="text-muted small mb-1">
                  <i class="mdi mdi-clock-outline"></i>
                  <?php echo date('d/m/Y \à\s H:i', strtotime($h->created)); ?>
                  <?php if (!empty($h->created_user)) { ?>
                    &middot; <?php echo htmlspecialchars($h->created_user, ENT_QUOTES, 'UTF-8'); ?>
                  <?php } ?>
                </div>

                <?php if (!empty($h->reason)) {
                  // Cai no próprio slug quando o motivo saiu do catálogo — o
                  // carimbo histórico não pode perder o porquê junto (032).
                  $rotuloMotivo = isset($end_reasons[$h->reason]) ? $end_reasons[$h->reason] : $h->reason;
                  ?>
                  <div class="small mb-1">
                    <strong>Motivo:</strong> <?php echo htmlspecialchars($rotuloMotivo, ENT_QUOTES, 'UTF-8'); ?>
                  </div>
                <?php } ?>

                <?php if (!empty($h->comments)) { ?>
                  <div class="small mb-1"><?php echo nl2br(htmlspecialchars($h->comments, ENT_QUOTES, 'UTF-8')); ?></div>
                <?php } ?>

                <?php if (!empty($h->detail)) { ?>
                  <div class="text-muted small"><?php echo htmlspecialchars($h->detail, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php } ?>
              </div>
            <?php } ?>
          </div>

          <?php // `.alert-message` obrigatório: o `.alert` do tema é `display: flex` e,
          // sem ele, cada <strong> vira um flex item — quebra em coluna e come o
          // espaço em volta. ?>
          <div class="alert alert-info p-2 mt-3 mb-0 small" role="alert">
            <div class="alert-message">
              <i class="mdi mdi-information-outline"></i>
              Só as mudanças de <strong>estado</strong> entram aqui (criação, suspensão, reativação, encerramento,
              reabertura e exclusão). Edições de valor, ciclo ou faturamento não são registradas — o reajuste tem
              histórico próprio, no bloco de Faturamento.
              Quem recebe o aviso por e-mail dessas mudanças é configurado em
              <strong>Parâmetros gerais &rsaquo; Contratos</strong>.
            </div>
          </div>
        <?php } ?>
      </div>
    </div>
  </div>
</div>

<!-- modal cobranca avulsa -->
<div class="modal fade" id="modal_cobranca" aria-hidden="true" style="display: none;">
  <div class="modal-dialog modal-md" role="document">
    <div class="modal-content">
      <form name="form" method="POST" id="form_cobranca" action="<?php echo base_url('contratos/post_lancarcobranca'); ?>">
        <input type="hidden" name="id" value="<?php echo (int) $result->id; ?>">
        <div class="modal-header">
          <h5 class="modal-title">LANÇAR COBRANÇA AVULSA</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>
        <div class="modal-body m-3">
          <p class="text-muted">
            <small>Venda pontual dentro deste contrato — um serviço extra, uma migração, um desenvolvimento.
              As parcelas são geradas na hora, com o vencimento de cada mês, e <strong>não se repetem no ciclo seguinte</strong>.</small>
          </p>

          <div class="form-group mb-3">
            <label class="form-label">* Descrição</label>
            <input type="text" class="form-control" name="charge[description]" id="cobranca_description" maxlength="255" placeholder="Ex.: Desenvolvimento do serviço X">
            <small class="form-text text-muted">É o texto que aparece em cada parcela na tela de Faturas.</small>
          </div>

          <div class="row">
            <div class="col-12 col-md-6">
              <div class="form-group mb-3">
                <label class="form-label">* Valor total</label>
                <input type="text" class="form-control moneymask" name="charge[value]" id="cobranca_value" value="0,00">
              </div>
            </div>
            <div class="col-12 col-md-6">
              <div class="form-group mb-3">
                <label class="form-label">* Parcelas</label>
                <input type="number" class="form-control" name="charge[installments]" id="cobranca_installments" min="1" max="<?php echo (int) $max_parcelas_avulsa; ?>" value="1">
              </div>
            </div>
          </div>

          <div class="alert alert-secondary py-2" role="alert">
            <div class="alert-message"><small id="cobranca_resumo">Informe o valor e o número de parcelas.</small></div>
          </div>

          <div class="row">
            <div class="col-12 col-md-6">
              <div class="form-group mb-3">
                <label class="form-label">* Primeiro vencimento</label>
                <input type="text" class="form-control" name="charge[due_date]" id="cobranca_due_date" data-mask="00/00/0000" value="<?php echo date('d/m/Y', strtotime('+30 days')); ?>">
                <small class="form-text text-muted">As demais vencem no mesmo dia dos meses seguintes.</small>
              </div>
            </div>
            <div class="col-12 col-md-6">
              <div class="form-group mb-3">
                <label class="form-label">Emitir NF-e</label>
                <select class="form-control" name="charge[invoice_policy]" id="cobranca_invoice_policy">
                  <?php foreach ($invoice_policies as $slug => $rotulo) { ?>
                    <option value="<?php echo $slug; ?>" <?php if ((string) $result->invoice_policy === $slug) echo 'selected=""'; ?>><?php echo $rotulo; ?></option>
                  <?php } ?>
                </select>
                <small class="form-text text-muted">Herdada do contrato.</small>
              </div>
            </div>
          </div>

          <div class="form-group mb-0">
            <label class="form-label">Observações</label>
            <textarea class="form-control" name="charge[comments]" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">CANCELAR</button>
          <button type="submit" class="btn btn-primary"><i class="mdi mdi-content-save"></i> LANÇAR</button>
        </div>
      </form>
    </div>
  </div>
</div>

<form method="POST" id="form_cancelar_cobranca" action="<?php echo base_url('contratos/post_cancelarcobranca'); ?>">
  <input type="hidden" name="id" value="<?php echo (int) $result->id; ?>">
  <input type="hidden" name="id_charge" id="cancelar_cobranca_id" value="">
</form>

<!-- modal cota da conta -->
<div class="modal fade" id="modal_quota_conta" aria-hidden="true" style="display: none;">
  <div class="modal-dialog modal-md" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">ALTERAR COTA DA CONTA</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body m-1" style="min-height:0;">

        <div id="quota_carregando" class="text-center text-muted py-4">
          <div class="spinner-border spinner-border-sm me-2" role="status"></div> Carregando os dados da conta...
        </div>

        <div id="quota_conteudo" class="d-none">
          <div class="mb-3">
            <div class="fw-bold" id="quota_dominio"></div>
            <small class="text-muted" id="quota_conta_info"></small>
          </div>

          <div class="row text-center mb-3">
            <div class="col-6">
              <div class="border rounded py-2">
                <small class="text-muted d-block">Em uso</small>
                <span class="fw-bold" id="quota_uso">—</span>
              </div>
            </div>
            <div class="col-6">
              <div class="border rounded py-2">
                <small class="text-muted d-block">Capacidade atual</small>
                <span class="fw-bold" id="quota_atual">—</span>
              </div>
            </div>
          </div>

          <!-- Painel sem cota: o modal abre e explica, em vez de o botão sumir da
               linha sem dizer por quê. -->
          <div id="quota_incompativel" class="alert alert-warning d-none">
            <div class="alert-message">
              <i class="mdi mdi-information-outline"></i> <span id="quota_motivo"></span>
            </div>
          </div>

          <div id="quota_formulario">
            <div class="form-group mb-3">
              <label class="form-label">Nova capacidade (Gb)</label>
              <input type="number" min="0" step="0.01" class="form-control" id="quota_gb" value="" placeholder="Ex.: 10" data-mask="00,00">
              <small class="text-muted" id="quota_referencia"></small>
            </div>
            <div class="form-group mb-3">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="quota_ilimitado">
                <label class="form-check-label" for="quota_ilimitado">Ilimitado (sem cota)</label>
              </div>
            </div>
            <div class="alert alert-light border">
              <div class="alert-message">
                <small>A alteração vale para a <strong>conta inteira</strong> no painel — todos os domínios e
                  subdomínios daquele usuário —, e passa a valer imediatamente.</small>
              </div>
            </div>
          </div>
        </div>

      </div>
      <div class="modal-footer">
        <div class="col">
          <button type="button" class="btn w-100 btn-primary" id="btn_salvar_quota" disabled><i class="mdi mdi-content-save"></i> SALVAR</button>
        </div>
        <div class="col"></div>
        <div class="col">
          <button type="button" class="btn w-100 btn-outline-secondary" data-bs-dismiss="modal"><i class="fa fa-times"></i> FECHAR</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- modal dominio -->
<div class="modal fade" id="modal_dominio" aria-hidden="true" style="display: none;">
  <div class="modal-dialog modal-md" role="document">
    <div class="modal-content">
      <form name="form" method="POST" id="form_dominio" action="<?php echo base_url('contratos/post_salvardominio'); ?>">
        <input type="hidden" name="id" value="<?php echo (int) $result->id; ?>">
        <input type="hidden" name="id_domain" id="dominio_id_domain" value="">
        <input type="hidden" name="id_server_domain" id="dominio_id_server_domain" value="">
        <div class="modal-header">
          <h5 class="modal-title" id="modal_dominio_titulo">ADICIONAR DOMÍNIO</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body m-1" style="min-height:0;">
          <div class="form-group mb-3">
            <label class="form-label">* Domínio</label>
            <div class="input-group">
              <input type="text" class="form-control" maxlength="255" required name="domain" id="dominio_nome" value="" placeholder="exemplo.com.br">
              <button type="button" class="btn btn-outline-primary" id="btn_buscar_dominio" title="Buscar o domínio nos servidores para vincular automaticamente"><i class="mdi mdi-magnify"></i> BUSCAR</button>
            </div>
            <small class="text-muted" id="dominio_dica">A busca verifica em quais servidores o domínio existe — é obrigatória antes de salvar. Se ele estiver em mais de um, cada conta é um cadastro à parte. Ela também consulta o registro do domínio (Registro.br nos <strong>.br</strong>, WHOIS nos demais) e preenche o vencimento e o local de registro.</small>
          </div>
          <div id="resultado_busca"></div>
          <div id="resultado_whois"></div>
          <div class="row">
            <div class="col-12 col-sm-6">
              <div class="form-group mb-3">
                <label class="form-label">Data de vencimento</label>
                <input type="text" class="form-control" name="due_date" id="dominio_vencimento" data-mask="00/00/0000" placeholder="dd/mm/aaaa" value="">
                <small class="text-muted">Em domínios internacionais vinculados a um servidor, esta data é atualizada automaticamente pela consulta de WHOIS e substitui o valor digitado aqui.</small>
              </div>
            </div>
            <div class="col-12 col-sm-6">
              <div class="form-group mb-3">
                <label class="form-label">Local de registro</label>
                <input type="text" class="form-control" maxlength="150" name="registrar" id="dominio_registro" value="" placeholder="Ex.: Registro.br, GoDaddy...">
              </div>
            </div>
          </div>
          <div class="form-group mb-3">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" name="managed_cdw" value="S" id="dominio_gerenciado">
              <label class="form-check-label" for="dominio_gerenciado">Gerenciado CDW</label>
            </div>
          </div>
          <div class="form-group mb-3">
            <label class="form-label">Observações</label>
            <textarea class="form-control" name="comments" id="dominio_observacoes" rows="2" maxlength="1000"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <div class="col">
            <button type="submit" class="btn w-100 btn-primary" id="btn_salvar_dominio" disabled title="Faça a busca do domínio antes de salvar"><i class="mdi mdi-content-save"></i> SALVAR</button>
          </div>
          <div class="col"></div>
          <div class="col">
            <button type="button" class="btn w-100 btn-outline-secondary" data-bs-dismiss="modal"><i class="fa fa-times"></i> FECHAR</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>
<!-- modal dominio -->

<!-- modal documento -->
<div class="modal fade" id="modal_documento" aria-hidden="true" style="display: none;">
  <div class="modal-dialog modal-md" role="document">
    <div class="modal-content">
      <form enctype="multipart/form-data" name="form" method="POST" action="<?php echo base_url('contratos/post_sendfile'); ?>">
        <input type="hidden" name="id" value="<?php echo (int) $result->id; ?>">
        <div class="modal-header">
          <h5 class="modal-title">ANEXAR ARQUIVO</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body m-1" style="min-height:0;">
          <div class="form-group mb-3">
            <label class="form-label">* Nome</label>
            <input type="text" class="form-control" maxlength="150" required name="name" value="" placeholder="Ex.: Contrato assinado, Aditivo, Distrato...">
          </div>
          <div class="form-group mb-3">
            <label class="form-label">* Selecionar arquivo <small>(JPG, PNG, PDF, XLS ou DOC — até 10 MB)</small></label>
            <input type="file" required name="file" class="form-control" accept=".jpg,.jpeg,.png,.pdf,.xls,.xlsx,.doc,.docx">
          </div>
        </div>
        <div class="modal-footer">
          <div class="col">
            <button type="submit" class="btn w-100 btn-primary"><i class="mdi mdi-content-save"></i> SALVAR</button>
          </div>
          <div class="col"></div>
          <div class="col">
            <button type="button" class="btn w-100 btn-outline-secondary" data-bs-dismiss="modal"><i class="fa fa-times"></i> FECHAR</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>
<!-- modal documento -->

<?php if (!$encerrado) { ?>
  <div class="modal fade" id="modal_encerrar" aria-hidden="true" style="display: none;">
    <div class="modal-dialog modal-md" role="document">
      <div class="modal-content">
        <form name="form" id="form_encerrar" method="POST" action="<?php echo base_url('contratos/post_encerrar'); ?>">
          <input type="hidden" name="id" value="<?php echo (int) $result->id; ?>">
          <div class="modal-header">
            <h5 class="modal-title">ENCERRAR CONTRATO</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body m-1" style="min-height:0;">
            <div class="alert alert-warning" role="alert">
              <div class="alert-message">
                O contrato passa a <strong>Encerrado</strong>, sai dos indicadores de vigentes e entra na
                <strong>barra de saídas</strong> do mês corrente no Dashboard, com o valor de
                <strong>R$ <?php echo reais($result->value); ?></strong>.
                <br><small>Suspensão não faz isso — use SUSPENDER quando a parada for temporária.</small>
              </div>
            </div>
            <?php if ((int) $dominios_com_vinculo > 0) { ?>
              <div class="alert alert-danger" role="alert">
                <div class="alert-message">
                  <i class="mdi mdi-server-off"></i>
                  <strong><?php echo (int) $dominios_com_vinculo; ?> domínio(s) vinculado(s)</strong> terão a conta
                  <strong>suspensa nos painéis</strong> (WHM/DirectAdmin suspendem a conta inteira; CloudPanel, o site;
                  Carbonio, o domínio de e-mail inteiro, retendo as mensagens que chegarem) e,
                  em seguida, serão <strong>desvinculados</strong> — a conta fica órfã, sem contrato.
                  <br><small>
                    Reabrir o contrato depois <strong>não</strong> reativa as contas nem refaz o vínculo. Conta
                    compartilhada com outro contrato vigente não é suspensa, e o que falhar fica listado na tela.
                  </small>
                </div>
              </div>
            <?php } ?>
            <div class="form-group mb-3">
              <label class="form-label">* Motivo do encerramento</label>
              <?php // Só os ativos: o catálogo é gerido em GESTÃO › Motivos de cancelamento. 
              ?>
              <select class="form-control" name="reason" required>
                <option value="">Selecione...</option>
                <?php foreach ($end_reasons_ativos as $slug => $rotulo) { ?>
                  <option value="<?php echo $slug; ?>"><?php echo htmlspecialchars($rotulo, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php } ?>
              </select>
            </div>
            <div class="form-group mb-3">
              <label class="form-label d-flex justify-content-between">
                <span>Observações</span>
                <?php // O maxlength trava a digitação sem dizer por quê; o contador mostra o teto antes de o usuário esbarrar nele. 
                ?>
                <small class="text-muted"><span id="encerrar_contador">0</span>/300</small>
              </label>
              <textarea class="form-control" name="comments" id="encerrar_comments" rows="3" maxlength="300" placeholder="Detalhe do encerramento (opcional)."></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <div class="col">
              <button type="submit" class="btn w-100 btn-danger"><i class="mdi mdi-close-circle-outline"></i> ENCERRAR CONTRATO</button>
            </div>
            <div class="col"></div>
            <div class="col">
              <button type="button" class="btn w-100 btn-outline-secondary" data-bs-dismiss="modal"><i class="fa fa-times"></i> FECHAR</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
  <!-- modal encerrar -->
<?php } ?>

<div class="modal fade" id="modal_vinculo_bc" aria-hidden="true" style="display: none;">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">VINCULAR CONTRATO — BOM CONTROLE</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body m-1" style="min-height:0;">
        <p class="text-muted mb-2" id="vinculo_bc_documento"></p>
        <div class="table-responsive">
          <table class="table table-sm table-striped table-hover mb-0">
            <thead>
              <tr>
                <th style="width: 40px;"></th>
                <th>Contrato BC</th>
                <th>Cliente</th>
                <th class="text-center">Início</th>
                <th class="text-end">Valor</th>
                <th class="text-end">Fatura recente</th>
              </tr>
            </thead>
            <tbody id="vinculo_bc_lista"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <div class="col">
          <button type="button" class="btn w-100 btn-primary" id="btn_confirmar_vinculo_bc" disabled><i class="mdi mdi-link-variant"></i> VINCULAR</button>
        </div>
        <div class="col"></div>
        <div class="col">
          <button type="button" class="btn w-100 btn-outline-secondary" data-bs-dismiss="modal"><i class="fa fa-times"></i> FECHAR</button>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- modal vinculo bom controle -->

<!-- modal do catalogo de servicos: a busca é sob demanda porque o rate limit do
     ERP não tolera varrer os 119 serviços a cada abertura -->
<div class="modal fade" id="modal_servico_bc" aria-hidden="true" style="display: none;">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">SERVIÇO DO BOM CONTROLE</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body m-1" style="min-height:0;">
        <p class="text-muted">
          <small>Este é o serviço que a cobrança vai usar no ERP. O <strong>tipo</strong> ao lado de cada um é o código fiscal do serviço — é ele que determina a tributação da nota.</small>
        </p>

        <div class="input-group mb-3">
          <input type="text" class="form-control" id="servico_bc_termo" placeholder="Buscar no catálogo (ex.: site, hospedagem, suporte)">
          <button class="btn btn-primary" type="button" id="btn_buscar_servico_bc"><i class="mdi mdi-magnify"></i> BUSCAR</button>
        </div>

        <div id="servico_bc_aviso"></div>

        <div class="table-responsive" style="max-height: 360px; overflow-y: auto;">
          <table class="table table-sm table-striped table-hover mb-0">
            <thead>
              <tr>
                <th style="width: 40px;"></th>
                <th style="width: 70px;">Id</th>
                <th>Serviço</th>
                <th>Tipo (código fiscal)</th>
              </tr>
            </thead>
            <tbody id="servico_bc_lista"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <div class="col">
          <button type="button" class="btn w-100 btn-primary" id="btn_confirmar_servico_bc" disabled><i class="mdi mdi-link-variant"></i> VINCULAR</button>
        </div>
        <div class="col"></div>
        <div class="col">
          <button type="button" class="btn w-100 btn-outline-secondary" data-bs-dismiss="modal"><i class="fa fa-times"></i> FECHAR</button>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- modal catalogo de servicos -->

<?php // Modal do provedor na aba Faturas. Renderizado só quando há provedor
// ativo — o mesmo desenho da tela de Faturas, e o mesmo endpoint. 
?>
<?php if (!empty($psp_disponiveis)) { ?>
  <div class="modal fade" id="modal_psp_aba" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Provedor da cobrança</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label" for="psp_aba_select">Registrar esta fatura em</label>
            <select class="form-select" id="psp_aba_select">
              <?php foreach ($psp_disponiveis as $pspSlug => $pspNome) { ?>
                <option value="<?php echo htmlspecialchars($pspSlug, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($pspNome, ENT_QUOTES, 'UTF-8'); ?></option>
              <?php } ?>
            </select>
            <small class="text-muted">
              Manter o mesmo provedor e confirmar <strong>força o registro</strong> — é o caminho quando a emissão falhou.
            </small>
          </div>
          <div class="alert alert-warning d-none" id="psp_aba_aviso" role="alert">
            <div class="alert-message">
              Esta fatura <strong>já tem cobrança registrada</strong>. Ao trocar de provedor, ela é
              <strong>cancelada no provedor atual</strong> antes de a nova ser emitida — se o cancelamento
              falhar, a troca é abortada, para não deixar dois boletos da mesma fatura em aberto.
            </div>
          </div>
          <p class="text-muted mb-0">O contrato não muda: a alteração vale só para esta fatura.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">VOLTAR</button>
          <button type="button" class="btn btn-primary" id="btn_confirmar_psp_aba">CONFIRMAR</button>
        </div>
      </div>
    </div>
  </div>
<?php } ?>

<?php // Visualizador do boleto. O iframe aponta para o endpoint de streaming,
// que serve do banco — e NÃO para uma data: URL com o base64 embutido:
// navegadores bloqueiam navegação para data: em PDF, e o HTML da página
// carregaria ~120 KB por boleto aberto. 
?>
<div class="modal fade" id="modal_boleto" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Boleto <span class="text-muted" id="boleto_titulo_fatura"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body p-0">
        <?php // Altura fixa: sem ela o iframe nasce com 150px e o boleto fica
        // ilegível dentro de um modal grande e vazio. 
        ?>
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

    // Sessão expirada: o MY_Controller responde 200 com {redirect:true}, então
    // a checagem precisa ser feita dentro do success, não pelo status HTTP.
    function sessaoExpirou(data) {
      if (data && data.redirect) {
        window.location.replace('<?php echo base_url('painel/sair_custom'); ?>');
        return true;
      }
      return false;
    }

    // Ao menos um tipo de serviço marcado (checkbox não tem required nativo
    // de grupo; a validação de verdade continua no servidor).
    $('#form_dados_gerais').on('submit', function(e) {
      if ($(this).find('.js-tipo-servico:checked').length === 0) {
        e.preventDefault();
        Swal.fire('Atenção', 'Marque ao menos um tipo de serviço para o contrato.', 'warning');
        return false;
      }
    });

    $('.moneymask').maskMoney({
      thousands: '.',
      decimal: ',',
      allowZero: true
    });

    // A confirmação diz quantas CONTAS a ação derruba (ou devolve), porque o
    // efeito não é só o status: no WHM e no DirectAdmin a suspensão é da conta
    // inteira, e ela sai do ar assim que o botão é confirmado.
    $('#btn_status').on('click', function() {
      var suspender = $(this).data('acao') === 'suspender';
      var vinculados = <?php echo (int) $dominios_com_vinculo; ?>;
      var contas = vinculados > 0 ?
        '<br>' + vinculados + ' domínio(s) vinculado(s) ' + (suspender ? 'serão suspensos' : 'serão reativados') +
        ' nos painéis dos servidores.' :
        '<br><small class="text-muted">Nenhum domínio vinculado a servidor — nada muda nos painéis.</small>';

      Swal.fire({
        title: suspender ? 'Suspender contrato?' : 'Reativar contrato?',
        html: (suspender ? 'O contrato ficará com o status <strong>suspenso</strong>.' : 'O contrato voltará ao status <strong>vigente</strong>.') + contas,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: suspender ? '#d33' : '#3085d6',
        cancelButtonColor: '#ccc',
        cancelButtonText: 'Cancelar',
        confirmButtonText: suspender ? 'Suspender' : 'Reativar'
      }).then(function(result) {
        if (result.value) {
          $('#btn_status').prop('disabled', true).html('<span class="spinner-border spinner-border-sm align-middle me-1"></span> APLICANDO...');
          $('#form_status').submit();
        }
      });
    });

    // O encerramento fala com os painéis antes de gravar: sem o aviso, um
    // contrato com várias contas pareceria travado e convidaria ao segundo
    // clique.
    $('#encerrar_comments').on('input', function() {
      $('#encerrar_contador').text($(this).val().length);
    });

    $('#form_encerrar').on('submit', function() {
      $(this).find('button[type="submit"]')
        .prop('disabled', true)
        .html('<span class="spinner-border spinner-border-sm align-middle me-1"></span> ENCERRANDO...');
    });

    // Reabrir é CORREÇÃO de engano, não retorno de cliente (cliente que volta
    // gera contrato novo) — por isso o aviso diz de qual mês o contrato sai.
    $('#btn_reabrir').on('click', function() {
      Swal.fire({
        title: 'Reabrir contrato?',
        html: 'O contrato volta a <strong>vigente</strong> e o registro do encerramento é apagado.<br>' +
          '<strong>As contas suspensas no encerramento não são reativadas</strong> — o vínculo com o servidor foi desfeito.<br>' +
          'Ele sai da barra de saídas de <strong><?php echo $encerrado ? date('m/Y', strtotime($result->ended)) : ''; ?></strong> no Dashboard.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#ccc',
        cancelButtonText: 'Cancelar',
        confirmButtonText: 'Reabrir'
      }).then(function(result) {
        if (result.value) $('#form_reabrir').submit();
      });
    });

    $('#btn_excluir_contrato').on('click', function() {
      // O motivo é OPCIONAL e vai para o histórico, que sobrevive à exclusão
      // (a linha guarda o rótulo do contrato copiado). Sem ele, o registro diz
      // que o contrato foi apagado mas não por quê — e não há mais nada para
      // consultar depois.
      Swal.fire({
        title: 'Excluir contrato?',
        html: 'O contrato <strong>#<?php echo (int) $result->id; ?></strong> e seus documentos serão excluídos <strong>definitivamente</strong>.<br><small>Esta ação não pode ser desfeita. O registro da exclusão fica no histórico.</small>',
        icon: 'warning',
        input: 'textarea',
        inputLabel: 'Motivo da exclusão (opcional)',
        inputPlaceholder: 'Ex.: contrato lançado em duplicidade',
        inputAttributes: { maxlength: 500 },
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#ccc',
        cancelButtonText: 'Cancelar',
        confirmButtonText: 'Excluir'
      }).then(function(result) {
        // `result.value` é a string do input e vem VAZIA quando o usuário
        // confirma sem digitar nada — testar a verdade dela cancelaria a
        // exclusão de quem não quis dar motivo. Quem diz se foi confirmado é
        // `isConfirmed`.
        if (!result.isConfirmed) return;
        $('#campo_motivo_exclusao').val(result.value || '');
        $('#form_excluir_contrato').submit();
      });
    });

    // ----------------------------------------------------------------
    // Domínios: a busca é obrigatória antes de salvar — ela resolve o
    // vínculo automático com o domínio sincronizado do servidor.
    // ----------------------------------------------------------------
    var buscaDominioValida = false;
    var modoEdicaoDominio = false;

    // Cada busca invalida a consulta de registro anterior: sem isso, a resposta
    // de uma consulta lenta chegaria depois de o usuário já ter trocado o
    // domínio e preencheria o formulário com o vencimento do domínio errado.
    var whoisCadastroSeq = 0;

    function invalidarBuscaDominio() {
      if (modoEdicaoDominio) return;
      buscaDominioValida = false;
      whoisCadastroSeq++;
      $('#btn_salvar_dominio').prop('disabled', true);
      $('#dominio_id_server_domain').val('');
      $('#resultado_busca').html('');
      $('#resultado_whois').html('');
    }

    function avisoWhoisCadastro(classe, icone, texto) {
      $('#resultado_whois').html('<div class="alert ' + classe + ' mb-3"><div class="alert-message">' +
        '<i class="mdi ' + icone + '"></i> ' + texto + '</div></div>');
    }

    /**
     * Consulta o registro do domínio e preenche vencimento e local de registro.
     *
     * Roda depois da busca nos servidores, e nunca mexe no SALVAR: quem libera
     * o cadastro é a busca. Consulta indisponível só significa digitar à mão.
     */
    function consultarWhoisCadastro(dominio) {
      var seq = ++whoisCadastroSeq;

      avisoWhoisCadastro('alert-secondary', 'mdi-magnify',
        '<span class="spinner-border spinner-border-sm align-middle me-1"></span> Consultando o registro de <strong>' + esc(dominio) + '</strong>...');

      $.ajax({
        type: 'POST',
        url: '<?php echo base_url('contratos/json_postwhoiscadastro'); ?>',
        data: {
          id: <?php echo (int) $result->id; ?>,
          domain: dominio
        },
        dataType: 'json',
        success: function(data) {
          // Resposta de uma busca que não é mais a atual: descarta em silêncio.
          if (seq !== whoisCadastroSeq) return;
          if (sessaoExpirou(data)) return;

          var d = (data && data.data) ? data.data : {};

          if (!data || !data.return) {
            if (d.livre) {
              avisoWhoisCadastro('alert-info', 'mdi-information-outline',
                'O registro informou que <strong>' + esc(d.domain || dominio) + '</strong> não está registrado — nada a preencher.');
              return;
            }
            avisoWhoisCadastro('alert-warning', 'mdi-alert-outline',
              'Não foi possível consultar o registro: ' + esc((data && data.message) ? data.message : 'falha na consulta.') +
              ' Preencha o vencimento e o local de registro manualmente.');
            return;
          }

          var preenchidos = [];
          if (d.due_date) {
            $('#dominio_vencimento').val(d.due_date);
            preenchidos.push('vencimento em <strong>' + esc(d.due_date) + '</strong>');
          }
          if (d.registrar) {
            $('#dominio_registro').val(d.registrar);
            preenchidos.push('local de registro <strong>' + esc(d.registrar) + '</strong>');
          }

          if (!preenchidos.length) {
            avisoWhoisCadastro('alert-warning', 'mdi-alert-outline',
              'A consulta de <strong>' + esc(d.domain || dominio) + '</strong> não trouxe vencimento nem local de registro.');
            return;
          }

          avisoWhoisCadastro('alert-success', 'mdi-check-circle-outline',
            'Consulta de <strong>' + esc(d.domain || dominio) + '</strong>: ' + preenchidos.join(' e ') +
            '. <small>' + esc(data.message || '') + ' Você pode ajustar antes de salvar.</small>');
        },
        error: function(xhr) {
          if (seq !== whoisCadastroSeq) return;
          console.log(xhr.responseText);
          avisoWhoisCadastro('alert-warning', 'mdi-alert-outline',
            'Não foi possível consultar o registro do domínio. Preencha o vencimento e o local de registro manualmente.');
        }
      });
    }

    $('#dominio_nome').on('input', invalidarBuscaDominio);

    // Edição: reaproveita o modal, mas o DOMÍNIO fica travado (trocar o
    // domínio = excluir e recadastrar, passando pela busca de novo) e a
    // busca não é exigida — o vínculo existente não muda.
    $(document).on('click', '.js-editar-dominio', function() {
      var $btn = $(this);
      modoEdicaoDominio = true;
      $('#dominio_id_domain').val($btn.data('id'));
      $('#dominio_nome').val($btn.data('nome')).prop('readonly', true);
      $('#dominio_vencimento').val($btn.data('vencimento'));
      $('#dominio_registro').val($btn.data('registro'));
      $('#dominio_gerenciado').prop('checked', String($btn.data('gerenciado')) === '1');
      $('#dominio_observacoes').val($btn.data('observacoes'));
      $('#btn_buscar_dominio').hide();
      $('#dominio_dica').hide();
      $('#resultado_busca').html('');
      // Sem busca não há consulta de registro: na edição o domínio não muda, e
      // uma resposta em voo da abertura anterior não pode cair aqui.
      whoisCadastroSeq++;
      $('#resultado_whois').html('');
      $('#btn_salvar_dominio').prop('disabled', false);
      $('#modal_dominio_titulo').text('EDITAR DOMÍNIO');
      $('#modal_dominio').modal('show');
    });

    $('#btn_buscar_dominio').on('click', function() {
      var dominio = $('#dominio_nome').val().trim();
      if (!dominio) {
        Swal.fire('Atenção', 'Informe o domínio para buscar.', 'warning');
        return;
      }

      var $btn = $(this);
      $btn.prop('disabled', true);

      $.ajax({
        type: 'POST',
        url: '<?php echo base_url('contratos/json_postbuscardominio'); ?>',
        data: {
          id: <?php echo (int) $result->id; ?>,
          domain: dominio
        },
        dataType: 'json',
        success: function(data) {
          if (sessaoExpirou(data)) return;
          if (!data || !data.return) {
            notificar('error', (data && data.message) ? data.message : 'Erro ao buscar o domínio.');
            return;
          }

          var d = data.data;
          $('#dominio_nome').val(d.domain);

          // Bloqueio só quando não sobrou servidor para vincular: o mesmo
          // domínio pode voltar aqui de propósito, desde que seja para outro
          // servidor (site num painel, e-mail em outro).
          if (d.bloqueado) {
            // Explícito, e não só um `return`: uma busca anterior pode ter
            // deixado o SALVAR liberado, e ele precisa voltar a travar.
            buscaDominioValida = false;
            $('#btn_salvar_dominio').prop('disabled', true);
            $('#dominio_id_server_domain').val('');
            $('#resultado_busca').html('<div class="alert alert-danger mb-3"><div class="alert-message"><i class="mdi mdi-alert"></i> ' + esc(d.motivo) + '</div></div>');
            return;
          }

          // Os servidores que este contrato já usa para este domínio saem da
          // escolha, mas ficam à vista — sem isso a lista de opções encolhe
          // sem explicação quando parte já foi cadastrada.
          var html = '';
          if (d.ja_vinculados && d.ja_vinculados.length) {
            var nomes = $.map(d.ja_vinculados, function(m) {
              return esc(m.server_name);
            }).join(', ');
            html += '<div class="alert alert-secondary mb-3"><div class="alert-message"><i class="mdi mdi-information-outline"></i> Este domínio já está neste contrato em: <strong>' + nomes + '</strong>. Abaixo, só o que ainda pode ser vinculado.</div></div>';
          }

          if (!d.matches.length) {
            html += '<div class="alert alert-warning mb-3"><div class="alert-message"><i class="mdi mdi-link-off"></i> Nenhuma correspondência nos servidores — o domínio será salvo <strong>sem vínculo</strong>.</div></div>';
            $('#dominio_id_server_domain').val('');
          } else if (d.matches.length === 1) {
            var m = d.matches[0];
            html += '<div class="alert alert-success mb-3"><div class="alert-message"><i class="mdi mdi-link-variant"></i> Correspondência encontrada: <strong>' + esc(m.domain) + '</strong> no servidor <strong>' + esc(m.server_name) + '</strong> (' + esc(m.status) + '). O vínculo será feito automaticamente.</div></div>';
            $('#dominio_id_server_domain').val(m.id);
          } else {
            html += '<div class="alert alert-info mb-3"><div class="alert-message"><i class="mdi mdi-link-variant"></i> ' + d.matches.length + ' correspondências — escolha o servidor do vínculo:';
            $.each(d.matches, function(i, m) {
              html += '<div class="form-check mt-1"><input class="form-check-input js-match-dominio" type="radio" name="match_dominio" id="match_dominio_' + m.id + '" value="' + m.id + '"' + (i === 0 ? ' checked' : '') + '>' +
                '<label class="form-check-label" for="match_dominio_' + m.id + '"><strong>' + esc(m.domain) + '</strong> — ' + esc(m.server_name) + ' (' + esc(m.status) + ')</label></div>';
            });
            html += '</div></div>';
            $('#dominio_id_server_domain').val(d.matches[0].id);
          }

          $('#resultado_busca').html(html);

          buscaDominioValida = true;
          $('#btn_salvar_dominio').prop('disabled', false);

          // Só agora, e em requisição própria: o vínculo já está resolvido e o
          // SALVAR liberado, então a consulta ao registro (que sai para fora)
          // pode demorar sem segurar o cadastro.
          consultarWhoisCadastro(d.domain);
        },
        error: function(xhr) {
          console.log(xhr.responseText);
          notificar('error', 'Erro ao buscar o domínio.');
        },
        complete: function() {
          $btn.prop('disabled', false);
        }
      });
    });

    $(document).on('change', '.js-match-dominio', function() {
      $('#dominio_id_server_domain').val($(this).val());
    });

    $('#form_dominio').on('submit', function(e) {
      if (!modoEdicaoDominio && !buscaDominioValida) {
        e.preventDefault();
        Swal.fire('Atenção', 'Clique em BUSCAR antes de salvar — a busca resolve o vínculo com os servidores.', 'warning');
        return false;
      }
    });

    $('#modal_dominio').on('hidden.bs.modal', function() {
      $('#form_dominio')[0].reset();
      modoEdicaoDominio = false;
      $('#dominio_id_domain').val('');
      $('#dominio_nome').prop('readonly', false);
      $('#btn_buscar_dominio').show();
      $('#dominio_dica').show();
      $('#modal_dominio_titulo').text('ADICIONAR DOMÍNIO');
      invalidarBuscaDominio();
    });

    $(document).on('click', '.js-excluir-dominio', function() {
      var $btn = $(this);
      var id = $btn.data('id');
      var nome = $btn.data('nome');
      // O mesmo nome pode estar duas vezes na tabela (um cadastro por conta de
      // servidor) — sem o servidor na pergunta, as duas linhas ficam iguais.
      var servidor = $btn.data('servidor');

      Swal.fire({
        title: 'Excluir domínio?',
        html: 'O domínio <strong>' + esc(nome) + '</strong>' + (servidor ? ' vinculado a <strong>' + esc(servidor) + '</strong>' : ' <em>(sem vínculo)</em>') + ' será removido deste contrato.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#ccc',
        cancelButtonText: 'Cancelar',
        confirmButtonText: 'Excluir'
      }).then(function(result) {
        if (!result.value) return;

        $btn.prop('disabled', true);

        $.ajax({
          type: 'POST',
          url: '<?php echo base_url('contratos/json_postdeletedominio'); ?>',
          data: {
            id: id
          },
          dataType: 'json',
          success: function(data) {
            if (sessaoExpirou(data)) return;
            if (!data || !data.return) {
              notificar('error', (data && data.message) ? data.message : 'Erro ao excluir o domínio.');
              return;
            }
            // Recarrega: a barra de uso e o contador dependem do servidor.
            window.location.reload();
          },
          error: function(xhr) {
            console.log(xhr.responseText);
            notificar('error', 'Erro ao excluir o domínio.');
          },
          complete: function() {
            $btn.prop('disabled', false);
          }
        });
      });
    });

    // ----- cota da conta -----
    // O id fica no escopo do bloco: o modal é preenchido por AJAX e o SALVAR
    // precisa saber sobre qual linha está agindo sem reler o DOM.
    var quotaIdDominio = 0;
    var quotaSeq = 0;

    function quotaGb(mb) {
      if (mb === null || mb === undefined) return 'Ilimitado';
      return (mb / 1024).toFixed(2).replace('.', ',') + ' Gb';
    }

    $(document).on('click', '.js-quota-dominio', function() {
      var $btn = $(this);
      quotaIdDominio = $btn.data('id');

      // Estado inicial a cada abertura: sem isso o modal mostraria por um
      // instante os dados da conta aberta antes desta.
      var seq = ++quotaSeq;
      $('#quota_carregando').removeClass('d-none');
      $('#quota_conteudo').addClass('d-none');
      $('#quota_incompativel').addClass('d-none');
      $('#quota_formulario').removeClass('d-none');
      $('#btn_salvar_quota').prop('disabled', true);
      $('#quota_gb').val('');
      $('#quota_ilimitado').prop('checked', false);

      $('#modal_quota_conta').modal('show');

      $.ajax({
        type: 'POST',
        url: '<?php echo base_url('contratos/json_postquotaconta'); ?>',
        data: {
          id: quotaIdDominio
        },
        dataType: 'json',
        success: function(data) {
          if (sessaoExpirou(data)) return;
          // Cliques em duas linhas diferentes: a resposta lenta não pode
          // sobrescrever a conta que o usuário está vendo agora.
          if (seq !== quotaSeq) return;

          if (!data || !data.return) {
            $('#modal_quota_conta').modal('hide');
            notificar('error', (data && data.message) ? data.message : 'Erro ao carregar os dados da conta.');
            return;
          }

          var d = data.data;

          $('#quota_carregando').addClass('d-none');
          $('#quota_conteudo').removeClass('d-none');
          $('#quota_dominio').text(d.domain);

          if (!d.vinculado) {
            $('#quota_conta_info').text('');
            $('#quota_uso').text('—');
            $('#quota_atual').text('—');
            $('#quota_motivo').text(d.motivo);
            $('#quota_incompativel').removeClass('d-none');
            $('#quota_formulario').addClass('d-none');
            return;
          }

          $('#quota_conta_info').text(
            d.server_name + ' · conta ' + (d.owner_username || '(não informada)') +
            (d.last_sync ? ' · sincronizado em ' + d.last_sync : '')
          );
          $('#quota_uso').text(d.disk_used_mb === null ? '—' : quotaGb(d.disk_used_mb));
          $('#quota_atual').text(quotaGb(d.disk_limit_mb));

          if (!d.compativel) {
            $('#quota_motivo').text(d.motivo);
            $('#quota_incompativel').removeClass('d-none');
            $('#quota_formulario').addClass('d-none');
            return;
          }

          if (d.encerrado) {
            $('#quota_motivo').text('Contrato encerrado não pode ter a cota alterada — reabra o contrato antes.');
            $('#quota_incompativel').removeClass('d-none');
            $('#quota_formulario').addClass('d-none');
            return;
          }

          // O contratado é referência, nunca valor sugerido: o contrato pode ter
          // várias contas, e preencher o campo com ele levaria a atribuir o
          // espaço inteiro a cada uma.
          $('#quota_referencia').text(
            d.space_gb > 0
              ? 'Espaço contratado no contrato: ' + d.space_gb.toFixed(2).replace('.', ',') + ' Gb (referência — a cota da conta pode ser menor).'
              : 'Este contrato não tem espaço contratado definido.'
          );
          $('#quota_gb').val(d.disk_limit_gb);
          $('#quota_ilimitado').prop('checked', d.disk_limit_mb === null);
          $('#quota_gb').prop('disabled', d.disk_limit_mb === null);
          $('#btn_salvar_quota').prop('disabled', false);
        },
        error: function(xhr) {
          console.log(xhr.responseText);
          if (seq !== quotaSeq) return;
          $('#modal_quota_conta').modal('hide');
          notificar('error', 'Erro ao carregar os dados da conta.');
        }
      });
    });

    $(document).on('change', '#quota_ilimitado', function() {
      $('#quota_gb').prop('disabled', $(this).is(':checked'));
    });

    $(document).on('click', '#btn_salvar_quota', function() {
      var $btn = $(this);
      var ilimitado = $('#quota_ilimitado').is(':checked');
      var gb = $('#quota_gb').val();
      var conta = $('#quota_conta_info').text();

      Swal.fire({
        title: 'Alterar a cota?',
        // Nomeia a CONTA, não o domínio: no WHM e no DirectAdmin a cota vale
        // para o usuário inteiro, e a mudança atinge todos os domínios dele.
        html: 'A capacidade da conta passará a ser <strong>' + (ilimitado ? 'ilimitada' : esc(gb) + ' Gb') + '</strong>.' +
          '<br><br><small class="text-muted">' + esc(conta) + '</small>' +
          '<br><small>A alteração vale para a conta inteira no painel e passa a valer imediatamente.</small>',
        icon: 'warning',
        showCancelButton: true,
        cancelButtonColor: '#ccc',
        cancelButtonText: 'Cancelar',
        confirmButtonText: 'Alterar'
      }).then(function(result) {
        if (!result.value) return;

        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span> APLICANDO...');

        $.ajax({
          type: 'POST',
          url: '<?php echo base_url('contratos/json_postsalvarquota'); ?>',
          data: {
            id: quotaIdDominio,
            quota_gb: gb,
            unlimited: ilimitado ? 'S' : 'N'
          },
          dataType: 'json',
          success: function(data) {
            if (sessaoExpirou(data)) return;
            if (!data || !data.return) {
              notificar('error', (data && data.message) ? data.message : 'Erro ao alterar a cota.');
              $btn.prop('disabled', false).html('<i class="mdi mdi-content-save"></i> SALVAR');
              return;
            }
            // Recarrega para a coluna de uso e o retrato da conta virem do banco
            // já atualizado, em vez de a tela afirmar um número que só existe no
            // navegador.
            window.location.reload();
          },
          error: function(xhr) {
            console.log(xhr.responseText);
            notificar('error', 'Erro ao alterar a cota.');
            $btn.prop('disabled', false).html('<i class="mdi mdi-content-save"></i> SALVAR');
          }
        });
      });
    });

    $(document).on('click', '.js-excluir-documento', function() {
      var $btn = $(this);
      var id = $btn.data('id');
      var nome = $btn.data('nome');

      Swal.fire({
        title: 'Excluir documento?',
        html: 'O documento <strong>' + nome + '</strong> será excluído definitivamente.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#ccc',
        cancelButtonText: 'Cancelar',
        confirmButtonText: 'Excluir'
      }).then(function(result) {
        if (!result.value) return;

        $btn.prop('disabled', true);

        $.ajax({
          type: 'POST',
          url: '<?php echo base_url('contratos/json_postdeletefile'); ?>',
          data: {
            id: id
          },
          dataType: 'json',
          success: function(data) {
            if (sessaoExpirou(data)) return;
            if (!data || !data.return) {
              notificar('error', (data && data.message) ? data.message : 'Erro ao excluir o documento.');
              return;
            }
            $('tr[data-documento="' + id + '"]').remove();
            notificar('success', data.message || 'Documento excluído com sucesso.');
          },
          error: function(xhr) {
            console.log(xhr.responseText);
            notificar('error', 'Erro ao excluir o documento.');
          },
          complete: function() {
            $btn.prop('disabled', false);
          }
        });
      });
    });

    // ------------------------------------------------------------------
    // Extrato Bom Controle
    // ------------------------------------------------------------------
    var bcVinculado = <?php echo !empty($result->bomcontrole_contract_id) ? 'true' : 'false'; ?>;
    var bcAtivo = <?php echo !empty($bomcontrole_ativo) ? 'true' : 'false'; ?>;
    var extratoCarregado = false;

    // Tudo que vem da API entra no HTML escapado — links inclusive.
    function esc(texto) {
      return $('<span></span>').text(texto == null ? '' : String(texto)).html();
    }

    function dataBr(iso) {
      if (!iso) return '—';
      var p = String(iso).split('-');
      return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : '—';
    }

    function moedaBr(valor) {
      return 'R$ ' + Number(valor || 0).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    }

    function linhaExtrato(item) {
      var badges = {
        quitado: 'bg-success',
        vencido: 'bg-danger',
        em_aberto: 'bg-warning'
      };
      var badge = badges[item.status] || 'bg-secondary';

      var nfse = item.link_nota_fiscal ?
        '<a class="btn btn-outline-primary btn-sm" href="' + esc(item.link_nota_fiscal) + '" target="_blank" rel="noopener">ABRIR</a>' :
        '<span class="text-muted">—</span>';
      var boleto = item.link_boleto ?
        '<a class="btn btn-outline-primary btn-sm" href="' + esc(item.link_boleto) + '" target="_blank" rel="noopener">ABRIR</a>' :
        '<span class="text-muted">—</span>';

      return '<tr>' +
        '<td class="text-center"><span class="badge w-100 ' + badge + '">' + esc(item.status_rotulo) + '</span></td>' +
        '<td class="text-center">' + dataBr(item.emissao) + '</td>' +
        '<td class="text-center">' + dataBr(item.vencimento) + '</td>' +
        '<td class="text-center">' + (item.dias_vencido > 0 ? esc(item.dias_vencido) : '—') + '</td>' +
        '<td class="text-end text-nowrap">' + moedaBr(item.valor) + '</td>' +
        '<td>' + (item.forma_pagamento ? esc(item.forma_pagamento) : '—') + '</td>' +
        '<td class="text-center">' + (item.parcela != null ? esc(item.parcela) : '—') + '</td>' +
        '<td class="text-center">' + dataBr(item.data_pagamento) + '</td>' +
        '<td class="text-center">' + nfse + '</td>' +
        '<td class="text-center">' + boleto + '</td>' +
        '</tr>';
    }

    function renderExtrato(data) {
      var $saida = $('#extrato_conteudo');

      if (!data.itens || !data.itens.length) {
        $saida.html('<div class="alert alert-secondary mb-0"><div class="alert-message">Nenhuma fatura no período consultado (pagas dos últimos <?php echo Bomcontrole_model::MESES_PAGAS; ?> meses e em aberto até <?php echo Bomcontrole_model::DIAS_FUTURO; ?> dias à frente).</div></div>');
        return;
      }

      var html = '<div class="table-responsive"><table class="table table-sm table-striped table-bordered table-hover mb-0"><thead><tr>' +
        '<th class="text-center" style="min-width:95px;">Status</th>' +
        '<th class="text-center">Emissão</th>' +
        '<th class="text-center">Vencimento</th>' +
        '<th class="text-center">Dias vencido</th>' +
        '<th class="text-end">Valor</th>' +
        '<th>Forma pgto</th>' +
        '<th class="text-center">Parcela</th>' +
        '<th class="text-center">Data pgto</th>' +
        '<th class="text-center">NFS-e</th>' +
        '<th class="text-center">Boleto</th>' +
        '</tr></thead><tbody>';
      data.itens.forEach(function(item) {
        html += linhaExtrato(item);
      });
      html += '</tbody></table></div>';

      if (data.aviso_pagas) {
        html += '<p class="text-muted mt-2 mb-0"><small>As parcelas pagas vêm do financeiro do CLIENTE no Bom Controle — se ele tiver outros contratos por lá, parcelas de outros contratos podem aparecer aqui.</small></p>';
      }

      $saida.html(html);
    }

    function carregarExtrato(forcar) {
      if (!bcVinculado || !bcAtivo) return;
      if (extratoCarregado && !forcar) return;
      extratoCarregado = true;

      var $saida = $('#extrato_conteudo');
      $saida.html('<div class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Consultando o Bom Controle...</div>');

      $.ajax({
        type: 'POST',
        url: '<?php echo base_url('contratos/json_postextratobc'); ?>',
        data: {
          id: <?php echo (int) $result->id; ?>
        },
        dataType: 'json',
        success: function(data) {
          if (sessaoExpirou(data)) return;
          if (!data || !data.return || !data.data) {
            $saida.html($('<div class="alert alert-danger mb-0"><div class="alert-message"></div></div>')
              .find('.alert-message').text((data && data.message) ? data.message : 'Erro ao consultar o extrato.').end());
            extratoCarregado = false; // deixa tentar de novo
            return;
          }
          renderExtrato(data.data);
        },
        error: function(xhr) {
          console.log(xhr.responseText);
          $saida.html('<div class="alert alert-danger mb-0"><div class="alert-message">Erro de comunicação ao consultar o extrato.</div></div>');
          extratoCarregado = false;
        }
      });
    }

    // Carga lazy: um AJAX na primeira abertura da aba; ATUALIZAR força recarga.
    $('a[href="#tab_extrato"]').on('shown.bs.tab', function() {
      carregarExtrato(false);
    });

    $('#btn_atualizar_extrato').on('click', function() {
      carregarExtrato(true);
    });

    // ------------------------------------------------------------------
    // Faturas do CDW Finance — paginadas no servidor
    // ------------------------------------------------------------------
    // Reusa esc/dataBr/moedaBr do bloco acima; a página corrente vive aqui e
    // não na URL, porque a aba é um pedaço da tela do contrato — recarregar
    // por querystring perderia a aba aberta.
    var faturasPagina = 1;
    var faturasCarregado = false;
    var faturasCarregando = false;

    var badgeSituacao = {
      paga: 'bg-success',
      vencida: 'bg-danger',
      a_vencer: 'bg-primary',
      cancelada: 'bg-secondary'
    };

    // Estado do REGISTRO da cobrança (crm_invoices_v.registration). Pergunta
    // diferente da `situation`: uma pode estar "a vencer" e sem boleto nenhum,
    // e é esse o caso que ninguém vê até o cliente reclamar.
    var badgeRegistro = {
      sem_psp: 'bg-light text-dark border',
      nao_registrada: 'bg-secondary',
      // "registrando" é estado NORMAL da emissão assíncrona, não falha — daí
      // azul, e não amarelo de alerta.
      registrando: 'bg-info text-dark',
      registrada: 'bg-success'
    };

    // Há provedor ativo? Sem isso o botão abriria um modal com select vazio.
    var pspTemOpcoes = <?php echo !empty($psp_disponiveis) ? 'true' : 'false'; ?>;

    function registroRotulo(item, rotulos) {
      var reg = String(item.registration || '');
      var classe = badgeRegistro[reg] || 'bg-secondary';
      var texto = (rotulos && rotulos[reg]) ? rotulos[reg] : reg;

      var html = '<span class="badge ' + classe + '">' + esc(texto) + '</span>';

      // A ação vive AQUI, junto do estado que ela resolve — e não numa coluna
      // à parte: quem vê "não registrada" quer agir na mesma linha do olho.
      //
      // Só aparece onde há o que resolver. Cobrança REGISTRADA não oferece
      // troca: o boleto está de pé e o cliente pode já tê-lo recebido, então
      // trocar significa cancelar no banco e emitir outro — operação de
      // exceção, que não merece um atalho a um clique. `registrando` mostra só
      // ATUALIZAR, porque a cobrança existe e falta o boleto (emissão
      // assíncrona): trocar de provedor ali cancelaria uma cobrança boa por
      // impaciência.
      var acao = '';
      if (pspTemOpcoes && String(item.status || '') === 'aberta') {
        if (reg === 'nao_registrada' || reg === 'sem_psp') acao = 'registrar / trocar';
        else if (reg === 'registrando') acao = 'atualizar';
      }

      if (acao !== '') {
        html += '<br /><button type="button" class="btn btn-sm btn-link p-0 btn-trocar-psp-aba"' +
          ' data-id="' + esc(item.id) + '"' +
          ' data-psp="' + esc(item.psp || '') + '"' +
          ' data-cobranca="' + (reg === 'nao_registrada' || reg === 'sem_psp' ? '0' : '1') + '">' +
          acao + '</button>';
      }

      return html;
    }

    // O PDF só existe depois de a cobrança estar registrada: em "registrando"
    // o banco ainda não gerou o arquivo, e o botão levaria a um erro que o
    // próprio estado já explica.
    function boletoBotao(item) {
      if (String(item.registration || '') !== 'registrada') {
        return '<span class="text-muted">—</span>';
      }

      return '<button type="button" class="btn btn-sm btn-outline-danger btn-boleto"' +
        ' data-id="' + esc(item.id) + '" title="Abrir o boleto">' +
        '<i class="mdi mdi-file-pdf-box"></i></button>';
    }

    // Só CANCELAR: a baixa é automática (webhook + conciliação) e o atalho para
    // o contrato não faz sentido aqui — nesta aba já se está nele, ou a um
    // clique dele. O botão existe nas três telas para a ação não depender de
    // por onde o usuário chegou na fatura.
    function acoesFatura(item) {
      if (String(item.status || '') !== 'aberta') {
        return '<span class="text-muted">—</span>';
      }

      return '<button type="button" class="btn btn-sm btn-outline-danger btn-cancelar-fatura"' +
        ' data-id="' + esc(item.id) + '" title="Cancelar a fatura">' +
        '<i class="mdi mdi-close"></i></button>';
    }
    var registrosRotulos = {};

    function linhaFatura(item, rotulos) {
      var sit = String(item.situation || '');
      var badge = badgeSituacao[sit] || 'bg-secondary';
      var rotulo = rotulos[sit] || sit;
      var competencia = String(item.competence || '').split('-');
      competencia = competencia.length === 3 ? competencia[1] + '/' + competencia[0] : '—';

      return '<tr>' +
        '<td class="text-center">' + competencia + '</td>' +
        '<td class="text-center">' + parcelaRotulo(item) + '</td>' +
        '<td class="text-center">' + dataBr(item.due_date) + '</td>' +
        '<td class="text-end">' + moedaBr(item.value) + '</td>' +
        '<td class="text-center"><span class="badge ' + badge + '">' + esc(rotulo) + '</span></td>' +
        '<td class="text-center">' + registroRotulo(item, registrosRotulos) + '</td>' +
        '<td class="text-center">' + boletoBotao(item) + '</td>' +
        '<td><small>' + esc(item.description) + origemRotulo(item) + '</small></td>' +
        '<td class="text-center">' + acoesFatura(item) + '</td>' +
        '</tr>';
    }

    // Sem a parcela, duas linhas de R$ 250 no mesmo mês ficam indistinguíveis.
    // "1/1" vira travessão: repetir isso em toda linha de contrato mensal é
    // ruído que esconde as poucas linhas que de fato são parceladas.
    function parcelaRotulo(item) {
      var total = parseInt(item.installments_total, 10) || 1;
      if (total <= 1) return '<span class="text-muted">—</span>';
      return esc(item.installment_number) + '/' + esc(total);
    }

    // A avulsa não tem competência que a distinga da recorrência — as duas
    // caem no mesmo mês. O marcador é o que diz de onde a linha veio.
    function origemRotulo(item) {
      if (!item.id_charge || parseInt(item.id_charge, 10) === 0) return '';
      return ' <span class="badge bg-light text-dark border">avulsa</span>';
    }

    function renderFaturas(data) {
      var $saida = $('#faturas_conteudo');
      var rotulos = data.situations || {};
      registrosRotulos = data.registrations || {};

      if (!data.total) {
        var dica = data.fatura_aqui ?
          'Nenhuma fatura gerada ainda. Use GERAR FATURA no bloco Faturamento ou espere a rotina diária.' :
          'Este contrato é cobrado pelo Bom Controle. Passe o faturamento para o CDW Finance no bloco Faturamento para gerar faturas aqui.';

        $saida.html('<div class="text-center text-muted py-5">' +
          '<i class="mdi mdi-receipt-text-outline fs-1 d-block mb-2"></i>' +
          '<h5 class="mb-1">Nenhuma fatura</h5>' +
          '<p class="mb-0">' + dica + '</p></div>');
        return;
      }

      var html = '<div class="table-responsive"><table class="table table-sm table-striped table-bordered table-hover mb-2">' +
        '<thead><tr>' +
        '<th class="text-center">Competência</th>' +
        '<th class="text-center">Parcela</th>' +
        '<th class="text-center">Vencimento</th>' +
        '<th class="text-end">Valor</th>' +
        '<th class="text-center">Situação</th>' +
        '<th class="text-center">Registro</th>' +
        '<th class="text-center">Boleto</th>' +
        '<th>Descrição</th>' +
        '<th class="text-center">Ações</th>' +
        '</tr></thead><tbody>';

      $.each(data.itens || [], function(i, item) {
        html += linhaFatura(item, rotulos);
      });

      html += '</tbody></table></div>';
      html += rodapeFaturas(data);

      $saida.html(html);
    }

    // Anterior/Próxima em vez de páginas numeradas: a ordem é do vencimento
    // mais recente para o mais antigo, então o que interessa está sempre no
    // começo — e o total fica escrito, para a lista não parecer truncada.
    function rodapeFaturas(data) {
      var resumo = '<span class="text-muted">' + data.total + ' fatura(s) · ' +
        moedaBr(data.valor_total) + ' no total';

      if (data.valor_aberto > 0) {
        resumo += ' · <strong>' + moedaBr(data.valor_aberto) + ' em aberto</strong>';
      }
      if (data.valor_vencido > 0) {
        resumo += ' · <span class="text-danger">' + moedaBr(data.valor_vencido) + ' vencido</span>';
      }
      resumo += '</span>';

      var nav = '';
      if (data.paginas > 1) {
        nav = '<div class="btn-group btn-group-sm" role="group">' +
          '<button type="button" class="btn btn-outline-secondary btn-faturas-pag" data-pagina="' + (data.pagina - 1) + '"' +
          (data.pagina <= 1 ? ' disabled' : '') + '><i class="mdi mdi-chevron-left"></i> ANTERIOR</button>' +
          '<button type="button" class="btn btn-outline-secondary" disabled>' + data.pagina + ' / ' + data.paginas + '</button>' +
          '<button type="button" class="btn btn-outline-secondary btn-faturas-pag" data-pagina="' + (data.pagina + 1) + '"' +
          (data.pagina >= data.paginas ? ' disabled' : '') + '>PRÓXIMA <i class="mdi mdi-chevron-right"></i></button>' +
          '</div>';
      }

      return '<div class="row align-items-center"><div class="col"><small>' + resumo + '</small></div>' +
        '<div class="col-auto">' + nav + '</div></div>';
    }

    function carregarFaturas(pagina) {
      // Sem guarda, clicar PRÓXIMA duas vezes rápido deixaria a resposta mais
      // lenta chegar por último e a tela mostraria a página errada.
      if (faturasCarregando) return;
      faturasCarregando = true;

      var $saida = $('#faturas_conteudo');
      $saida.html('<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>');

      $.ajax({
        url: '<?php echo base_url('contratos/json_postfaturas'); ?>',
        type: 'POST',
        dataType: 'json',
        data: {
          id: <?php echo (int) $result->id; ?>,
          pagina: pagina
        },
        success: function(data) {
          if (!data || !data.success) {
            $saida.html($('<div class="alert alert-danger mb-0"><div class="alert-message"></div></div>')
              .find('.alert-message').text((data && data.message) ? data.message : 'Erro ao consultar as faturas.').end());
            faturasCarregado = false; // deixa tentar de novo
            return;
          }
          faturasPagina = data.data.pagina;
          faturasCarregado = true;
          renderFaturas(data.data);
        },
        error: function() {
          $saida.html('<div class="alert alert-danger mb-0"><div class="alert-message">Erro de comunicação ao consultar as faturas.</div></div>');
          faturasCarregado = false;
        },
        complete: function() {
          faturasCarregando = false;
        }
      });
    }

    $('a[href="#tab_faturas"]').on('shown.bs.tab', function() {
      if (!faturasCarregado) carregarFaturas(faturasPagina);
    });

    $('#btn_atualizar_faturas').on('click', function() {
      carregarFaturas(faturasPagina);
    });

    // Delegado: os botões são recriados a cada render.
    $('#faturas_conteudo').on('click', '.btn-faturas-pag', function() {
      carregarFaturas(parseInt($(this).data('pagina'), 10) || 1);
    });

    // --- vínculo ---
    var candidatoSelecionado = null;

    function linhaCandidato(c) {
      var alerta = c.ja_vinculado_ao_contrato ?
        '<br><small class="text-danger">Já vinculado ao contrato #' + esc(c.ja_vinculado_ao_contrato) + ' — vincular aqui deixará dois contratos apontando para o mesmo contrato BC.</small>' : '';
      var obs = c.observacao ? '<br><small class="text-muted">' + esc(c.observacao) + '</small>' : '';
      var encerrado = c.encerrado ? ' <span class="badge bg-secondary">Encerrado</span>' : '';
      var tipo = c.tipo ? '<br><small class="text-muted">' + esc(c.tipo) + '</small>' : '';
      var faturaRecente = c.fatura_recente ?
        moedaBr(c.fatura_recente.valor) + '<br><small class="text-muted">venc. ' + dataBr(c.fatura_recente.vencimento) + '</small>' :
        '<span class="text-muted">—</span>';

      return '<tr>' +
        '<td class="text-center"><input class="form-check-input js-candidato-bc" type="radio" name="candidato_bc" value="' + esc(c.id) + '"></td>' +
        '<td>#' + esc(c.id) + encerrado + tipo + obs + alerta + '</td>' +
        '<td>' + esc(c.cliente_nome) + '</td>' +
        '<td class="text-center">' + dataBr(c.inicio) + '</td>' +
        '<td class="text-end text-nowrap">' + moedaBr(c.valor) + '</td>' +
        '<td class="text-end text-nowrap">' + faturaRecente + '</td>' +
        '</tr>';
    }

    $('#btn_vincular_bc').on('click', function() {
      var $btn = $(this);
      $btn.prop('disabled', true);

      $.ajax({
        type: 'POST',
        url: '<?php echo base_url('contratos/json_postbuscarbc'); ?>',
        data: {
          id: <?php echo (int) $result->id; ?>
        },
        dataType: 'json',
        success: function(data) {
          if (sessaoExpirou(data)) return;
          if (!data || !data.return || !data.data) {
            notificar('error', (data && data.message) ? data.message : 'Erro ao buscar contratos no Bom Controle.');
            return;
          }
          if (!data.data.candidatos.length) {
            Swal.fire('Nenhum contrato encontrado', 'O Bom Controle não tem contrato para o documento ' + data.data.documento + '.', 'info');
            return;
          }

          candidatoSelecionado = null;
          $('#btn_confirmar_vinculo_bc').prop('disabled', true);
          $('#vinculo_bc_documento').text('Contratos do documento ' + data.data.documento + ' no Bom Controle — selecione o correspondente:');
          var html = '';
          data.data.candidatos.forEach(function(c) {
            html += linhaCandidato(c);
          });
          $('#vinculo_bc_lista').html(html);
          $('#modal_vinculo_bc').modal('show');
        },
        error: function(xhr) {
          console.log(xhr.responseText);
          notificar('error', 'Erro ao buscar contratos no Bom Controle.');
        },
        complete: function() {
          $btn.prop('disabled', false);
        }
      });
    });

    $(document).on('change', '.js-candidato-bc', function() {
      candidatoSelecionado = parseInt($(this).val(), 10) || null;
      $('#btn_confirmar_vinculo_bc').prop('disabled', !candidatoSelecionado);
    });

    $('#btn_confirmar_vinculo_bc').on('click', function() {
      if (!candidatoSelecionado) return;
      var $btn = $(this);
      $btn.prop('disabled', true);

      $.ajax({
        type: 'POST',
        url: '<?php echo base_url('contratos/json_postvincularbc'); ?>',
        data: {
          id: <?php echo (int) $result->id; ?>,
          id_bc: candidatoSelecionado
        },
        dataType: 'json',
        success: function(data) {
          if (sessaoExpirou(data)) return;
          if (!data || !data.return) {
            notificar('error', (data && data.message) ? data.message : 'Erro ao vincular.');
            $btn.prop('disabled', false);
            return;
          }
          notificar('success', data.message || 'Contrato vinculado.');
          // O cabeçalho da aba muda de estado — recarregar é o caminho
          // simples e consistente com os POSTs da tela.
          window.location.reload();
        },
        error: function(xhr) {
          console.log(xhr.responseText);
          notificar('error', 'Erro ao vincular.');
          $btn.prop('disabled', false);
        }
      });
    });

    $('#btn_desvincular_bc').on('click', function() {
      Swal.fire({
        title: 'Desvincular do Bom Controle?',
        html: 'O extrato deixa de ser consultado para este contrato. O contrato no Bom Controle não é alterado.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#ccc',
        cancelButtonText: 'Cancelar',
        confirmButtonText: 'Desvincular'
      }).then(function(result) {
        if (!result.value) return;

        $.ajax({
          type: 'POST',
          url: '<?php echo base_url('contratos/json_postdesvincularbc'); ?>',
          data: {
            id: <?php echo (int) $result->id; ?>
          },
          dataType: 'json',
          success: function(data) {
            if (sessaoExpirou(data)) return;
            if (!data || !data.return) {
              notificar('error', (data && data.message) ? data.message : 'Erro ao desvincular.');
              return;
            }
            notificar('success', data.message || 'Vínculo removido.');
            window.location.reload();
          },
          error: function(xhr) {
            console.log(xhr.responseText);
            notificar('error', 'Erro ao desvincular.');
          }
        });
      });
    });

    // ----------------------------------------------------------------
    // Faturamento
    // ----------------------------------------------------------------

    // Os campos só fazem sentido quando o faturamento é daqui; escondê-los
    // evita que alguém preencha um dia de vencimento que o ERP ignora.
    function alternarBlocosFaturamento() {
      var daqui = $('#billing_source').val() === 'cdwfinance';
      $('.bloco-cdw').toggle(daqui);
      if (daqui) alternarBlocoReajuste();
    }

    function alternarBlocoReajuste() {
      var temIndice = $('#adjustment_index').val() !== 'nenhum';
      $('.bloco-reajuste').toggle(temIndice);
    }

    if ($('#billing_source').length) {
      alternarBlocosFaturamento();
      $('#billing_source').on('change', alternarBlocosFaturamento);
      $('#adjustment_index').on('change', alternarBlocoReajuste);
    }

    // ------------------------------------------------------------------
    // Notificações ao cliente — repeaters de e-mail e WhatsApp
    // ------------------------------------------------------------------
    // Clonar a última linha em vez de montar o HTML em string: o markup vive
    // num lugar só (o PHP), então mudar um campo não exige mexer aqui também.
    // O índice do `name` é reescrito no clone, senão duas linhas dividiriam a
    // mesma posição do array e o PHP receberia só a última.
    function reindexar($container, grupo) {
      $container.children().each(function(i) {
        $(this).find('[name]').each(function() {
          var nome = $(this).attr('name').replace(
            new RegExp('^notification\\[' + grupo + '\\]\\[\\d+\\]'),
            'notification[' + grupo + '][' + i + ']'
          );
          $(this).attr('name', nome);
        });
      });
    }

    function adicionarLinha(seletor, grupo) {
      var $container = $(seletor);
      var $ultima = $container.children().last();
      if (!$ultima.length) return;

      var $nova = $ultima.clone();
      $nova.find('input').val('');
      // O select volta ao primeiro tipo: herdar "Cópia Oculta" da linha
      // anterior faria a nova nascer escondida do cliente sem ninguém pedir.
      $nova.find('select').prop('selectedIndex', 0);
      $container.append($nova);

      reindexar($container, grupo);

      // O `.phonemask` do footer roda uma vez, no ready: a linha clonada
      // nasce sem máscara. E o clone é sem `true` de propósito — copiar
      // dados e eventos traria junto o estado velho do plugin.
      if (typeof $.fn.mask === 'function') {
        $nova.find('.phonemask').each(function() {
          var padrao = function(val) {
            return val.replace(/\D/g, '').length === 11 ? '(00) 00000-0000' : '(00) 0000-00009';
          };
          $(this).mask(padrao, {
            onKeyPress: function(val, e, field, options) {
              field.mask(padrao.apply({}, arguments), options);
            }
          });
        });
      }

      $nova.find('input').first().focus();
    }

    $('#btn_add_email').on('click', function() {
      adicionarLinha('#repeater_emails', 'emails');
    });

    $('#btn_add_whatsapp').on('click', function() {
      adicionarLinha('#repeater_whatsapps', 'whatsapps');
    });

    // Delegado: as linhas são clonadas depois do carregamento.
    $('#repeater_emails, #repeater_whatsapps').on('click', '.btn-remover-linha', function() {
      var $container = $(this).closest('#repeater_emails, #repeater_whatsapps');
      var grupo = $container.attr('id') === 'repeater_emails' ? 'emails' : 'whatsapps';

      // A última linha é esvaziada, não removida: sem nenhuma linha o repeater
      // fica sem o que clonar e o botão ADICIONAR para de funcionar.
      if ($container.children().length <= 1) {
        $container.find('input').val('');
        $container.find('select').prop('selectedIndex', 0);
        return;
      }

      $(this).closest('.linha-email, .linha-whatsapp').remove();
      reindexar($container, grupo);
    });

    // ------------------------------------------------------------------
    // Parcelamento
    // ------------------------------------------------------------------
    // O hint traduz "2 parcelas" em "2× de R$ 250,00": o valor por parcela é
    // o que o cliente vê no boleto, e é ele que se confere de cabeça.
    function moedaSimples(valor) {
      return Number(valor || 0).toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    }

    // Mesmo rateio do servidor (Invoice_model::valoresDasParcelas): centavos
    // inteiros, resto na última. Se as duas contas divergirem, a tela promete
    // um valor e o boleto cobra outro.
    function parcelasDe(valorReais, n) {
      var centavos = Math.round((Number(valorReais) || 0) * 100);
      n = Math.max(1, parseInt(n, 10) || 1);
      if (n === 1) return {
        base: centavos / 100,
        ultima: centavos / 100,
        iguais: true
      };

      var base = Math.floor(centavos / n);
      var ultima = base + (centavos - base * n);
      return {
        base: base / 100,
        ultima: ultima / 100,
        iguais: base === ultima
      };
    }

    function atualizarHintParcelas() {
      var n = parseInt($('#installments').val(), 10) || 1;
      var valor = Number('<?php echo (float) $result->value; ?>');
      var p = parcelasDe(valor, n);

      $('#hint_parcelas').text(n <= 1 ?
        'Uma cobrança por competência.' :
        n + '× de R$ ' + moedaSimples(p.base) + (p.iguais ? '' : ' (última: R$ ' + moedaSimples(p.ultima) + ')'));
    }

    if ($('#installments').length) {
      atualizarHintParcelas();
      $('#installments').on('input change', atualizarHintParcelas);
    }

    $('#cobranca_value, #cobranca_installments').on('input change', function() {
      var n = parseInt($('#cobranca_installments').val(), 10) || 1;
      var valor = Number(String($('#cobranca_value').val() || '0').replace(/\./g, '').replace(',', '.'));
      var p = parcelasDe(valor, n);

      $('#cobranca_resumo').html(valor <= 0 ?
        'Informe o valor e o número de parcelas.' :
        '<strong>' + n + '× de R$ ' + moedaSimples(p.base) + '</strong>' +
        (p.iguais ? '' : ' — a última fica em R$ ' + moedaSimples(p.ultima) + ', para a soma fechar em R$ ' + moedaSimples(valor)));
    });

    $('.btn-cancelar-cobranca').on('click', function() {
      var id = $(this).data('id');
      var descricao = $(this).data('descricao');

      Swal.fire({
        title: 'Cancelar a cobrança?',
        html: '<strong>' + $('<span></span>').text(descricao).html() + '</strong><br>' +
          'As parcelas ainda em aberto são canceladas. As já pagas permanecem — o dinheiro entrou.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'SIM, CANCELAR',
        cancelButtonText: 'VOLTAR'
      }).then(function(r) {
        if (!r.isConfirmed) return;
        $('#cancelar_cobranca_id').val(id);
        $('#form_cancelar_cobranca').submit();
      });
    });

    $('#form_faturamento').on('submit', function(e) {
      if ($('#billing_source').val() !== 'cdwfinance') return;

      var $confirma = $('#confirma_erp');
      if ($confirma.length && !$confirma.is(':checked')) {
        e.preventDefault();
        Swal.fire('Atenção', 'Encerre o contrato no Bom Controle e marque a confirmação — sem isso o cliente seria cobrado duas vezes.', 'warning');
      }
    });

    $('#btn_gerar_fatura').on('click', function() {
      var $btn = $(this);
      var html = $btn.html();
      $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1" role="status"></span> GERANDO...');

      $.ajax({
        type: 'POST',
        url: '<?php echo base_url('contratos/json_postgerarfatura'); ?>',
        data: {
          id: <?php echo (int) $result->id; ?>
        },
        dataType: 'json',
        success: function(data) {
          if (sessaoExpirou(data)) return;
          if (!data || !data.return) {
            notificar('error', (data && data.message) ? data.message : 'Erro ao gerar a fatura.');
            return;
          }
          // Gerou: recarrega para a fatura aparecer na aba Faturas. O aviso NÃO
          // sai daqui — um toast não sobrevive ao reload da linha seguinte, e
          // era isso que fazia o sucesso parecer silencioso. Quem avisa é o
          // flashdata gravado no servidor, que o header.php exibe já na página
          // recarregada.
          if (data.data && data.data.geradas > 0) {
            window.location.reload();
            return;
          }

          // Nada gerado (competência já faturada): não há o que recarregar, e
          // aqui o toast é o único canal.
          notificar('success', data.message);
        },
        error: function(xhr) {
          console.log(xhr.responseText);
          notificar('error', 'Erro de comunicação ao gerar a fatura.');
        },
        complete: function() {
          $btn.prop('disabled', false).html(html);
        }
      });
    });

    // ----------------------------------------------------------------
    // Serviço do catálogo do Bom Controle
    // ----------------------------------------------------------------

    var servicoSelecionado = null;

    function buscarServicoBc(termo) {
      var $lista = $('#servico_bc_lista');
      var $aviso = $('#servico_bc_aviso');

      servicoSelecionado = null;
      $('#btn_confirmar_servico_bc').prop('disabled', true);
      $aviso.html('');
      $lista.html('<tr><td colspan="4" class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Consultando o catálogo...</td></tr>');

      $.ajax({
        type: 'POST',
        url: '<?php echo base_url('contratos/json_postbuscarservicobc'); ?>',
        data: {
          id: <?php echo (int) $result->id; ?>,
          termo: termo
        },
        dataType: 'json',
        success: function(data) {
          if (sessaoExpirou(data)) return;

          if (!data || !data.return || !data.data) {
            $lista.html('');
            $aviso.html($('<div class="alert alert-danger mb-2"><div class="alert-message"></div></div>')
              .find('.alert-message').text((data && data.message) ? data.message : 'Erro ao consultar o catálogo.').end());
            return;
          }

          var servicos = data.data.servicos || [];
          if (!servicos.length) {
            $lista.html('<tr><td colspan="4" class="text-center text-muted py-4">Nenhum serviço encontrado para esta busca.</td></tr>');
            return;
          }

          // Quando a busca é vazia, o catálogo pode ser maior que a página —
          // dizer isso evita que alguém conclua que o serviço não existe.
          if (data.data.total > data.data.exibindo) {
            $aviso.html($('<div class="alert alert-warning mb-2"><div class="alert-message"></div></div>')
              .find('.alert-message').text('Mostrando ' + data.data.exibindo + ' de ' + data.data.total + ' serviços. Refine a busca para encontrar o que falta.').end());
          }

          var html = '';
          $.each(servicos, function(i, s) {
            html += '<tr>' +
              '<td class="text-center"><input type="radio" name="servico_bc" class="form-check-input servico-bc-radio"' +
              ' data-id="' + s.id + '" data-nome="' + esc(s.nome) + '"></td>' +
              '<td>' + s.id + '</td>' +
              '<td>' + esc(s.nome) + (s.observacao ? '<br /><small class="text-muted">' + esc(s.observacao) + '</small>' : '') + '</td>' +
              '<td><small class="text-muted">' + esc(s.tipo) + '</small></td>' +
              '</tr>';
          });
          $lista.html(html);
        },
        error: function(xhr) {
          console.log(xhr.responseText);
          $lista.html('');
          $aviso.html('<div class="alert alert-danger mb-2"><div class="alert-message">Erro de comunicação ao consultar o catálogo.</div></div>');
        }
      });
    }

    $('#btn_vincular_servico_bc').on('click', function() {
      $('#servico_bc_termo').val('');
      $('#modal_servico_bc').modal('show');
      buscarServicoBc('');
    });

    $('#btn_buscar_servico_bc').on('click', function() {
      buscarServicoBc($('#servico_bc_termo').val());
    });

    $('#servico_bc_termo').on('keypress', function(e) {
      if (e.which === 13) {
        e.preventDefault();
        buscarServicoBc($(this).val());
      }
    });

    $(document).on('change', '.servico-bc-radio', function() {
      servicoSelecionado = {
        id: $(this).data('id'),
        nome: $(this).data('nome')
      };
      $('#btn_confirmar_servico_bc').prop('disabled', false);
    });

    $('#btn_confirmar_servico_bc').on('click', function() {
      if (!servicoSelecionado) return;

      var $btn = $(this);
      $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1" role="status"></span> VINCULANDO...');

      $.ajax({
        type: 'POST',
        url: '<?php echo base_url('contratos/json_postvincularservicobc'); ?>',
        data: {
          id: <?php echo (int) $result->id; ?>,
          id_servico: servicoSelecionado.id
        },
        dataType: 'json',
        success: function(data) {
          if (sessaoExpirou(data)) return;
          if (!data || !data.return) {
            notificar('error', (data && data.message) ? data.message : 'Erro ao vincular o serviço.');
            $btn.prop('disabled', false).html('<i class="mdi mdi-link-variant"></i> VINCULAR');
            return;
          }
          notificar('success', data.message);
          window.location.reload();
        },
        error: function(xhr) {
          console.log(xhr.responseText);
          notificar('error', 'Erro de comunicação ao vincular o serviço.');
          $btn.prop('disabled', false).html('<i class="mdi mdi-link-variant"></i> VINCULAR');
        }
      });
    });

    $('#btn_desvincular_servico_bc').on('click', function() {
      Swal.fire({
        title: 'Desvincular o serviço?',
        html: 'O contrato fica sem serviço do ERP, e a emissão de boleto e nota fiscal passa a não ter o que enviar. Nada muda no catálogo do Bom Controle.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonText: 'Cancelar',
        confirmButtonText: 'Desvincular'
      }).then(function(result) {
        if (!result.value) return;

        $.ajax({
          type: 'POST',
          url: '<?php echo base_url('contratos/json_postdesvincularservicobc'); ?>',
          data: {
            id: <?php echo (int) $result->id; ?>
          },
          dataType: 'json',
          success: function(data) {
            if (sessaoExpirou(data)) return;
            if (!data || !data.return) {
              notificar('error', (data && data.message) ? data.message : 'Erro ao desvincular.');
              return;
            }
            notificar('success', data.message);
            window.location.reload();
          },
          error: function(xhr) {
            console.log(xhr.responseText);
            notificar('error', 'Erro de comunicação ao desvincular.');
          }
        });
      });
    });

    $('#btn_avisar_reajuste').on('click', function() {
      var $btn = $(this);

      Swal.fire({
        title: 'Enviar aviso de reajuste?',
        html: 'O cliente recebe um e-mail com o índice, o percentual e o novo valor.',
        icon: 'question',
        showCancelButton: true,
        cancelButtonText: 'Cancelar',
        confirmButtonText: 'Enviar'
      }).then(function(result) {
        if (!result.value) return;

        var html = $btn.html();
        $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1" role="status"></span> ENVIANDO...');

        $.ajax({
          type: 'POST',
          url: '<?php echo base_url('contratos/json_postavisarreajuste'); ?>',
          data: {
            id: <?php echo (int) $result->id; ?>
          },
          dataType: 'json',
          success: function(data) {
            if (sessaoExpirou(data)) return;
            if (!data || !data.return) {
              notificar('error', (data && data.message) ? data.message : 'Erro ao enviar o aviso.');
              return;
            }
            notificar('success', data.message);
          },
          error: function(xhr) {
            console.log(xhr.responseText);
            notificar('error', 'Erro de comunicação ao enviar o aviso.');
          },
          complete: function() {
            $btn.prop('disabled', false).html(html);
          }
        });
      });
    });
    // --- Provedor da cobrança, a partir da aba Faturas ---------------
    // Delegado no document porque a tabela é redesenhada a cada página da aba:
    // um bind direto morreria no primeiro ANTERIOR/PRÓXIMA.
    (function() {
      var $modalPsp = $('#modal_psp_aba');
      if (!$modalPsp.length) return;

      var faturaAlvo = 0;

      $(document).on('click', '.btn-trocar-psp-aba', function() {
        var $b = $(this);
        faturaAlvo = $b.data('id');

        var pspAtual = String($b.data('psp') || '');
        if (pspAtual !== '') $('#psp_aba_select').val(pspAtual);

        $('#psp_aba_aviso').toggleClass('d-none', String($b.data('cobranca')) !== '1');
        bootstrap.Modal.getOrCreateInstance($modalPsp[0]).show();
      });

      $('#btn_confirmar_psp_aba').on('click', function() {
        var $botao = $(this);
        var psp = $('#psp_aba_select').val();
        if (!faturaAlvo || !psp) return;

        $botao.prop('disabled', true).text('PROCESSANDO...');

        $.post('<?php echo base_url('faturas/json_posttrocarpsp'); ?>', {
            id: faturaAlvo,
            psp: psp
          }, null, 'json')
          .done(function(retorno) {
            if (typeof sessaoExpirou === 'function' && sessaoExpirou(retorno)) return;

            if (retorno && retorno.success) {
              bootstrap.Modal.getOrCreateInstance($modalPsp[0]).hide();
              // Recarrega só a aba, sem reload de página: o usuário está no
              // meio da tela do contrato e perderia o contexto à toa.
              if (typeof carregarFaturas === 'function') carregarFaturas(1);
              else if (typeof carregarFaturasCliente === 'function') carregarFaturasCliente(1);
              notificarPsp(retorno.data && retorno.data.pronta ? 'success' : 'info', retorno.message);
              return;
            }

            Swal.fire('Não foi possível', (retorno && retorno.message) ? retorno.message : 'Falha ao falar com o provedor.', 'error');
          })
          .fail(function(xhr) {
            console.log(xhr.responseText);
            Swal.fire('Erro', 'Falha de comunicação ao registrar a cobrança.', 'error');
          })
          .always(function() {
            $botao.prop('disabled', false).text('CONFIRMAR');
          });
      });

      function notificarPsp(tipo, texto) {
        if (typeof notificar === 'function') {
          notificar(tipo === 'success' ? 'success' : 'info', texto);
          return;
        }
        Swal.fire(tipo === 'success' ? 'Pronto' : 'Registrado', texto, tipo === 'success' ? 'success' : 'info');
      }
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


    // --- Cancelar a fatura, a partir das abas ------------------------
    // Endpoint AJAX próprio: o post_status da tela de Faturas redireciona para
    // a listagem, e daqui isso tiraria o usuário do contrato no meio da
    // conferência. A REGRA é a mesma nos dois caminhos (derrubarCobranca no
    // servidor) — o que muda é só como a resposta volta.
    $(document).on('click', '.btn-cancelar-fatura', function() {
      var id = $(this).data('id');

      Swal.fire({
        title: 'Cancelar a fatura?',
        html: 'O <strong>boleto é cancelado no banco</strong> primeiro — se isso falhar, a fatura' +
          ' continua em aberto, para não deixar uma cobrança de pé sem fatura.<br><br>' +
          'Cancelada, ela <strong>não pode ser reaberta</strong>, e a competência dela não é gerada de novo.',
        icon: 'warning',
        showCancelButton: true,
        cancelButtonText: 'Voltar',
        confirmButtonText: 'Cancelar fatura'
      }).then(function(r) {
        if (!r.value) return;

        // O cancelamento vai ao banco antes de fechar a fatura e pode levar
        // segundos. Aqui a tela NÃO recarrega — então o hide precisa cobrir
        // todas as saídas, inclusive a de sucesso, senão o modal fica travando
        // a tela com o trabalho já feito.
        $('#modal_loading').modal('show');

        $.post('<?php echo base_url('faturas/json_postcancelar'); ?>', {
            id: id
          }, null, 'json')
          .done(function(retorno) {
            // Esconde ANTES de qualquer diálogo: no `.always()` o hide roda
            // depois do `.done()`, e o alerta de erro subiria com o loading
            // ainda por baixo, piscando o backdrop.
            $('#modal_loading').modal('hide');

            if (typeof sessaoExpirou === 'function' && sessaoExpirou(retorno)) return;

            if (!retorno || !retorno.success) {
              Swal.fire('Não foi possível cancelar', (retorno && retorno.message) ? retorno.message : 'Falha ao cancelar a fatura.', 'error');
              return;
            }

            // Recarrega só a aba: a fatura muda de situação e sai do total em
            // aberto do rodapé.
            if (typeof carregarFaturas === 'function') carregarFaturas(1);
            else if (typeof carregarFaturasCliente === 'function') carregarFaturasCliente(1);

            if (typeof notificar === 'function') notificar('success', retorno.message);
            else Swal.fire('Pronto', retorno.message, 'success');
          })
          .fail(function(xhr) {
            $('#modal_loading').modal('hide');
            console.log(xhr.responseText);
            Swal.fire('Erro', 'Falha de comunicação ao cancelar a fatura.', 'error');
          });
      });
    });

  });
</script>

<?php $this->load->view("domains/whois_modal"); ?>