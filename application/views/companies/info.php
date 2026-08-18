<div class="row">
    <div class="col-md-4 col-xl-3">
        <div class="card mb-3">
            <div class="card-body  text-center">
                <a class="btn btn-secondary btn-sm mb-2" href="<?php echo base_url('empresas'); ?>">VOLTAR</a>
                <h4><?php echo $result->name; ?></h4>
                <hr>
                <div class="text-muted mb-2"><?php echo $result->byname; ?></div>
                <div>
                    <a class="btn btn-primary btn-sm mt-1" href="<?php echo base_url('empresas/editar?id=' . $result->id); ?>"><i class="mdi mdi-clipboard-edit"></i> EDITAR</a>
                    <a class="btn btn-outline-primary btn-sm mt-1" href="<?php echo base_url('empresas/chaves-api?id=' . $result->id); ?>"><i class="mdi mdi-key-variant"></i> CHAVES API</a>
                    <hr>
                    RESPONSÁVEL: <strong><?php echo $result->owner; ?></strong><br />
                    CELULAR: <strong><?php echo $result->owner_cellphone; ?></strong><br />
                </div>
            </div>
            <hr class="my-0">
            <ul class="list-group list-group-flush">
                <li class="list-group-item pb-1 pt-1"><small>Status</small><br /><?php echo $result->status_name; ?></li>
                <li class="list-group-item pb-1 pt-1"><small>CNPJ</small><br /><?php echo cnpj($result->cnpj); ?></li>
                <li class="list-group-item pb-1 pt-1"><small>Telefone</small><br /><?php echo (!empty($result->phone)) ? $result->phone : "-"; ?></li>
                <li class="list-group-item pb-1 pt-1"><small>E-mail contato</small><br /><?php echo $result->email; ?></li>
                <li class="list-group-item pb-1 pt-1"><small>Cidade</small><br /><?php echo $result->city_name . '/' . $result->state_uf; ?></li>
                <li class="list-group-item pb-1 pt-1"><small>Data cadastro</small><br /><?php echo date("d/m/Y H:i", strtotime($result->created)); ?></li>
                <li class="list-group-item pb-1 pt-1"><small>Última Modificação</small><br /><?php echo (!empty($result->modified)) ? date("d/m/Y H:i", strtotime($result->modified)) : "-"; ?></li>
            </ul>
        </div>
    </div>
    <div class="col-md-8 col-xl-9">
        <div class="row">
            <div class="col-12">
                <?php if ($result->id_status == 4) { ?>
                    <div class="alert alert-warning alert-outline alert-dismissible" role="alert">
                        <button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button>
                        <div class="alert-icon"><i class="far fa-fw fa-bell"></i></div>
                        <div class="alert-message">
                            <strong>ATIVAR CADASTRO</strong><br /> Clique no botão abaixo para efetivar o cadastro da empresa.
                            <form id="form_post" method="POST" action="<?php echo base_url('empresas/post_ativarcadastro'); ?>" name="form" enctype="multipart/form-data">
                                <input type="hidden" name="id" value="<?php echo $result->id; ?>">
                                <button type="submit" id="submit_form" style="display: none;"><i class="mdi mdi-content-save"></i> ENVIAR </button>
                            </form>
                            <button type="submit" id="save" class="btn btn-sm mt-2 btn-primary">ATIVAR </button>
                        </div>
                    </div>
                <?php } ?>
            </div>

            <div class="tab">
                <ul class="nav nav-tabs mt-2" role="tablist">
                    <li class="nav-item"><a class="nav-link active" href="#tab1" data-bs-toggle="tab" role="tab" aria-selected="true">Atividades <?php if (count($notes) > 0) echo '(' . count($notes) . ')'; ?></a></li>
                    <li class="nav-item"><a class="nav-link" href="#tab2" data-bs-toggle="tab" role="tab">Usuários <?php if (count($users) > 0) echo '(' . count($users) . ')'; ?></a></li>
                    <li class="nav-item"><a class="nav-link" href="#tab_bomcontrole" data-bs-toggle="tab" role="tab">Bom Controle</a></li>
                    <li class="nav-item"><a class="nav-link" href="#tab_psp" data-bs-toggle="tab" role="tab">Cobrança (PSP)</a></li>
                    <li class="nav-item"><a class="nav-link" href="#tab3" data-bs-toggle="tab" role="tab">Histórico <?php if (count($logs) > 0) echo '(' . count($logs) . ')'; ?></a></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane active" id="tab1" role="tabpanel">
                        <button data-bs-toggle="modal" data-bs-target="#modal_anotacao" class="btn btn-primary btn-sm">NOVA ATIVIDADE</button>
                        <hr>
                        <?php if (!empty($notes)) { ?>
                            <ul class="timeline mt-2 mb-0">
                                <?php foreach ($notes as $c) { ?>
                                    <li class="timeline-item mb-3">
                                        <strong><?php echo $c->created_user; ?></strong> - <?php echo $c->created_company_byname; ?>
                                        <span class="float-end text-muted text-sm"><?php echo date("d/m/Y H:i", strtotime($c->created)); ?></span>
                                        <p><?php echo $c->description; ?></p>
                                    </li>
                                <?php } ?>
                            </ul>
                        <?php } else { ?>
                            <div class="alert alert-secondary alert-dismissible" role="alert">
                                <button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button>
                                <div class="alert-message">
                                    Nenhum registro encontrado.
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                    <div class="tab-pane" id="tab2" role="tabpanel">
                        <button data-bs-toggle="modal" data-bs-target="#modal_usuario" class="btn btn-primary btn-sm mb-3">NOVO USUÁRIO</button>
                        <?php if (!empty($users)) { ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-bordered table-hover">
                                    <thead>
                                        <tr>
                                            <th class="text-center">Ações</th>
                                            <th>Nome</th>
                                            <th>Celular</th>
                                            <th>Permissão de acesso</th>
                                            <th>Último login</th>
                                            <th>Situação</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($users as $c) {  ?>
                                            <tr class="odd gradeX">
                                                <td align="center">
                                                    <div class="form-check form-switch d-flex justify-content-center m-0">
                                                        <input class="form-check-input switch-user-status" type="checkbox" role="switch" id="switch_usuario_<?php echo $c->id; ?>" data-id="<?php echo $c->id; ?>" aria-label="Ativar ou desativar usuário" <?php echo ($c->id_status == 1) ? 'checked' : ''; ?>>
                                                    </div>
                                                </td>
                                                <td><a href="<?php echo base_url('usuarios/editar?id=') . $c->id . '&source=company'; ?>"><?php echo $c->name; ?></a><br />
                                                    <small><?php echo $c->email; ?></small>
                                                </td>
                                                <td><?php echo (!empty($c->cellphone)) ? $c->cellphone : "-"; ?></td>
                                                <td><a href="<?php echo base_url('usuarios/editar?id=' . $c->id_permission . '&source=company'); ?>"><?php echo $c->permission_name; ?></a></td>
                                                <td><?php echo (!empty($c->last_login)) ? data($c->last_login) : "Nunca acessou"; ?></td>
                                                <td><span class="badge w-100 bg-<?php echo $c->status_color; ?>" data-user-status-badge data-base-class="badge w-100"><?php echo $c->status_name; ?></span></td>
                                            </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php } else { ?>
                            <div class="alert alert-secondary alert-dismissible" role="alert">
                                <button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button>
                                <div class="alert-message">
                                    Nenhum registro encontrado.
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                    <div class="tab-pane" id="tab_bomcontrole" role="tabpanel">
                        <?php if (empty($crypto_ready)) { ?>
                            <div class="alert alert-danger" role="alert">
                                <div class="alert-message">
                                    A chave de criptografia (<code>secret_crypto_key</code>) não está configurada — a chave da API não pode ser gravada até isso ser resolvido.
                                </div>
                            </div>
                        <?php } ?>
                        <form method="POST" action="<?php echo base_url('empresas/post_bomcontrole'); ?>" name="form_bomcontrole">
                            <input type="hidden" name="id" value="<?php echo (int) $result->id; ?>">
                            <div class="form-check form-switch mb-3">
                                <?php // O hidden garante que "desmarcado" chegue como 0 — checkbox ausente e desmarcado seriam iguais. ?>
                                <input type="hidden" name="bomcontrole[bomcontrole_active]" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" id="bomcontrole_active" name="bomcontrole[bomcontrole_active]" value="1" <?php if (!empty($result->bomcontrole_active)) echo 'checked'; ?>>
                                <label class="form-check-label" for="bomcontrole_active">Integração com o Bom Controle ativa para esta empresa</label>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="bomcontrole_base_url">Base URL da API</label>
                                <input type="text" class="form-control" id="bomcontrole_base_url" name="bomcontrole[bomcontrole_base_url]" value="<?php echo htmlspecialchars((string) $result->bomcontrole_base_url, ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://apinewintegracao.bomcontrole.com.br" maxlength="255">
                                <small class="text-muted">Em branco, usa a URL padrão da API.</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="bomcontrole_api_key">Chave da API (ApiKey)</label>
                                <?php if (!empty($bomcontrole_key_set)) { ?>
                                    <span class="badge bg-success ms-1">Chave cadastrada</span>
                                <?php } else { ?>
                                    <span class="badge bg-secondary ms-1">Nenhuma chave cadastrada</span>
                                <?php } ?>
                                <?php // O olho é o ÚNICO controle: com o campo em branco ele busca a chave salva
                                // em json_postrevelarbomcontrole (o valor não vem no HTML da página); com algo
                                // digitado, só mostra/esconde o que está no campo. data-key-set diz ao JS
                                // se existe chave a buscar — mesmo desenho do olho da chave Ninjas.
                                ?>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="bomcontrole_api_key" name="bomcontrole_api_key" maxlength="255" autocomplete="new-password" data-lpignore="true" data-form-type="other" placeholder="<?php echo !empty($bomcontrole_key_set) ? 'Preencha apenas para alterar a chave atual' : 'Informe a chave da API do Bom Controle'; ?>">
                                    <button type="button" class="btn btn-outline-secondary" id="btn_toggle_bomcontrole_api_key" data-key-set="<?php echo !empty($bomcontrole_key_set) ? '1' : '0'; ?>" title="Ver chave salva" aria-label="Ver chave salva">
                                        <i class="mdi mdi-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text text-warning d-none" id="aviso_revelado_bomcontrole">
                                    <i class="fa fa-exclamation-triangle"></i> Chave salva visível. Clique no olho de novo para ocultá-la e limpar o campo — em branco, o SALVAR mantém a chave atual.
                                </div>
                                <small class="text-muted">A chave é gravada cifrada e nunca volta para a tela — por isso o campo nasce sempre em branco. Em branco = manter a chave atual.</small>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">SALVAR</button>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="btn_testar_bomcontrole" <?php if (empty($bomcontrole_key_set)) echo 'disabled'; ?>>TESTAR CONEXÃO</button>
                        </form>
                        <div id="resultado_teste_bomcontrole" class="mt-3"></div>
                        <div class="alert alert-info mt-3 mb-0" role="alert">
                            <div class="alert-message">
                                A chave é gerada no próprio Bom Controle e vale só para esta empresa. Com a integração ativa, os contratos dos clientes desta empresa podem ser vinculados aos contratos do Bom Controle e o Extrato Bom Controle passa a ser consultado por lá.
                                <a href="https://documenter.getpostman.com/view/1797561/SWT7BKWo?version=latest" target="_blank" rel="noopener">Documentação da API</a>.
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane" id="tab_psp" role="tabpanel">
                        <?php if (empty($crypto_ready)) { ?>
                            <div class="alert alert-danger" role="alert">
                                <div class="alert-message">
                                    A chave de criptografia (<code>secret_crypto_key</code>) não está configurada — o Client Secret não pode ser gravado até isso ser resolvido.
                                </div>
                            </div>
                        <?php } ?>
                        <p class="text-muted">
                            O PSP é quem registra a cobrança (boleto + PIX) e avisa quando o cliente paga.
                            A escolha é <strong>por contrato</strong>, então mais de um provedor pode ficar ativo aqui ao mesmo tempo.
                        </p>
                        <?php foreach ($psp_providers as $psp_slug => $psp_def) {
                            $psp_conta = isset($psp_accounts[$psp_slug]) ? $psp_accounts[$psp_slug] : NULL;
                            $psp_tem_secret = !empty($psp_conta) && (string) $psp_conta->client_secret !== '';
                            $psp_tem_cert = !empty($psp_conta) && (string) $psp_conta->cert_path !== '';
                            $psp_dias = $this->psp_model->diasParaVencerCertificado($psp_conta);
                            $psp_pronto = $psp_tem_secret && $psp_tem_cert;
                        ?>
                            <div class="card mb-3">
                                <div class="card-header d-flex align-items-center justify-content-between">
                                    <strong><?php echo htmlspecialchars($psp_def['nome'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <?php if (!empty($psp_conta) && (int) $psp_conta->active === 1) { ?>
                                        <span class="badge bg-success">Ativo</span>
                                    <?php } else { ?>
                                        <span class="badge bg-secondary">Inativo</span>
                                    <?php } ?>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="<?php echo base_url('empresas/post_psp'); ?>" enctype="multipart/form-data">
                                        <input type="hidden" name="id" value="<?php echo (int) $result->id; ?>">
                                        <input type="hidden" name="psp" value="<?php echo htmlspecialchars($psp_slug, ENT_QUOTES, 'UTF-8'); ?>">

                                        <div class="form-check form-switch mb-3">
                                            <?php // O hidden garante que "desmarcado" chegue como 0 — checkbox ausente e desmarcado seriam iguais. ?>
                                            <input type="hidden" name="psp_config[active]" value="0">
                                            <input class="form-check-input" type="checkbox" role="switch" id="psp_active_<?php echo $psp_slug; ?>" name="psp_config[active]" value="1" <?php if (!empty($psp_conta) && (int) $psp_conta->active === 1) echo 'checked'; ?>>
                                            <label class="form-check-label" for="psp_active_<?php echo $psp_slug; ?>">Integração ativa para esta empresa</label>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-4 mb-3">
                                                <label class="form-label" for="psp_env_<?php echo $psp_slug; ?>">Ambiente</label>
                                                <select class="form-select" id="psp_env_<?php echo $psp_slug; ?>" name="psp_config[environment]">
                                                    <option value="sandbox" <?php if (empty($psp_conta) || (string) $psp_conta->environment !== 'producao') echo 'selected'; ?>>Sandbox (homologação)</option>
                                                    <option value="producao" <?php if (!empty($psp_conta) && (string) $psp_conta->environment === 'producao') echo 'selected'; ?>>Produção</option>
                                                </select>
                                                <small class="text-muted">Sandbox e produção têm credenciais diferentes.</small>
                                            </div>
                                            <div class="col-md-8 mb-3">
                                                <label class="form-label" for="psp_client_id_<?php echo $psp_slug; ?>">Client ID</label>
                                                <input type="text" class="form-control" id="psp_client_id_<?php echo $psp_slug; ?>" name="psp_config[client_id]" maxlength="255" value="<?php echo htmlspecialchars(!empty($psp_conta) ? (string) $psp_conta->client_id : '', ENT_QUOTES, 'UTF-8'); ?>">
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label" for="psp_client_secret_<?php echo $psp_slug; ?>">Client Secret</label>
                                            <?php if ($psp_tem_secret) { ?>
                                                <span class="badge bg-success ms-1">Segredo cadastrado</span>
                                            <?php } else { ?>
                                                <span class="badge bg-secondary ms-1">Nenhum segredo cadastrado</span>
                                            <?php } ?>
                                            <div class="input-group">
                                                <input type="password" class="form-control" id="psp_client_secret_<?php echo $psp_slug; ?>" name="psp_client_secret" maxlength="255" autocomplete="new-password" data-lpignore="true" data-form-type="other" placeholder="<?php echo $psp_tem_secret ? 'Preencha apenas para alterar o segredo atual' : 'Informe o Client Secret'; ?>">
                                                <button type="button" class="btn btn-outline-secondary btn-toggle-psp-secret" data-psp="<?php echo $psp_slug; ?>" data-key-set="<?php echo $psp_tem_secret ? '1' : '0'; ?>" title="Ver segredo salvo" aria-label="Ver segredo salvo">
                                                    <i class="mdi mdi-eye"></i>
                                                </button>
                                            </div>
                                            <div class="form-text text-warning d-none" id="aviso_revelado_psp_<?php echo $psp_slug; ?>">
                                                <i class="fa fa-exclamation-triangle"></i> Segredo salvo visível. Clique no olho de novo para ocultá-lo e limpar o campo — em branco, o SALVAR mantém o atual.
                                            </div>
                                            <small class="text-muted">Gravado cifrado e nunca devolvido para a tela — por isso o campo nasce sempre em branco.</small>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label" for="psp_conta_<?php echo $psp_slug; ?>">Conta corrente <span class="text-muted">(opcional)</span></label>
                                            <input type="text" class="form-control" id="psp_conta_<?php echo $psp_slug; ?>" name="psp_config[conta_corrente]" maxlength="30" value="<?php
                                                $psp_extra = !empty($psp_conta) ? json_decode((string) $psp_conta->extra, TRUE) : [];
                                                echo htmlspecialchars(is_array($psp_extra) && isset($psp_extra['conta_corrente']) ? (string) $psp_extra['conta_corrente'] : '', ENT_QUOTES, 'UTF-8');
                                            ?>">
                                            <small class="text-muted">Só é necessária quando a mesma integração atende mais de uma conta — em branco, o banco usa a conta padrão.</small>
                                        </div>

                                        <hr>

                                        <div class="mb-2">
                                            <label class="form-label mb-0">Certificado (mTLS)</label>
                                            <?php if ($psp_tem_cert) { ?>
                                                <span class="badge bg-success ms-1">Certificado cadastrado</span>
                                                <?php if ($psp_dias !== NULL) { ?>
                                                    <?php if ($psp_dias < 0) { ?>
                                                        <span class="badge bg-danger ms-1">VENCIDO em <?php echo date('d/m/Y', strtotime((string) $psp_conta->cert_expires_at)); ?></span>
                                                    <?php } elseif ($psp_dias <= 30) { ?>
                                                        <span class="badge bg-warning text-dark ms-1">Vence em <?php echo (int) $psp_dias; ?> dia(s)</span>
                                                    <?php } else { ?>
                                                        <span class="badge bg-light text-dark ms-1">Válido até <?php echo date('d/m/Y', strtotime((string) $psp_conta->cert_expires_at)); ?></span>
                                                    <?php } ?>
                                                <?php } ?>
                                            <?php } else { ?>
                                                <span class="badge bg-secondary ms-1">Nenhum certificado</span>
                                            <?php } ?>
                                        </div>
                                        <?php if ($psp_dias !== NULL && $psp_dias < 0) { ?>
                                            <div class="alert alert-danger py-2" role="alert">
                                                <div class="alert-message">
                                                    O certificado venceu — <strong>todas</strong> as cobranças desta empresa neste provedor param até ele ser renovado no banco e reenviado aqui.
                                                </div>
                                            </div>
                                        <?php } ?>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label" for="psp_cert_<?php echo $psp_slug; ?>">Arquivo .crt</label>
                                                <input type="file" class="form-control" id="psp_cert_<?php echo $psp_slug; ?>" name="psp_cert" accept=".crt,.pem,.cer">
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label class="form-label" for="psp_key_<?php echo $psp_slug; ?>">Arquivo .key</label>
                                                <input type="file" class="form-control" id="psp_key_<?php echo $psp_slug; ?>" name="psp_key" accept=".key,.pem">
                                            </div>
                                        </div>
                                        <small class="text-muted d-block mb-3">
                                            Os dois arquivos são enviados juntos: o sistema confere que a chave corresponde ao certificado antes de gravar, e lê dele a data de validade.
                                            Em branco, o par atual é mantido. A chave privada nunca é exibida depois de gravada.
                                        </small>

                                        <button type="submit" class="btn btn-primary btn-sm">SALVAR</button>
                                        <button type="button" class="btn btn-outline-primary btn-sm btn-testar-psp" data-psp="<?php echo $psp_slug; ?>" <?php if (!$psp_pronto) echo 'disabled'; ?>>TESTAR CONEXÃO</button>
                                        <?php if (!$psp_pronto) { ?>
                                            <small class="text-muted ms-2">O teste exige Client Secret e certificado cadastrados.</small>
                                        <?php } ?>
                                    </form>
                                    <div class="resultado-teste-psp mt-3" data-psp="<?php echo $psp_slug; ?>"></div>
                                </div>
                            </div>
                        <?php } ?>
                        <div class="alert alert-info mb-0" role="alert">
                            <div class="alert-message">
                                As credenciais são geradas no painel do próprio banco, por integração, com os escopos de cobrança marcados na criação — e valem só para esta empresa.
                                O envio automático do boleto e a baixa por webhook ainda não estão implementados; por ora esta aba só guarda e testa a credencial.
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane" id="tab3" role="tabpanel">
                        <?php if (!empty($logs)) { ?>
                            <ul class="timeline mt-2 mb-0">
                                <?php foreach ($logs as $c) { ?>
                                    <li class="timeline-item mb-3">
                                        <strong><?php echo $c->created_user; ?></strong> - <?php echo $c->created_company_byname; ?>
                                        <span class="float-end text-muted text-sm"><?php echo nice_date($c->created, 'd/m/Y H:i'); ?></span>
                                        <p><?php echo $c->description; ?></p>
                                    </li>
                                <?php } ?>
                            </ul>
                        <?php } else { ?>
                            <div class="alert alert-secondary alert-dismissible" role="alert">
                                <button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button>
                                <div class="alert-message">
                                    Nenhum registro encontrado.
                                </div>
                            </div>
                        <?php } ?>
                    </div>

                </div>
            </div>
        </div>

        <!-- Modal: detalhes da cotação + minha proposta -->
        <div class="modal fade" id="modalMinhaCotacao" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="mdi mdi-clipboard-text-outline"></i>
                            Cotação Nº <span id="mmc-pedido">—</span>
                            <span id="mmc-status-badge" class="badge ms-2">—</span>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <h6 class="text-muted text-uppercase small">Dados da cotação</h6>
                                <table class="table table-sm table-striped">
                                    <tbody>
                                        <tr>
                                            <td><strong>Vendedor</strong></td>
                                            <td id="mmc-vendedor">—</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Cliente</strong></td>
                                            <td id="mmc-cliente">—</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Origem</strong></td>
                                            <td id="mmc-origem">—</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Destino</strong></td>
                                            <td id="mmc-destino">—</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Valor NF</strong></td>
                                            <td id="mmc-valor-nf">—</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Cubagem</strong></td>
                                            <td id="mmc-cubagem">—</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Peso</strong></td>
                                            <td id="mmc-peso">—</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Volumes</strong></td>
                                            <td id="mmc-volumes">—</td>
                                        </tr>
                                        <tr>
                                            <td><strong>Observações</strong></td>
                                            <td id="mmc-obs">—</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-12 col-md-6">
                                <h6 class="text-muted text-uppercase small">Minha proposta</h6>
                                <div class="card border-primary">
                                    <div class="card-body">
                                        <table class="table table-sm mb-0">
                                            <tbody>
                                                <tr>
                                                    <td><strong>Valor do frete</strong></td>
                                                    <td id="mmc-meu-valor" class="text-end">—</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Prazo entrega</strong></td>
                                                    <td id="mmc-meu-prazo" class="text-end">—</td>
                                                </tr>
                                                <tr>
                                                    <td><strong>Enviada em</strong></td>
                                                    <td id="mmc-meu-envio" class="text-end">—</td>
                                                </tr>
                                                <tr>
                                                    <td colspan="2"><strong>Observações</strong><br><span id="mmc-meu-obs" class="text-muted">—</span></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <div id="mmc-resultado-box" class="alert mt-3 mb-0 d-none" role="alert"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="mdi mdi-close"></i> Fechar</button>
                    </div>
                </div>
            </div>
        </div>


        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header pb-0">
                        <h5 class="card-title mb-0 float-start">ANEXOS</h5>
                        <button type="button" data-bs-toggle="modal" data-bs-target="#modal_enviar_arquivo" class="btn btn-sm btn-primary float-end">NOVO DOCUMENTO</a>
                    </div>
                    <div class="card-body pt-0">
                        <hr>
                        <?php if (!empty($files)) { ?>
                            <ul class="timeline mt-2 mb-0">
                                <?php foreach ($files as $c) { ?>
                                    <li class="timeline-item mb-3" data-file="<?php echo $c->file; ?>">
                                        <strong><?php echo $c->name; ?></strong> <span class="text-muted mt-0 mb-2 text-sm">Em <?php echo nice_date($c->created, 'd/m/Y H:i');
                                                                                                                                $user = $this->global_model->getWhere_off('crm_users',  ['id' => $c->created_by], TRUE); ?>
                                            <br />Enviado por: <strong> <?php echo $user->name; ?></strong> </span><br />
                                        <a href="<?php echo base_url() . $c->file; ?>" target="blank" class="btn btn-primary btn-sm my-1">ABRIR</a>
                                        <button type="button" data-file="<?php echo $c->file; ?>" class="btn btn-danger btn-sm my-1 excluir">EXCLUIR</button>
                                    </li>
                                <?php } ?>
                            </ul>
                        <?php } else { ?>
                            <div class="alert alert-secondary alert-dismissible" role="alert">
                                <button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button>
                                <div class="alert-message">
                                    Nenhum registro encontrado.
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- modal anotação -->
<div class="modal fade" id="modal_anotacao" aria-hidden="true" style="display: none;">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">NOVA ATIVIDADE</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form name="form" method="POST" action="<?php echo base_url('empresas/post_novaanotacao') ?>">
                <input type="hidden" name="id_company" value="<?php echo $result->id; ?>">
                <input type="hidden" name="url" value="<?php echo current_url() . '?id=' . $result->id; ?>">
                <div class="modal-body m-1">
                    <div class="form-group mb-3">
                        <label class="form-label">* Descrição</label>
                        <textarea class="form-control" required="" name="description" rows="4"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="row w-100">
                        <div class="col">
                            <button type="submit" class="btn w-100 btn-primary"><i class="mdi mdi-content-save"></i> SALVAR</button>
                        </div>
                        <div class="col"></div>
                        <div class="col">
                            <button type="button" class="btn w-100 btn-outline-secondary" data-bs-dismiss="modal"><i class="fa fa-times"></i> FECHAR</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- modal anotação -->

<!-- modal usuario -->
<div class="modal fade" id="modal_usuario" aria-hidden="true" style="display: none;">
    <div class="modal-dialog modal-lg" role="document">
        <form method="POST" action="<?php echo base_url('usuarios/post_usernew'); ?>" name="form" enctype="multipart/form-data">
            <input type="hidden" name="user[id_company]" value="<?php echo $result->id; ?>">
            <input type="hidden" name="url" value="<?php echo current_url() . '?id=' . $result->id; ?>">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">NOVO USUÁRIO</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body m-1">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label class="form-label">* Nome completo</label>
                                <input type="text" class="form-control" name="user[name]" maxlength="150" value="" required="">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label class="form-label">* E-mail</label>
                                <input type="email" class="form-control" name="user[email]" value="">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label class="form-label">Celular</label>
                                <input type="text" class="form-control phonemask" name="user[cellphone]" value="">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label mb-3>Permissão de acesso</label>
                                <select class="form-control select2" name="user[id_permission]" data-dropdown-parent="#modal_usuario">
                                    <?php foreach ($permissions as $c) { ?>
                                        <option value="<?php echo $c->id; ?>"><?php echo $c->name; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label> Grupo de empresas</label>
                                <select class="form-control select2" name="user[id_group]" data-dropdown-parent="#modal_usuario">
                                    <option selected="" value="0">NÃO SE APLICA</option>
                                    <?php foreach ($crm_user_groups as $c) { ?>
                                        <option value="<?php echo $c->id; ?>"><?php echo $c->name; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-12">
                            <hr>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label><span class="required" aria-required="true"> * </span> Senha</label>
                                <input minlength="8" type="password" class="form-control" name="passdf">
                                <small class="text-muted">(mínimo 8 caracteres com letras e números)</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label><span class="required" aria-required="true"> * </span> Confirmar senha</label>
                                <input minlength="8" type="password" class="form-control" name="passdf2">
                                <small class="text-muted">(mínimo 8 caracteres com letras e números)</small>
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
            </div>
        </form>
    </div>
</div>
<!-- modal usuario -->

<!--modal_enviar_arquivo-->
<div class="modal fade" id="modal_enviar_arquivo" aria-hidden="true" style="display: none;">
    <div class="modal-dialog  modal-md" role="document">
        <div class="modal-content">
            <form enctype="multipart/form-data" name="form" method="POST" action="<?php echo base_url('empresas/post_sendfiles') ?>">
                <input type="hidden" name="id" value="<?php echo $result->id; ?>">
                <div class="modal-header">
                    <h5 class="modal-title">ENVIAR ARQUIVO</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body m-1" style="min-height:0;">
                    <div class="form-group mb-3">
                        <label>* Nome</label>
                        <input type="text" class="form-control" maxlength="100" required="" name="name" value="">
                    </div>
                    <div class="form-group mb-3">
                        <label>* Selecionar arquivo <small>(tamanho máximo de 32Mb)</small></label>
                        <input type="file" required="" name="file" class="form-control" accept=".jpg,.jpeg,.png,.pdf,.xls,xlsx,.doc,.docx">
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

<script>
    document.addEventListener("DOMContentLoaded", function() {
        function handleRedirect(data) {
            if (data && data.redirect) {
                window.location.replace('<?php echo base_url(); ?>painel/sair_custom');
                return true;
            }
            return false;
        }

        $("#save").click(function() {
            Swal.fire({
                title: 'Confirma inserir documento?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#ccc',
                cancelButtonText: 'Cancelar',
                confirmButtonText: 'Sim'
            }).then((result) => {
                if (result.value) {
                    $("#submit_form").click();
                    $('#form_post').submit(function(e) {
                        e.preventDefault;
                        this.submit();
                        $("#save").attr("disabled", true);
                    });
                }
            })
        });
        $(".excluir").click(function() {
            Swal.fire({
                title: 'Confirma exclusão do arquivo?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#ccc',
                cancelButtonText: 'Cancelar',
                confirmButtonText: 'Sim'
            }).then((result) => {
                if (result.value) {
                    var id_company = <?php echo $result->id; ?>;
                    var file = $(this).attr("data-file");
                    var formData = {
                        id_company: id_company,
                        file: file
                    };
                    $.ajax({
                        type: "POST",
                        data: formData,
                        url: "<?php echo base_url(); ?>/empresas/json_postdeletefile",
                        success: function(data) {
                            $('li[data-file="' + file + '"]').remove();
                        },
                        error: function(xhr, ajaxOptions, thrownError) {
                            console.log(xhr.responseText);
                        }
                    });
                }
            });
        });
        function notifyUserStatus(type, message) {
            window.notyf.open({
                type: type,
                message: message,
                duration: 5000,
                ripple: true,
                dismissible: true,
                position: {
                    x: 'top',
                    y: 'top'
                }
            });
        }

        $(document).on('change', '.switch-user-status', function() {
            var $sw = $(this);
            var $row = $sw.closest('tr');
            var id = $sw.data('id');
            var ativar = $sw.is(':checked') ? 1 : 0;

            $sw.prop('disabled', true);
            $.ajax({
                type: 'POST',
                url: '<?php echo base_url('usuarios/json_postchangestatus'); ?>',
                data: {
                    id: id,
                    ativar: ativar
                },
                dataType: 'json',
                success: function(data) {
                    if (handleRedirect(data)) return;
                    if (!data || !data.return) {
                        $sw.prop('checked', !ativar);
                        notifyUserStatus('error', (data && data.message) ? data.message : 'Erro ao alterar situação.');
                        return;
                    }

                    if (data.data) {
                        var $badge = $row.find('[data-user-status-badge]');
                        var baseClass = $badge.data('baseClass') || 'badge w-100';
                        $badge.attr('class', baseClass + ' bg-' + data.data.status_color);
                        $badge.text(data.data.status_name);
                    }

                    notifyUserStatus('success', data.message || 'Situação alterada com sucesso.');
                },
                error: function(xhr) {
                    $sw.prop('checked', !ativar);
                    console.log(xhr.responseText);
                    notifyUserStatus('error', 'Erro ao alterar situação.');
                },
                complete: function() {
                    $sw.prop('disabled', false);
                }
            });
        });

        // As abas são client-side: sem isto, o redirect do salvar da aba Bom
        // Controle (que volta com #tab_bomcontrole) cairia sempre na primeira.
        if (window.location.hash) {
            var $abaAlvo = $('a[data-bs-toggle="tab"][href="' + window.location.hash + '"]');
            if ($abaAlvo.length && window.bootstrap && bootstrap.Tab) {
                bootstrap.Tab.getOrCreateInstance($abaAlvo[0]).show();
            }
        }

        // Olho da chave (mesmo desenho do olho da chave Ninjas em Parâmetros
        // Gerais): campo em branco + chave salva -> busca no servidor (o valor
        // não vem no HTML da página); campo preenchido -> só mostra/esconde o
        // que está digitado, sem ir ao servidor. Ocultar uma chave que veio do
        // servidor limpa o campo — é o estado que faz o SALVAR manter a atual;
        // ocultar o que foi digitado preserva.
        (function() {
            var $btn = $('#btn_toggle_bomcontrole_api_key');
            var $campo = $('#bomcontrole_api_key');
            if (!$btn.length || !$campo.length) {
                return;
            }

            var temChaveSalva = $btn.data('key-set') === 1 || $btn.data('key-set') === '1';
            // Distingue "visível porque veio do servidor" de "visível porque
            // foi digitado" — só o primeiro caso limpa o campo ao ocultar.
            var veioDoServidor = false;

            function pintarBotao(visivel, revelada) {
                $btn.find('i').removeClass('mdi-eye mdi-eye-off').addClass(visivel ? 'mdi-eye-off' : 'mdi-eye');
                var titulo = visivel ? 'Ocultar' : (temChaveSalva ? 'Ver chave salva' : 'Mostrar o que foi digitado');
                $btn.attr('title', titulo).attr('aria-label', titulo);
                $('#aviso_revelado_bomcontrole').toggleClass('d-none', !revelada);
            }

            // Editar a chave revelada a transforma em digitação: a partir daí
            // ocultar preserva o texto em vez de limpar.
            $campo.on('input', function() {
                if (veioDoServidor) {
                    veioDoServidor = false;
                    $('#aviso_revelado_bomcontrole').addClass('d-none');
                }
            });

            $btn.on('click', function() {
                var visivel = $campo.attr('type') === 'text';

                if (visivel) {
                    if (veioDoServidor) {
                        $campo.val('');
                        veioDoServidor = false;
                    }
                    $campo.attr('type', 'password');
                    pintarBotao(false, false);
                    return;
                }

                // Algo digitado: mostra sem consultar o servidor.
                if ($campo.val() !== '' || !temChaveSalva) {
                    $campo.attr('type', 'text');
                    pintarBotao(true, false);
                    return;
                }

                $btn.prop('disabled', true);

                $.ajax({
                    type: 'POST',
                    url: '<?php echo base_url('empresas/json_postrevelarbomcontrole'); ?>',
                    data: {
                        id: <?php echo (int) $result->id; ?>
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (handleRedirect(data)) return;
                        if (!data || !data.success) {
                            Swal.fire('Não foi possível exibir', (data && data.message) ? data.message : 'Falha ao ler a chave.', 'error');
                            return;
                        }
                        veioDoServidor = true;
                        $campo.val(data.data.bomcontrole_api_key).attr('type', 'text');
                        pintarBotao(true, true);
                    },
                    error: function(xhr) {
                        console.log(xhr.responseText);
                        Swal.fire('Erro', 'Falha ao ler a chave.', 'error');
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                    }
                });
            });
        })();

        // Testa a chave JÁ SALVA no servidor — nunca a digitada no campo, que
        // pode estar em branco porque o usuário quer manter a atual.
        $('#btn_testar_bomcontrole').on('click', function() {
            var $botao = $(this);
            var $saida = $('#resultado_teste_bomcontrole');

            $botao.prop('disabled', true);
            $saida.html('<div class="alert alert-info mb-0"><div class="alert-message">Testando a conexão com o Bom Controle...</div></div>');

            $.post('<?php echo base_url('empresas/json_posttestarbomcontrole'); ?>', {
                    id: <?php echo (int) $result->id; ?>
                }, null, 'json')
                .done(function(retorno) {
                    var classe = retorno && retorno.success ? 'alert-success' : 'alert-danger';
                    var texto = retorno && retorno.message ? retorno.message : 'Não foi possível interpretar a resposta.';
                    $saida.html($('<div class="alert mb-0"><div class="alert-message"></div></div>')
                        .addClass(classe).find('.alert-message').text(texto).end());
                })
                .fail(function() {
                    $saida.html('<div class="alert alert-danger mb-0"><div class="alert-message">Falha de comunicação ao testar a conexão.</div></div>');
                })
                .always(function() {
                    $botao.prop('disabled', false);
                });
        });

        // --- Aba Cobrança (PSP) ---------------------------------------
        // Os handlers são delegados por CLASSE, e não por id: a aba desenha um
        // card por provedor da allowlist, então PSP novo passa a funcionar sem
        // tocar neste JS.

        // Olho do Client Secret — mesmo desenho do olho do Bom Controle:
        // campo em branco + segredo salvo busca no servidor (o valor não vem
        // no HTML da página); campo preenchido só mostra/esconde o digitado.
        $('.btn-toggle-psp-secret').each(function() {
            var $btn = $(this);
            var psp = $btn.data('psp');
            var $campo = $('#psp_client_secret_' + psp);
            var $aviso = $('#aviso_revelado_psp_' + psp);
            if (!$campo.length) {
                return;
            }

            var temSegredoSalvo = $btn.data('key-set') === 1 || $btn.data('key-set') === '1';
            // Distingue "visível porque veio do servidor" de "visível porque
            // foi digitado" — só o primeiro caso limpa o campo ao ocultar.
            var veioDoServidor = false;

            function pintarBotao(visivel, revelado) {
                $btn.find('i').removeClass('mdi-eye mdi-eye-off').addClass(visivel ? 'mdi-eye-off' : 'mdi-eye');
                var titulo = visivel ? 'Ocultar' : (temSegredoSalvo ? 'Ver segredo salvo' : 'Mostrar o que foi digitado');
                $btn.attr('title', titulo).attr('aria-label', titulo);
                $aviso.toggleClass('d-none', !revelado);
            }

            $campo.on('input', function() {
                if (veioDoServidor) {
                    veioDoServidor = false;
                    $aviso.addClass('d-none');
                }
            });

            $btn.on('click', function() {
                var visivel = $campo.attr('type') === 'text';

                if (visivel) {
                    if (veioDoServidor) {
                        $campo.val('');
                        veioDoServidor = false;
                    }
                    $campo.attr('type', 'password');
                    pintarBotao(false, false);
                    return;
                }

                if ($campo.val() !== '' || !temSegredoSalvo) {
                    $campo.attr('type', 'text');
                    pintarBotao(true, false);
                    return;
                }

                $btn.prop('disabled', true);

                $.ajax({
                    type: 'POST',
                    url: '<?php echo base_url('empresas/json_postrevelarpsp'); ?>',
                    data: {
                        id: <?php echo (int) $result->id; ?>,
                        psp: psp
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (handleRedirect(data)) return;
                        if (!data || !data.success) {
                            Swal.fire('Não foi possível exibir', (data && data.message) ? data.message : 'Falha ao ler o segredo.', 'error');
                            return;
                        }
                        veioDoServidor = true;
                        $campo.val(data.data.psp_client_secret).attr('type', 'text');
                        pintarBotao(true, true);
                    },
                    error: function(xhr) {
                        console.log(xhr.responseText);
                        Swal.fire('Erro', 'Falha ao ler o segredo.', 'error');
                    },
                    complete: function() {
                        $btn.prop('disabled', false);
                    }
                });
            });
        });

        // Testa a credencial JÁ SALVA no servidor — nunca a digitada.
        $('.btn-testar-psp').on('click', function() {
            var $botao = $(this);
            var psp = $botao.data('psp');
            var $saida = $('.resultado-teste-psp[data-psp="' + psp + '"]');

            $botao.prop('disabled', true);
            $saida.html('<div class="alert alert-info mb-0"><div class="alert-message">Testando a conexão...</div></div>');

            $.post('<?php echo base_url('empresas/json_posttestarpsp'); ?>', {
                    id: <?php echo (int) $result->id; ?>,
                    psp: psp
                }, null, 'json')
                .done(function(retorno) {
                    var classe = retorno && retorno.success ? 'alert-success' : 'alert-danger';
                    var texto = retorno && retorno.message ? retorno.message : 'Não foi possível interpretar a resposta.';
                    $saida.html($('<div class="alert mb-0"><div class="alert-message"></div></div>')
                        .addClass(classe).find('.alert-message').text(texto).end());
                })
                .fail(function() {
                    $saida.html('<div class="alert alert-danger mb-0"><div class="alert-message">Falha de comunicação ao testar a conexão.</div></div>');
                })
                .always(function() {
                    $botao.prop('disabled', false);
                });
        });

    });
</script>
