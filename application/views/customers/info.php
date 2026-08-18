<?php
// Endereço comercial em linha única, no formato do mockup da visão geral.
$enderecoComercial = '';
if (!empty($result->address)) {
  $partes = [
    $result->address . (!empty($result->address_number) ? ', ' . $result->address_number : ''),
    !empty($result->address_complement) ? $result->address_complement : '',
    !empty($result->address_district) ? $result->address_district : '',
    !empty($result->city_name) ? $result->city_name . ' - ' . $result->state_uf : '',
    !empty($result->address_zip) ? $result->address_zip : '',
  ];
  $enderecoComercial = implode(', ', array_filter($partes));
}

// Abas do painel lateral. Domínios e Atividades já são funcionais; as demais
// são módulos futuros — cada um substitui o estado vazio pelo conteúdo real
// quando existir, mantendo esta estrutura.
$abas = [
  ['id' => 'tab_dominios', 'titulo' => 'Domínios', 'icone' => 'mdi-web', 'texto' => 'Domínios vinculados a este cliente.'],
  ['id' => 'tab_atividades', 'titulo' => 'Atividades', 'icone' => 'mdi-history', 'texto' => 'Histórico de interações e tarefas.'],
  ['id' => 'tab_orcamentos', 'titulo' => 'Orçamentos', 'icone' => 'mdi-file-percent-outline', 'texto' => 'Orçamentos enviados para este cliente.'],
  ['id' => 'tab_extrato', 'titulo' => 'Extrato Bom Controle', 'icone' => 'mdi-cash-multiple', 'texto' => 'Faturas, boletos e movimentações do ERP.'],
  ['id' => 'tab_faturas', 'titulo' => 'Faturas', 'icone' => 'mdi-receipt-text-outline', 'texto' => 'Faturas geradas pelo CDW Finance.'],
];
$badgeContratoStatus = ['vigente' => 'bg-success', 'suspenso' => 'bg-warning', 'encerrado' => 'bg-secondary'];
// Rótulo por mapa: o ternário antigo (`=== 'vigente' ? 'Vigente' : 'Suspenso'`)
// chamava de suspenso todo contrato encerrado.
$rotuloContratoStatus = ['vigente' => 'Vigente', 'suspenso' => 'Suspenso', 'encerrado' => 'Encerrado'];
$gb = function ($valor) {
  $texto = number_format((float) $valor, 2, ',', '');
  return rtrim(rtrim($texto, '0'), ',');
};
?>
<div class="row mb-2 mb-xl-2">
  <div class="col-auto text-start">
    <h1 class="h3 mb-0"><?php echo htmlspecialchars($result->name, ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="text-muted mb-2">Visão geral do cliente #<?php echo (int) $result->id; ?></p>
  </div>
  <div class="col-auto ms-auto text-end mt-n1">
    <span class="badge bg-success fs-6 align-middle me-2"><?php echo $result->type === 'F' ? 'Pessoa física' : 'Pessoa jurídica'; ?></span>
    <a class="btn btn-outline-secondary" href="<?php echo base_url('clientes'); ?>"><i class="fa fa-arrow-left"></i> VOLTAR</a>
  </div>
</div>

<div class="row">
  <div class="col-12 col-xl-4 d-flex">
    <div class="card flex-fill">
      <div class="card-body py-3">
        <h5 class="card-title mb-1">Resumo cadastral</h5>
        <p class="text-muted">Dados principais do cliente e ações de manutenção.</p>

        <div class="d-flex mb-3">
          <i class="mdi mdi-file-document-outline fs-4 me-2 text-muted"></i>
          <div>
            <strong>Documento</strong><br />
            <?php echo cnpj($result->document); ?>
          </div>
        </div>
        <?php if ((string) $result->type === 'J') { ?>
          <div class="d-flex mb-3">
            <i class="mdi mdi-bank-outline fs-4 me-2 text-muted"></i>
            <div>
              <strong>Inscrição estadual</strong><br />
              <?php echo htmlspecialchars((string) $result->state_registration, ENT_QUOTES, 'UTF-8'); ?>
            </div>
          </div>
        <?php } ?>
        <div class="d-flex mb-3">
          <i class="mdi mdi-map-marker-outline fs-4 me-2 text-muted"></i>
          <div>
            <strong>Endereço</strong><br />
            <?php echo $enderecoComercial !== '' ? htmlspecialchars($enderecoComercial, ENT_QUOTES, 'UTF-8') : '—'; ?>
          </div>
        </div>

        <p class="text-muted mb-3">Criado em <?php echo data($result->created); ?></p>

        <a class="btn btn-primary w-100 mb-2" href="<?php echo base_url('clientes/editar?id=' . (int) $result->id); ?>"><i class="fa fa-edit"></i> EDITAR</a>

        <?php if (!empty($bomcontrole_ativo)) { ?>
          <!-- Reprocessa a sincronização que falhou (o cadastro nunca é
               bloqueado pelo ERP) e sobe a base anterior à integração. -->
          <button type="button" class="btn btn-outline-primary w-100 mb-2" id="btn_sincronizar_bc">
            <i class="mdi mdi-cloud-upload-outline"></i> SINCRONIZAR CADASTRO
          </button>
          <p class="text-muted mb-2" id="txt_sincronizado_bc"><small>
            <?php echo empty($result->bomcontrole_synced)
              ? 'Nunca sincronizado com o Bom Controle.'
              : 'Sincronizado com o Bom Controle em ' . data($result->bomcontrole_synced) . '.'; ?>
          </small></p>
        <?php } ?>
        <form method="POST" id="form_excluir" action="<?php echo base_url('clientes/post_excluir'); ?>">
          <input type="hidden" name="id" value="<?php echo (int) $result->id; ?>">
          <button type="button" class="btn btn-danger w-100" id="btn_excluir"><i class="fa fa-trash"></i> EXCLUIR</button>
        </form>
        <p class="text-muted mt-2 mb-0"><small>Para excluir, remova antes os contratos, anexos e contatos deste cliente.</small></p>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-8 d-flex">
    <div class="card flex-fill">
      <div class="card-body py-3">
        <ul class="nav nav-pills" role="tablist">
          <?php foreach ($abas as $i => $aba) { ?>
            <li class="nav-item"><a class="nav-link <?php if ($i === 0) echo 'active'; ?>" data-bs-toggle="tab" href="#<?php echo $aba['id']; ?>" role="tab"><?php echo $aba['titulo']; ?>
              <?php // Só a aba Faturas tem contagem — as demais carregam conteúdo
                    // que já está na página. Zero não vira badge: a ausência diz o
                    // mesmo sem poluir todas as abas com um "0". ?>
              <?php if ($aba['id'] === 'tab_faturas' && !empty($faturas_count)) { ?><span class="badge bg-secondary ms-1"><?php echo (int) $faturas_count; ?></span><?php } ?>
            </a></li>
          <?php } ?>
        </ul>
        <div class="tab-content pt-3">
          <?php foreach ($abas as $i => $aba) { ?>
            <div class="tab-pane fade <?php if ($i === 0) echo 'show active'; ?>" id="<?php echo $aba['id']; ?>" role="tabpanel">
              <?php if ($aba['id'] === 'tab_dominios') { ?>
                <div class="row align-items-center mb-2">
                  <div class="col">
                    <span class="text-muted"><?php echo count($contract_domains); ?> domínio(s) nos contratos vigentes deste cliente.</span>
                  </div>
                </div>
                <?php if (!empty($contract_domains)) { ?>
                  <div class="table-responsive">
                    <table class="table table-sm table-striped table-bordered table-hover">
                      <thead>
                        <tr>
                          <th class="text-center" style="width: 95px;">Ações</th>
                          <th>Domínio</th>
                          <!-- <th class="text-center">Contrato</th> -->
                          <th class="text-center">Vínculo</th>
                          <th class="text-center" style="min-width: 80px;">Em uso</th>
                          <th class="text-center">Vencimento</th>
                          <th>Local de registro</th>
                          <th class="text-center">Gerenciado CDW</th>
                          <th>Observações</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($contract_domains as $d) { ?>
                          <tr>
                            <td align="center" class="text-nowrap">
                              <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('contratos/info?id=' . (int) $d->id_contract); ?>" title="Abrir o contrato para editar este domínio"><i class="fa fa-eye"></i></a>
                              <button type="button" class="btn btn-sm btn-outline-primary js-whois-dominio" data-id="<?php echo (int) $d->id; ?>" data-dominio="<?php echo htmlspecialchars($d->domain, ENT_QUOTES, 'UTF-8'); ?>" title="Consultar WHOIS do domínio"><i class="fa fa-search"></i></button>
                            </td>
                            <td><small><?php echo htmlspecialchars($d->domain, ENT_QUOTES, 'UTF-8'); ?></small></td>
                            <!-- <td align="center"><a href="<?php echo base_url('contratos/info?id=' . (int) $d->id_contract); ?>">#<?php echo (int) $d->id_contract; ?></a></td> -->
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
                  <p class="text-muted mb-0"><small>Somente leitura: o cadastro e a edição de domínios ficam na visão geral do contrato.</small></p>
                <?php } else { ?>
                  <div class="text-center text-muted py-4">
                    <i class="mdi mdi-web fs-1 d-block mb-2"></i>
                    <h5 class="mb-1">Nenhum domínio nos contratos vigentes</h5>
                    <p class="mb-0">Abra um contrato vigente para cadastrar os domínios.</p>
                  </div>
                <?php } ?>
              <?php } elseif ($aba['id'] === 'tab_atividades') { ?>
                <div class="row align-items-center mb-2">
                  <div class="col">
                    <span class="text-muted"><?php echo count($notes); ?> atividade(s) registrada(s).</span>
                  </div>
                  <div class="col-auto text-end">
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#modal_atividade"><i class="mdi mdi-plus"></i> NOVA ATIVIDADE</button>
                  </div>
                </div>
                <?php if (!empty($notes)) { ?>
                  <div style="max-height: 420px; overflow-y: auto;">
                    <ul class="timeline mt-2 mb-0">
                      <?php foreach ($notes as $n) { ?>
                        <li class="timeline-item mb-2">
                          <strong><?php echo !empty($n->created_user) ? htmlspecialchars($n->created_user, ENT_QUOTES, 'UTF-8') : '—'; ?></strong>
                          <span class="text-muted"><small>em <?php echo data($n->created); ?></small></span>
                          <p class="mb-0"><?php echo nl2br(htmlspecialchars($n->description, ENT_QUOTES, 'UTF-8')); ?></p>
                        </li>
                      <?php } ?>
                    </ul>
                  </div>
                <?php } else { ?>
                  <div class="text-center text-muted py-4">
                    <i class="mdi mdi-history fs-1 d-block mb-2"></i>
                    <h5 class="mb-1">Nenhuma atividade registrada</h5>
                    <p class="mb-0">Registre a primeira observação ou interação deste cliente.</p>
                  </div>
                <?php } ?>
              <?php } elseif ($aba['id'] === 'tab_extrato') { ?>
                <div class="row align-items-center mb-2">
                  <div class="col">
                    <span class="text-muted">Extrato consolidado dos contratos vinculados ao Bom Controle.</span>
                  </div>
                  <div class="col-auto text-end">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btn_atualizar_extrato_cliente" <?php if (empty($bomcontrole_ativo)) echo 'disabled'; ?>><i class="mdi mdi-refresh"></i> ATUALIZAR</button>
                  </div>
                </div>
                <div id="extrato_cliente_conteudo">
                  <?php if (empty($bomcontrole_ativo)) { ?>
                    <div class="alert alert-warning mb-0" role="alert">
                      <div class="alert-message">
                        Integração com o Bom Controle desativada para esta empresa. Ative-a no cadastro da empresa (aba Bom Controle) para consultar o extrato.
                      </div>
                    </div>
                  <?php } ?>
                </div>
              <?php } elseif ($aba['id'] === 'tab_faturas') { ?>
                <div class="row align-items-center mb-2">
                  <div class="col">
                    <span class="text-muted">Faturas geradas pelo CDW Finance em todos os contratos deste cliente.</span>
                  </div>
                  <div class="col-auto text-end">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btn_atualizar_faturas_cliente"><i class="mdi mdi-refresh"></i> ATUALIZAR</button>
                  </div>
                </div>
                <div id="faturas_cliente_conteudo"></div>
              <?php } else { ?>
                <div class="text-center text-muted py-5">
                  <i class="mdi <?php echo $aba['icone']; ?> fs-1 d-block mb-2"></i>
                  <h5 class="mb-1"><?php echo $aba['titulo']; ?> <span class="badge bg-secondary">Em breve</span></h5>
                  <p class="mb-0"><?php echo $aba['texto']; ?></p>
                </div>
              <?php } ?>
            </div>
          <?php } ?>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card flex-fill">
  <div class="card-body py-3">
    <div class="row">
      <div class="col">
        <h5 class="card-title mb-1">Contratos</h5>
        <p class="text-muted mb-2">Crie contratos operacionais com espaço contratado, domínios e documentos.</p>
      </div>
      <div class="col-auto text-end">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal_contrato_novo"><i class="mdi mdi-plus"></i> ADICIONAR NOVO</button>
      </div>
    </div>
    <?php if (!empty($contracts)) { ?>
      <div class="table-responsive">
        <table class="table table-striped table-bordered table-hover">
          <thead>
            <tr>
              <th class="text-center" style="width: 130px;">Ações</th>
              <th>Contrato</th>
              <th class="text-center">Espaço contratado</th>
              <th class="text-center" style="min-width: 170px;">Capacidade</th>
              <th style="min-width: 200px;">Domínios</th>
              <th class="text-center">Valor</th>
              <th class="text-center">Ciclo</th>
              <th class="text-center">Status</th>
              <th class="text-center">Criado em</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($contracts as $ct) { ?>
              <tr>
                <td align="center">
                  <a class="btn btn-sm btn-outline-primary" href="<?php echo base_url('contratos/info?id=' . (int) $ct->id); ?>" title="Abrir contrato"><i class="fa fa-eye"></i> CONTRATO</a>
                </td>
                <td>
                  <a href="<?php echo base_url('contratos/info?id=' . (int) $ct->id); ?>"><strong>#<?php echo (int) $ct->id; ?></strong></a><br />
                  <?php foreach ((isset($contract_services[(int) $ct->id]) ? $contract_services[(int) $ct->id] : []) as $tipoNome) { ?>
                    <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($tipoNome, ENT_QUOTES, 'UTF-8'); ?></span>
                  <?php } ?>
                </td>
                <td align="center"><?php echo $gb($ct->space_gb); ?> Gb</td>
                <td>
                  <?php
                  $usoGb = (isset($usage_by_contract_mb[(int) $ct->id]) ? (float) $usage_by_contract_mb[(int) $ct->id] : 0.0) / 1024;
                  $temEspaco = ((float) $ct->space_gb) > 0;
                  $percentUso = $temEspaco ? min(100, ($usoGb / (float) $ct->space_gb) * 100) : 0;
                  $corBarra = 'bg-success';
                  if ($percentUso >= 90) $corBarra = 'bg-danger';
                  elseif ($percentUso >= 70) $corBarra = 'bg-warning';
                  ?>
                  <?php if ($temEspaco) { ?>
                    <div class="d-flex justify-content-between">
                      <small><?php echo number_format($usoGb, 2, ',', '.'); ?> de <?php echo $gb($ct->space_gb); ?> Gb</small>
                      <small class="fw-bold"><?php echo number_format($percentUso, 0, ',', '.'); ?>%</small>
                    </div>
                    <div class="progress mt-1" style="height: 6px;">
                      <div class="progress-bar <?php echo $corBarra; ?>" role="progressbar" style="width: <?php echo (int) $percentUso; ?>%;" aria-valuenow="<?php echo (int) $percentUso; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                  <?php } else { ?>
                    <span class="badge bg-light text-dark border">Sem espaço definido</span>
                  <?php } ?>
                </td>
                <td>
                  <?php
                  $dominiosDoContrato = isset($domains_by_contract[(int) $ct->id]) ? $domains_by_contract[(int) $ct->id] : [];
                  $total = count($dominiosDoContrato);
                  // O mesmo domínio pode estar em servidores diferentes (site num
                  // painel, e-mail em outro) e vira um cadastro por conta. Aqui não
                  // há coluna de vínculo, então as duas linhas sairiam idênticas —
                  // o servidor entra como sufixo SÓ no nome que se repete.
                  $repetidos = [];
                  foreach ($dominiosDoContrato as $d) {
                    $repetidos[$d->domain] = isset($repetidos[$d->domain]) ? $repetidos[$d->domain] + 1 : 1;
                  }
                  // Compacto: os primeiros na célula, o resto pela tela do contrato.
                  $mostrar = array_slice($dominiosDoContrato, 0, 5);
                  ?>
                  <?php if ($total > 0) { ?>
                    <?php foreach ($mostrar as $d) { ?>
                      <?php $vencido = !empty($d->due_date) && strtotime($d->due_date) < strtotime(date('Y-m-d')); ?>
                      <div class="text-truncate" style="max-width: 320px;">
                        <small><?php echo htmlspecialchars($d->domain, ENT_QUOTES, 'UTF-8'); ?></small>
                        <?php if ($repetidos[$d->domain] > 1 && !empty($d->id_server_domain) && !empty($d->server_name)) { ?>
                          <small class="text-muted">· <?php echo htmlspecialchars((string) $d->server_name, ENT_QUOTES, 'UTF-8'); ?></small>
                        <?php } ?>
                        <small class="<?php echo $vencido ? 'text-danger fw-bold' : 'text-muted'; ?>">
                          <?php echo !empty($d->due_date) ? '· ' . date('d/m/Y', strtotime($d->due_date)) : '· sem vencimento'; ?>
                        </small>
                      </div>
                    <?php } ?>
                    <?php if ($total > count($mostrar)) { ?>
                      <a href="<?php echo base_url('contratos/info?id=' . (int) $ct->id); ?>"><small>+ <?php echo $total - count($mostrar); ?> domínio(s)</small></a>
                    <?php } ?>
                  <?php } else { ?>
                    <small class="text-muted">Nenhum domínio</small>
                  <?php } ?>
                </td>
                <td align="center"><?php echo reais($ct->value); ?></td>
                <td align="center"><?php echo isset($cycles[$ct->cycle]) ? $cycles[$ct->cycle] : $ct->cycle; ?></td>
                <td align="center">
                  <span class="badge <?php echo isset($badgeContratoStatus[$ct->status]) ? $badgeContratoStatus[$ct->status] : 'bg-dark'; ?>"><?php echo isset($rotuloContratoStatus[$ct->status]) ? $rotuloContratoStatus[$ct->status] : $ct->status; ?></span>
                  <?php if ($ct->status === 'encerrado' && !empty($ct->ended)) { ?>
                    <small class="d-block text-muted"><?php echo date('d/m/Y', strtotime($ct->ended)); ?></small>
                  <?php } ?>
                </td>
                <td align="center"><?php echo data($ct->created); ?></td>
              </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>
    <?php } else { ?>
      <div class="text-center text-muted py-4">
        <i class="mdi mdi-file-sign fs-1 d-block mb-2"></i>
        <h5 class="mb-1">Nenhum contrato disponível</h5>
        <p class="mb-0">Clique em ADICIONAR NOVO para criar o primeiro contrato deste cliente.</p>
      </div>
    <?php } ?>
  </div>
</div>

<div class="card flex-fill">
  <div class="card-body py-3">
    <div class="row">
      <div class="col">
        <h5 class="card-title mb-1">Contatos</h5>
        <p class="text-muted mb-2">Gerencie os contatos vinculados a este cliente.</p>
      </div>
      <div class="col-auto text-end">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal_contato"><i class="mdi mdi-plus"></i> NOVO CONTATO</button>
      </div>
    </div>
    <?php if (!empty($contacts)) { ?>
      <div class="table-responsive">
        <table class="table table-striped table-bordered table-hover">
          <thead>
            <tr>
              <th class="text-center" style="width: 130px;">Ações</th>
              <th>Tipo de contato</th>
              <th>Nome</th>
              <th>E-mail</th>
              <th class="text-center">Telefone/WhatsApp</th>
              <th class="text-center">Criado em</th>
              <th class="text-center">Incluído por</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($contacts as $c) { ?>
              <tr data-contato="<?php echo (int) $c->id; ?>">
                <td align="center">
                  <button type="button" class="btn btn-sm btn-outline-secondary js-editar-contato"
                    data-id="<?php echo (int) $c->id; ?>"
                    data-type="<?php echo htmlspecialchars($c->type, ENT_QUOTES, 'UTF-8'); ?>"
                    data-nome="<?php echo htmlspecialchars($c->name, ENT_QUOTES, 'UTF-8'); ?>"
                    data-email="<?php echo htmlspecialchars((string) $c->email, ENT_QUOTES, 'UTF-8'); ?>"
                    data-phone="<?php echo htmlspecialchars((string) $c->phone, ENT_QUOTES, 'UTF-8'); ?>"
                    title="Editar contato"><i class="fa fa-edit"></i></button>
                  <button type="button" class="btn btn-sm btn-outline-danger js-excluir-contato" data-id="<?php echo (int) $c->id; ?>" data-nome="<?php echo htmlspecialchars($c->name, ENT_QUOTES, 'UTF-8'); ?>" title="Excluir contato"><i class="fa fa-trash"></i></button>
                </td>
                <td><?php echo isset($contact_types[$c->type]) ? $contact_types[$c->type] : htmlspecialchars($c->type, ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($c->name, ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo !empty($c->email) ? htmlspecialchars($c->email, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                <td align="center"><?php echo !empty($c->phone) ? htmlspecialchars($c->phone, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                <td align="center">
                  <?php echo data($c->created); ?>
                  <?php if (!empty($c->modified)) { ?>
                    <br /><small class="text-muted" title="Editado por <?php echo htmlspecialchars((string) $c->modified_user, ENT_QUOTES, 'UTF-8'); ?>">editado em <?php echo data($c->modified); ?></small>
                  <?php } ?>
                </td>
                <td align="center"><?php echo !empty($c->created_user) ? htmlspecialchars($c->created_user, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
              </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>
    <?php } else { ?>
      <div class="text-center text-muted py-4">
        <i class="mdi mdi-account-box-outline fs-1 d-block mb-2"></i>
        <h5 class="mb-1">Nenhum contato disponível</h5>
        <p class="mb-0">Adicione a primeira pessoa de contato deste cliente.</p>
      </div>
    <?php } ?>
  </div>
</div>

<div class="card flex-fill">
  <div class="card-body py-3">
    <div class="row">
      <div class="col">
        <h5 class="card-title mb-1">Anexos</h5>
        <p class="text-muted mb-2">Faça upload de documentos e gerencie os anexos vinculados a este cliente.</p>
      </div>
      <div class="col-auto text-end">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal_anexo"><i class="mdi mdi-paperclip"></i> ANEXAR ARQUIVO</button>
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
              <tr data-anexo="<?php echo (int) $c->id; ?>">
                <td align="center">
                  <a class="btn btn-sm btn-outline-primary" target="_blank" href="<?php echo base_url() . $c->file; ?>" title="Abrir anexo"><i class="fa fa-external-link-alt"></i></a>
                  <button type="button" class="btn btn-sm btn-outline-danger js-excluir-anexo" data-id="<?php echo (int) $c->id; ?>" data-nome="<?php echo htmlspecialchars($c->name, ENT_QUOTES, 'UTF-8'); ?>" title="Excluir anexo"><i class="fa fa-trash"></i></button>
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
        <i class="mdi mdi-paperclip fs-1 d-block mb-2"></i>
        <h5 class="mb-1">Nenhum anexo disponível</h5>
        <p class="mb-0">Envie o primeiro arquivo para iniciar o histórico de anexos deste cliente.</p>
      </div>
    <?php } ?>
  </div>
</div>

<!-- modal contrato novo -->
<div class="modal fade" id="modal_contrato_novo" aria-hidden="true" style="display: none;">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <form name="form" method="POST" action="<?php echo base_url('contratos/post_novo'); ?>">
        <input type="hidden" name="id" value="<?php echo (int) $result->id; ?>">
        <div class="modal-header">
          <h5 class="modal-title">NOVO CONTRATO</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body m-1" style="min-height:0;">
          <div class="row">
            <div class="col-12 col-md-6">
              <div class="form-group mb-3">
                <label class="form-label">* Data</label>
                <input type="text" class="form-control" name="contract[created]" data-mask="00/00/0000" placeholder="dd/mm/aaaa" value="<?php echo date('d/m/Y'); ?>">
                <!-- <small class="text-muted">Retroaja ao lançar um contrato antigo: é a data que o dashboard usa como entrada.</small> -->
              </div>
            </div>
            <div class="col-12 col-md-6">
              <div class="form-group mb-3">
                <label class="form-label">* Ciclo de pagamento</label>
                <select class="form-control" name="contract[cycle]" required>
                  <option value="">-- Selecione --</option>
                  <?php foreach ($cycles as $alias => $rotulo) { ?>
                    <option value="<?php echo $alias; ?>"><?php echo $rotulo; ?></option>
                  <?php } ?>
                </select>
              </div>
            </div>
            <div class="col-12 col-md-6">
              <div class="form-group mb-3">
                <label class="form-label">* Valor (R$)</label>
                <input type="text" class="form-control moneymask" name="contract[value]" value="" placeholder="0,00">
              </div>
            </div>
            <div class="col-12 col-md-6">
              <div class="form-group mb-3">
                <label class="form-label">Espaço contratado (Gb)</label>
                <input type="number" min="0" step="0.01" class="form-control" name="contract[space_gb]" value="" placeholder="0">
              </div>
            </div>
            <div class="col-12">
              <div class="form-group mb-3">
                <label class="form-label">* Tipos de serviços <small class="text-muted">(marque um ou mais)</small></label>
                <?php if (!empty($service_types)) { ?>
                  <div class="border rounded p-2" style="max-height: 180px; overflow-y: auto;">
                    <?php foreach ($service_types as $tipo) { ?>
                      <div class="form-check form-switch">
                        <input class="form-check-input js-tipo-servico" type="checkbox" role="switch" name="contract[service_types][]" value="<?php echo (int) $tipo->id; ?>" id="ct_tipo_<?php echo (int) $tipo->id; ?>">
                        <label class="form-check-label" for="ct_tipo_<?php echo (int) $tipo->id; ?>"><?php echo htmlspecialchars($tipo->name, ENT_QUOTES, 'UTF-8'); ?></label>
                      </div>
                    <?php } ?>
                  </div>
                <?php } else { ?>
                  <div class="alert alert-warning mb-0"><div class="alert-message">Nenhum tipo de serviço ativo — cadastre em Gestão &rsaquo; Tipos de serviços.</div></div>
                <?php } ?>
              </div>
            </div>
            
            <div class="col-12">
              <div class="form-group mb-3">
                <label class="form-label">Observações</label>
                <textarea class="form-control" name="contract[comments]" rows="3" maxlength="1000"></textarea>
              </div>
            </div>
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
<!-- modal contrato novo -->

<!-- modal atividade -->
<div class="modal fade" id="modal_atividade" aria-hidden="true" style="display: none;">
  <div class="modal-dialog modal-md" role="document">
    <div class="modal-content">
      <form name="form" method="POST" action="<?php echo base_url('clientes/post_novaatividade'); ?>">
        <input type="hidden" name="id" value="<?php echo (int) $result->id; ?>">
        <div class="modal-header">
          <h5 class="modal-title">NOVA ATIVIDADE</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body m-1" style="min-height:0;">
          <div class="form-group mb-3">
            <label class="form-label">* Observações</label>
            <textarea class="form-control" name="description" rows="4" required placeholder="Ex.: Cliente ligou pedindo a segunda via do boleto..."></textarea>
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
<!-- modal atividade -->

<!-- modal contato -->
<div class="modal fade" id="modal_contato" aria-hidden="true" style="display: none;">
  <div class="modal-dialog modal-md" role="document">
    <div class="modal-content">
      <form name="form" method="POST" id="form_contato" action="<?php echo base_url('clientes/post_salvarcontato'); ?>">
        <input type="hidden" name="id" value="<?php echo (int) $result->id; ?>">
        <input type="hidden" name="id_contact" id="contato_id" value="">
        <div class="modal-header">
          <h5 class="modal-title" id="modal_contato_titulo">NOVO CONTATO</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body m-1" style="min-height:0;">
          <div class="form-group mb-3">
            <label class="form-label">* Tipo de contato</label>
            <select class="form-control" name="contact[type]" id="contato_tipo" required>
              <option value="">-- Selecione --</option>
              <?php foreach ($contact_types as $alias => $rotulo) { ?>
                <option value="<?php echo $alias; ?>"><?php echo $rotulo; ?></option>
              <?php } ?>
            </select>
          </div>
          <div class="form-group mb-3">
            <label class="form-label">* Nome</label>
            <input type="text" class="form-control" maxlength="150" required name="contact[name]" id="contato_nome" value="">
          </div>
          <div class="form-group mb-3">
            <label class="form-label">E-mail</label>
            <input type="email" class="form-control" maxlength="150" name="contact[email]" id="contato_email" value="">
          </div>
          <div class="form-group mb-3">
            <label class="form-label">Telefone/WhatsApp</label>
            <input type="text" class="form-control phonemask" name="contact[phone]" id="contato_telefone" value="" placeholder="(00) 00000-0000">
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
<!-- modal contato -->

<!-- modal anexo -->
<div class="modal fade" id="modal_anexo" aria-hidden="true" style="display: none;">
  <div class="modal-dialog modal-md" role="document">
    <div class="modal-content">
      <form enctype="multipart/form-data" name="form" method="POST" action="<?php echo base_url('clientes/post_sendfile'); ?>">
        <input type="hidden" name="id" value="<?php echo (int) $result->id; ?>">
        <div class="modal-header">
          <h5 class="modal-title">ANEXAR ARQUIVO</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body m-1" style="min-height:0;">
          <div class="form-group mb-3">
            <label class="form-label">* Nome</label>
            <input type="text" class="form-control" maxlength="150" required name="name" value="" placeholder="Ex.: Contrato assinado, RG, Comprovante...">
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
<!-- modal anexo -->

<?php // Modal do provedor na aba Faturas. Renderizado só quando há provedor
      // ativo — o mesmo desenho da tela de Faturas, e o mesmo endpoint. ?>
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

    var mascara89Dig = function(val) {
        return val.replace(/\D/g, '').length === 11 ? '(00) 00000-0000' : '(00) 0000-00009';
      },
      optionsTel = {
        onKeyPress: function(val, e, field, options) {
          field.mask(mascara89Dig.apply({}, arguments), options);
        }
      };
    $('.phonemask').mask(mascara89Dig, optionsTel);

    // Ao menos um tipo de serviço marcado (checkbox não tem required nativo
    // de grupo; a validação de verdade continua no servidor).
    $('#modal_contrato_novo form').on('submit', function(e) {
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

    // Editar contato: reaproveita o modal de novo contato, preenchido com os
    // dados da linha. Ao fechar, o modal volta ao modo "novo".
    $(document).on('click', '.js-editar-contato', function() {
      var $btn = $(this);
      $('#contato_id').val($btn.data('id'));
      $('#contato_tipo').val($btn.data('type'));
      $('#contato_nome').val($btn.data('nome'));
      $('#contato_email').val($btn.data('email'));
      $('#contato_telefone').val($btn.data('phone'));
      $('#modal_contato_titulo').text('EDITAR CONTATO');
      $('#modal_contato').modal('show');
    });

    $('#modal_contato').on('hidden.bs.modal', function() {
      $('#form_contato')[0].reset();
      $('#contato_id').val('');
      $('#modal_contato_titulo').text('NOVO CONTATO');
    });

    $(document).on('click', '.js-excluir-contato', function() {
      var $btn = $(this);
      var id = $btn.data('id');
      var nome = $btn.data('nome');

      Swal.fire({
        title: 'Excluir contato?',
        html: 'O contato <strong>' + nome + '</strong> será excluído definitivamente.',
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
          url: '<?php echo base_url('clientes/json_postdeletecontact'); ?>',
          data: {
            id: id
          },
          dataType: 'json',
          success: function(data) {
            if (sessaoExpirou(data)) return;
            if (!data || !data.return) {
              notificar('error', (data && data.message) ? data.message : 'Erro ao excluir o contato.');
              return;
            }
            $('tr[data-contato="' + id + '"]').remove();
            notificar('success', data.message || 'Contato excluído com sucesso.');
          },
          error: function(xhr) {
            console.log(xhr.responseText);
            notificar('error', 'Erro ao excluir o contato.');
          },
          complete: function() {
            $btn.prop('disabled', false);
          }
        });
      });
    });

    $(document).on('click', '.js-excluir-anexo', function() {
      var $btn = $(this);
      var id = $btn.data('id');
      var nome = $btn.data('nome');

      Swal.fire({
        title: 'Excluir anexo?',
        html: 'O anexo <strong>' + nome + '</strong> será excluído definitivamente.',
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
          url: '<?php echo base_url('clientes/json_postdeletefile'); ?>',
          data: {
            id: id
          },
          dataType: 'json',
          success: function(data) {
            if (sessaoExpirou(data)) return;
            if (!data || !data.return) {
              notificar('error', (data && data.message) ? data.message : 'Erro ao excluir o anexo.');
              return;
            }
            $('tr[data-anexo="' + id + '"]').remove();
            notificar('success', data.message || 'Anexo excluído com sucesso.');
          },
          error: function(xhr) {
            console.log(xhr.responseText);
            notificar('error', 'Erro ao excluir o anexo.');
          },
          complete: function() {
            $btn.prop('disabled', false);
          }
        });
      });
    });

    $('#btn_excluir').on('click', function() {
      Swal.fire({
        title: 'Excluir cliente?',
        html: 'O cadastro de <strong><?php echo htmlspecialchars(addslashes($result->name), ENT_QUOTES, 'UTF-8'); ?></strong> será excluído <strong>definitivamente</strong>.<br><small>Esta ação não pode ser desfeita.</small>',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#ccc',
        cancelButtonText: 'Cancelar',
        confirmButtonText: 'Excluir'
      }).then(function(result) {
        if (result.value) $('#form_excluir').submit();
      });
    });

    // ------------------------------------------------------------------
    // Aba Extrato Bom Controle — agregado dos contratos
    // vinculados; o vínculo em si é feito na tela de cada contrato.
    // ------------------------------------------------------------------
    var bcAtivo = <?php echo !empty($bomcontrole_ativo) ? 'true' : 'false'; ?>;
    var extratoClienteCarregado = false;

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

    function linhaExtratoCliente(item) {
      var badges = {
        quitado: 'bg-success',
        vencido: 'bg-danger',
        em_aberto: 'bg-warning'
      };
      var badge = badges[item.status] || 'bg-secondary';

      var contrato = item.contrato ?
        '<a href="<?php echo base_url('contratos/info?id='); ?>' + esc(item.contrato) + '">#' + esc(item.contrato) + '</a>' :
        '<span class="text-muted">—</span>';
      var nfse = item.link_nota_fiscal ?
        '<a class="btn btn-outline-primary btn-sm" href="' + esc(item.link_nota_fiscal) + '" target="_blank" rel="noopener">ABRIR</a>' :
        '<span class="text-muted">—</span>';
      var boleto = item.link_boleto ?
        '<a class="btn btn-outline-primary btn-sm" href="' + esc(item.link_boleto) + '" target="_blank" rel="noopener">ABRIR</a>' :
        '<span class="text-muted">—</span>';

      return '<tr>' +
        '<td class="text-center">' + contrato + '</td>' +
        '<td class="text-center"><span class="badge w-100 ' + badge + '">' + esc(item.status_rotulo) + '</span></td>' +
        '<td class="text-center">' + dataBr(item.vencimento) + '</td>' +
        '<td class="text-center">' + (item.dias_vencido > 0 ? esc(item.dias_vencido) : '—') + '</td>' +
        '<td class="text-end text-nowrap">' + moedaBr(item.valor) + '</td>' +
        '<td>' + (item.forma_pagamento ? esc(item.forma_pagamento) : '—') + '</td>' +
        '<td class="text-center">' + dataBr(item.data_pagamento) + '</td>' +
        '<td class="text-center">' + nfse + '</td>' +
        '<td class="text-center">' + boleto + '</td>' +
        '</tr>';
    }

    function renderExtratoCliente(data) {
      var $saida = $('#extrato_cliente_conteudo');

      if (!data.vinculado) {
        $saida.html('<div class="text-center text-muted py-5">' +
          '<i class="mdi mdi-cash-multiple fs-1 d-block mb-2"></i>' +
          '<h5 class="mb-1">Nenhum contrato vinculado ao Bom Controle</h5>' +
          '<p class="mb-0">O vínculo é feito na aba Extrato Bom Controle de cada contrato.</p></div>');
        return;
      }

      var html = '';
      if (data.avisos && data.avisos.length) {
        html += '<div class="alert alert-warning"><div class="alert-message">' + data.avisos.map(esc).join('<br>') + '</div></div>';
      }

      if (!data.itens || !data.itens.length) {
        $saida.html(html + '<div class="alert alert-secondary mb-0"><div class="alert-message">Nenhuma fatura no período consultado.</div></div>');
        return;
      }

      html += '<div class="table-responsive"><table class="table table-sm table-striped table-bordered table-hover mb-0"><thead><tr>' +
        '<th class="text-center">Contrato</th>' +
        '<th class="text-center" style="min-width:95px;">Status</th>' +
        '<th class="text-center">Vencimento</th>' +
        '<th class="text-center">Dias vencido</th>' +
        '<th class="text-end">Valor</th>' +
        '<th>Forma pgto</th>' +
        '<th class="text-center">Data pgto</th>' +
        '<th class="text-center">NFS-e</th>' +
        '<th class="text-center">Boleto</th>' +
        '</tr></thead><tbody>';
      data.itens.forEach(function(item) {
        html += linhaExtratoCliente(item);
      });
      html += '</tbody></table></div>';

      if (data.aviso_pagas) {
        html += '<p class="text-muted mt-2 mb-0"><small>As parcelas pagas vêm do financeiro do CLIENTE no Bom Controle — parcelas de contratos não vinculados aqui também podem aparecer.</small></p>';
      }

      $saida.html(html);
    }

    function carregarExtratoCliente(forcar) {
      if (!bcAtivo) return;
      if (extratoClienteCarregado && !forcar) return;
      extratoClienteCarregado = true;

      var $saida = $('#extrato_cliente_conteudo');
      $saida.html('<div class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Consultando o Bom Controle...</div>');

      $.ajax({
        type: 'POST',
        url: '<?php echo base_url('clientes/json_postextratobc'); ?>',
        data: {
          id: <?php echo (int) $result->id; ?>
        },
        dataType: 'json',
        success: function(data) {
          if (sessaoExpirou(data)) return;
          if (!data || !data.return || !data.data) {
            $saida.html($('<div class="alert alert-danger mb-0"><div class="alert-message"></div></div>')
              .find('.alert-message').text((data && data.message) ? data.message : 'Erro ao consultar o extrato.').end());
            extratoClienteCarregado = false; // deixa tentar de novo
            return;
          }
          renderExtratoCliente(data.data);
        },
        error: function(xhr) {
          console.log(xhr.responseText);
          $saida.html('<div class="alert alert-danger mb-0"><div class="alert-message">Erro de comunicação ao consultar o extrato.</div></div>');
          extratoClienteCarregado = false;
        }
      });
    }

    // Carga lazy: um AJAX na primeira abertura da aba; ATUALIZAR força recarga.
    $('a[href="#tab_extrato"]').on('shown.bs.tab', function() {
      carregarExtratoCliente(false);
    });

    $('#btn_atualizar_extrato_cliente').on('click', function() {
      carregarExtratoCliente(true);
    });

    // ------------------------------------------------------------------
    // Aba Faturas — as do CDW Finance, de todos os contratos do cliente
    // ------------------------------------------------------------------
    // Mesma paginação da aba do contrato; a diferença é a coluna Contrato,
    // que aqui é necessária (a lista mistura contratos) e lá seria uma coluna
    // com o mesmo valor em todas as linhas.
    var faturasClientePagina = 1;
    var faturasClienteCarregado = false;
    var faturasClienteCarregando = false;

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

    function linhaFaturaCliente(item, rotulos) {
      var sit = String(item.situation || '');
      var badge = badgeSituacao[sit] || 'bg-secondary';
      var rotulo = rotulos[sit] || sit;
      var competencia = String(item.competence || '').split('-');
      competencia = competencia.length === 3 ? competencia[1] + '/' + competencia[0] : '—';

      var contrato = '<a href="<?php echo base_url('contratos/info?id='); ?>' + encodeURIComponent(item.id_contract) + '">#' + esc(item.id_contract) + '</a>';

      return '<tr>' +
        '<td class="text-center">' + contrato + '</td>' +
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

    // Mesma regra da aba do contrato: "1/1" vira travessão, e a avulsa ganha
    // marcador porque cai no mesmo mês da recorrência.
    function parcelaRotulo(item) {
      var total = parseInt(item.installments_total, 10) || 1;
      if (total <= 1) return '<span class="text-muted">—</span>';
      return esc(item.installment_number) + '/' + esc(total);
    }

    function origemRotulo(item) {
      if (!item.id_charge || parseInt(item.id_charge, 10) === 0) return '';
      return ' <span class="badge bg-light text-dark border">avulsa</span>';
    }

    function renderFaturasCliente(data) {
      var $saida = $('#faturas_cliente_conteudo');
      var rotulos = data.situations || {};
      registrosRotulos = data.registrations || {};

      if (!data.total) {
        $saida.html('<div class="text-center text-muted py-5">' +
          '<i class="mdi mdi-receipt-text-outline fs-1 d-block mb-2"></i>' +
          '<h5 class="mb-1">Nenhuma fatura</h5>' +
          '<p class="mb-0">Nenhum contrato deste cliente é faturado pelo CDW Finance ainda. A configuração fica no bloco Faturamento de cada contrato.</p></div>');
        return;
      }

      var html = '<div class="table-responsive"><table class="table table-sm table-striped table-bordered table-hover mb-2">' +
        '<thead><tr>' +
        '<th class="text-center">Contrato</th>' +
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
        html += linhaFaturaCliente(item, rotulos);
      });

      html += '</tbody></table></div>';
      html += rodapeFaturasCliente(data);

      $saida.html(html);
    }

    function rodapeFaturasCliente(data) {
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
          '<button type="button" class="btn btn-outline-secondary btn-faturas-cliente-pag" data-pagina="' + (data.pagina - 1) + '"' +
          (data.pagina <= 1 ? ' disabled' : '') + '><i class="mdi mdi-chevron-left"></i> ANTERIOR</button>' +
          '<button type="button" class="btn btn-outline-secondary" disabled>' + data.pagina + ' / ' + data.paginas + '</button>' +
          '<button type="button" class="btn btn-outline-secondary btn-faturas-cliente-pag" data-pagina="' + (data.pagina + 1) + '"' +
          (data.pagina >= data.paginas ? ' disabled' : '') + '>PRÓXIMA <i class="mdi mdi-chevron-right"></i></button>' +
          '</div>';
      }

      return '<div class="row align-items-center"><div class="col"><small>' + resumo + '</small></div>' +
        '<div class="col-auto">' + nav + '</div></div>';
    }

    function carregarFaturasCliente(pagina) {
      if (faturasClienteCarregando) return;
      faturasClienteCarregando = true;

      var $saida = $('#faturas_cliente_conteudo');
      $saida.html('<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>');

      $.ajax({
        url: '<?php echo base_url('clientes/json_postfaturas'); ?>',
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
            faturasClienteCarregado = false;
            return;
          }
          faturasClientePagina = data.data.pagina;
          faturasClienteCarregado = true;
          renderFaturasCliente(data.data);
        },
        error: function() {
          $saida.html('<div class="alert alert-danger mb-0"><div class="alert-message">Erro de comunicação ao consultar as faturas.</div></div>');
          faturasClienteCarregado = false;
        },
        complete: function() {
          faturasClienteCarregando = false;
        }
      });
    }

    $('a[href="#tab_faturas"]').on('shown.bs.tab', function() {
      if (!faturasClienteCarregado) carregarFaturasCliente(faturasClientePagina);
    });

    $('#btn_atualizar_faturas_cliente').on('click', function() {
      carregarFaturasCliente(faturasClientePagina);
    });

    $('#faturas_cliente_conteudo').on('click', '.btn-faturas-cliente-pag', function() {
      carregarFaturasCliente(parseInt($(this).data('pagina'), 10) || 1);
    });

    // Sincronização do cadastro com o Bom Controle, sob demanda.
    $('#btn_sincronizar_bc').on('click', function() {
      var $botao = $(this);
      var htmlOriginal = $botao.html();

      $botao.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1" role="status"></span> SINCRONIZANDO...');

      $.ajax({
        type: 'POST',
        url: '<?php echo base_url('clientes/json_postsincronizarbc'); ?>',
        data: {
          id: <?php echo (int) $result->id; ?>
        },
        dataType: 'json',
        success: function(data) {
          if (sessaoExpirou(data)) return;

          if (!data || !data.return) {
            Swal.fire('Atenção', (data && data.message) ? data.message : 'Não foi possível sincronizar com o Bom Controle.', 'warning');
            return;
          }

          // A legenda é atualizada com a data que o servidor devolveu, sem
          // recarregar a página.
          if (data.data && data.data.synced_label) {
            $('#txt_sincronizado_bc').find('small').text('Sincronizado com o Bom Controle em ' + data.data.synced_label + '.');
          }
          Swal.fire('Pronto', data.message, 'success');
        },
        error: function(xhr) {
          console.log(xhr.responseText);
          Swal.fire('Erro', 'Erro de comunicação ao sincronizar com o Bom Controle.', 'error');
        },
        complete: function() {
          $botao.prop('disabled', false).html(htmlOriginal);
        }
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
        if (typeof notificar === 'function') { notificar(tipo === 'success' ? 'success' : 'info', texto); return; }
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
