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
          <button type="button" class="btn btn-outline-warning" id="btn_status" data-acao="suspender"><i class="mdi mdi-pause-circle-outline"></i> SUSPENDER</button>
        <?php } else { ?>
          <button type="button" class="btn btn-success" id="btn_status" data-acao="reativar"><i class="fa fa-check"></i> REATIVAR</button>
        <?php } ?>
      </form>
      <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#modal_encerrar"><i class="mdi mdi-close-circle-outline"></i> ENCERRAR</button>
      <form method="POST" id="form_excluir_contrato" action="<?php echo base_url('contratos/post_excluir'); ?>" class="d-inline">
        <input type="hidden" name="id" value="<?php echo (int) $result->id; ?>">
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
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_extrato" role="tab">Extrato financeiro</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab_historicos" role="tab">Históricos</a></li>
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
                <small class="text-muted">Mês em que o contrato entra nas entradas do dashboard.</small>
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
                  <th class="text-center" style="width: 170px;">Ações</th>
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
            <h5 class="card-title mb-0">Extrato financeiro</h5>
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

  <div class="tab-pane fade" id="tab_historicos" role="tabpanel">
    <div class="card flex-fill">
      <div class="card-body py-3">
        <div class="text-center text-muted py-5">
          <i class="mdi mdi-history fs-1 d-block mb-2"></i>
          <h5 class="mb-1">Históricos <span class="badge bg-secondary">Em breve</span></h5>
          <p class="mb-0">Alterações e eventos deste contrato.</p>
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
        <form name="form" method="POST" action="<?php echo base_url('contratos/post_encerrar'); ?>">
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
            <div class="form-group mb-3">
              <label class="form-label">* Motivo do encerramento</label>
              <select class="form-control" name="reason" required>
                <option value="">Selecione...</option>
                <?php foreach ($end_reasons as $slug => $rotulo) { ?>
                  <option value="<?php echo $slug; ?>"><?php echo $rotulo; ?></option>
                <?php } ?>
              </select>
            </div>
            <div class="form-group mb-3">
              <label class="form-label">Observações</label>
              <textarea class="form-control" name="comments" rows="3" maxlength="500" placeholder="Detalhe do encerramento (opcional)."></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <div class="col">
              <button type="submit" class="btn w-100 btn-warning"><i class="mdi mdi-close-circle-outline"></i> ENCERRAR CONTRATO</button>
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

    $('#btn_status').on('click', function() {
      var suspender = $(this).data('acao') === 'suspender';
      Swal.fire({
        title: suspender ? 'Suspender contrato?' : 'Reativar contrato?',
        html: suspender ? 'O contrato ficará com o status <strong>suspenso</strong>.' : 'O contrato voltará ao status <strong>vigente</strong>.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: suspender ? '#d33' : '#3085d6',
        cancelButtonColor: '#ccc',
        cancelButtonText: 'Cancelar',
        confirmButtonText: suspender ? 'Suspender' : 'Reativar'
      }).then(function(result) {
        if (result.value) $('#form_status').submit();
      });
    });

    // Reabrir é CORREÇÃO de engano, não retorno de cliente (cliente que volta
    // gera contrato novo) — por isso o aviso diz de qual mês o contrato sai.
    $('#btn_reabrir').on('click', function() {
      Swal.fire({
        title: 'Reabrir contrato?',
        html: 'O contrato volta a <strong>vigente</strong> e o registro do encerramento é apagado.<br>' +
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
      Swal.fire({
        title: 'Excluir contrato?',
        html: 'O contrato <strong>#<?php echo (int) $result->id; ?></strong> e seus documentos serão excluídos <strong>definitivamente</strong>.<br><small>Esta ação não pode ser desfeita.</small>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#ccc',
        cancelButtonText: 'Cancelar',
        confirmButtonText: 'Excluir'
      }).then(function(result) {
        if (result.value) $('#form_excluir_contrato').submit();
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
    // Extrato financeiro (Bom Controle)
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
  });
</script>

<?php $this->load->view("domains/whois_modal"); ?>
