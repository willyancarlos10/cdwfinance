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
  ['id' => 'tab_extrato', 'titulo' => 'Extrato financeiro', 'icone' => 'mdi-cash-multiple', 'texto' => 'Faturas, boletos e movimentações.'],
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
            <li class="nav-item"><a class="nav-link <?php if ($i === 0) echo 'active'; ?>" data-bs-toggle="tab" href="#<?php echo $aba['id']; ?>" role="tab"><?php echo $aba['titulo']; ?></a></li>
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
                  <a class="btn btn-sm btn-outline-primary" href="<?php echo base_url('contratos/info?id=' . (int) $ct->id); ?>" title="Abrir contrato"><i class="fa fa-eye"></i></a>
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
                <label class="form-label">* Data de criação</label>
                <input type="text" class="form-control" name="contract[created]" data-mask="00/00/0000" placeholder="dd/mm/aaaa" value="<?php echo date('d/m/Y'); ?>">
                <small class="text-muted">Retroaja ao lançar um contrato antigo: é a data que o dashboard usa como entrada.</small>
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
            <div class="col-12 col-md-6">
              <div class="form-group mb-3">
                <label class="form-label">Espaço contratado (Gb)</label>
                <input type="number" min="0" step="0.01" class="form-control" name="contract[space_gb]" value="" placeholder="0">
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
    // Aba Extrato financeiro (Bom Controle) — agregado dos contratos
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
          '<p class="mb-0">O vínculo é feito na aba Extrato financeiro de cada contrato.</p></div>');
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
  });
</script>


<?php $this->load->view("domains/whois_modal"); ?>
