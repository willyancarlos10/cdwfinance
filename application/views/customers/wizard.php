<!DOCTYPE html>
<html lang="pt-br">

<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <meta name="description" content="<?php echo $this->config->item('og_description'); ?>">
  <meta name="author" content="<?php echo $this->config->item('company'); ?>">
  <title><?php echo $this->config->item('title'); ?></title>
  <link rel="canonical" href="<?php echo current_url(); ?>" />
  <link rel="icon" href="<?php echo base_url('theme/custom/images/CDW-FAVICON-150x150.png'); ?>" sizes="32x32" />
  <link rel="icon" href="<?php echo base_url('theme/custom/images/CDW-FAVICON-300x300.png'); ?>" sizes="192x192" />
  <link rel="apple-touch-icon" href="<?php echo base_url('theme/custom/images/CDW-FAVICON-300x300.png'); ?>" />
  <meta name="msapplication-TileImage" content="<?php echo base_url('theme/custom/images/CDW-FAVICON-300x300.png'); ?>" />
  <?php $this->load->view('partials/auth_head'); ?>
  <style>
    /* Honeypot: fora da tela para humanos; robô que preenche é descartado. */
    .campo-site {
      position: absolute;
      left: -9999px;
      top: -9999px;
      height: 0;
      width: 0;
      overflow: hidden;
    }
  </style>
</head>

<body data-theme="default" data-layout="fluid" data-sidebar-position="left" data-sidebar-behavior="sticky">
  <div class="min-vh-100 w-100 d-flex flex-column">
    <div class="row flex-grow-1 g-0 justify-content-center">
      <div class="col-12 col-lg-10 col-xxl-8 bg-white overflow-auto align-items-center">
        <div class="container p-4 pb-5">
          <div class="text-center">
            <a href="<?php echo base_url(); ?>">
              <img src="<?php echo base_url() . 'theme/custom/images/logomarca.png'; ?>" alt="<?php echo $this->config->item('company'); ?>" style="max-width:280px;" class="img-fluid w-100 mb-3 mt-2">
            </a>
          </div>
          <div class="row d-flex justify-content-center">
            <div class="col-12">
              <?php
              echo empty(validation_errors()) ? '' : '<div class="alert alert-danger alert-dismissible" role="alert"><button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button><div class="alert-message">' . validation_errors() . '</div></div>';
              ?>
              <?php if ($this->session->flashdata('error') != null) { ?>
                <div class="alert alert-danger alert-dismissible" role="alert">
                  <button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button>
                  <div class="alert-icon">
                    <i class="far fa-fw fa-bell"></i>
                  </div>
                  <div class="alert-message"><?php echo $this->session->flashdata('error'); ?></div>
                </div>
              <?php } ?>

              <div class="text-center mb-4">
                <h3 class="mb-2">Cadastro de cliente</h3>
                <?php if (!empty($tenant)) { ?>
                  <p class="mb-2"><span class="badge bg-primary fs-6"><?php echo htmlspecialchars($tenant->byname, ENT_QUOTES, 'UTF-8'); ?></span></p>
                <?php } ?>
                <p class="text-muted mb-0">Preencha os dados abaixo para a elaboração do seu contrato de prestação de serviço.<br />As informações são tratadas com sigilo e acesso restrito.</p>
              </div>

              <form id="form_wizard" method="POST" action="<?php echo base_url('cadastro-cliente' . (!empty($token) ? '/' . $token : '')); ?>" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="<?php echo $this->security->get_csrf_token_name(); ?>" value="<?php echo $this->security->get_csrf_hash(); ?>">
                <input type="hidden" name="cnpj_autofill" id="cnpj_autofill" value="<?php echo set_value('cnpj_autofill', '0'); ?>">
                <div class="campo-site" aria-hidden="true">
                  <label for="website_confirm">Não preencha este campo</label>
                  <input type="text" name="website_confirm" id="website_confirm" tabindex="-1" autocomplete="off" value="">
                </div>

                <div id="wizard_cliente" class="wizard wizard-primary mb-3">
                  <ul class="nav">
                    <li class="nav-item"><a class="nav-link" href="#passo-1">Identificação</a></li>
                    <li class="nav-item"><a class="nav-link" href="#passo-2">Endereço</a></li>
                    <li class="nav-item"><a class="nav-link" href="#passo-3">Representante</a></li>
                    <li class="nav-item"><a class="nav-link" href="#passo-4">Complementares</a></li>
                    <li class="nav-item"><a class="nav-link" href="#passo-5">Revisão e envio</a></li>
                  </ul>

                  <div class="tab-content">
                    <!-- PASSO 1 — IDENTIFICAÇÃO -->
                    <div id="passo-1" class="tab-pane" role="tabpanel">
                      <div class="row pt-3">
                        <div class="col-md-12">
                          <div class="form-group text-center mb-3">
                            <div id="loading" style="display: none;">
                              <img src="<?php echo base_url('theme/custom/images/transfer.gif'); ?>" style="max-height: 30px;" /> Consultando CNPJ, aguarde...
                            </div>
                          </div>
                        </div>
                        <div class="col-12 col-sm-4">
                          <div class="form-group mb-3">
                            <label class="form-label" for="type">* Tipo de cadastro</label>
                            <select class="form-control" name="customer[type]" id="type">
                              <option value="J" <?php if (set_value('customer[type]', 'J') === 'J') echo 'selected=""'; ?>>Pessoa jurídica (CNPJ)</option>
                              <option value="F" <?php if (set_value('customer[type]') === 'F') echo 'selected=""'; ?>>Pessoa física (CPF)</option>
                            </select>
                          </div>
                        </div>
                        <div class="col-12 col-sm-8">
                          <div class="form-group mb-3">
                            <label class="form-label" for="document" id="label_document">* CNPJ</label>
                            <div class="input-group">
                              <input type="text" name="customer[document]" class="form-control" id="document" required value="<?php echo set_value('customer[document]'); ?>" placeholder="00.000.000/0000-00">
                              <button type="button" class="btn btn-outline-primary" id="btn_buscar_cnpj" title="Buscar dados do CNPJ">
                                <i class="mdi mdi-magnify"></i>
                              </button>
                            </div>
                            <small class="text-muted" id="dica_document">Ao sair do campo, os dados da empresa serão preenchidos automaticamente.</small>
                          </div>
                        </div>
                        <div class="col-12 col-sm-6">
                          <div class="form-group mb-3">
                            <label class="form-label" for="name" id="label_name">* Razão Social</label>
                            <input type="text" class="form-control" name="customer[name]" id="name" required maxlength="150" value="<?php echo set_value('customer[name]'); ?>">
                          </div>
                        </div>
                        <div class="col-12 col-sm-6">
                          <div class="form-group mb-3">
                            <label class="form-label" for="byname" id="label_byname">* Nome Fantasia</label>
                            <input type="text" class="form-control" name="customer[byname]" id="byname" required maxlength="150" value="<?php echo set_value('customer[byname]'); ?>">
                          </div>
                        </div>
                        <!-- Só PJ tem inscrição estadual: em PF o bloco é
                             escondido e o controller grava ISENTO. -->
                        <div class="col-12 col-sm-4" id="bloco_ie">
                          <div class="form-group mb-3">
                            <label class="form-label" for="state_registration">Inscrição Estadual</label>
                            <input type="text" class="form-control" name="customer[state_registration]" id="state_registration" maxlength="20" value="<?php echo set_value('customer[state_registration]'); ?>">
                            <small class="form-text text-muted">Deixe em branco se for isento.</small>
                          </div>
                        </div>
                        <div class="col-12 col-sm-8">
                          <div class="form-group mb-3">
                            <label class="form-label" for="email">* E-mail para envio do contrato digital e assinatura</label>
                            <input type="email" class="form-control" name="customer[email]" id="email" required maxlength="150" value="<?php echo set_value('customer[email]'); ?>">
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- PASSO 2 — ENDEREÇO COMERCIAL -->
                    <div id="passo-2" class="tab-pane" role="tabpanel">
                      <div class="row pt-3">
                        <div class="col-12">
                          <h5>Endereço comercial</h5>
                        </div>
                        <div class="col-12 col-sm-4">
                          <div class="form-group mb-3">
                            <label class="form-label" for="address_zip">* CEP</label>
                            <input type="text" class="form-control" name="customer[address_zip]" id="address_zip" data-mask="00.000-000" data-mask-reverse="TRUE" required value="<?php echo set_value('customer[address_zip]'); ?>" placeholder="00.000-000">
                          </div>
                        </div>
                        <div class="col-12 col-sm-8">
                          <div class="form-group mb-3">
                            <label class="form-label" for="address">* Rua / Avenida</label>
                            <input type="text" class="form-control" name="customer[address]" id="address" required maxlength="200" value="<?php echo set_value('customer[address]'); ?>">
                          </div>
                        </div>
                        <div class="col-12 col-sm-4">
                          <div class="form-group mb-3">
                            <label class="form-label" for="address_number">* Número</label>
                            <input type="text" class="form-control" name="customer[address_number]" id="address_number" required maxlength="20" value="<?php echo set_value('customer[address_number]'); ?>">
                          </div>
                        </div>
                        <div class="col-12 col-sm-8">
                          <div class="form-group mb-3">
                            <label class="form-label" for="address_complement">Complemento</label>
                            <input type="text" class="form-control" name="customer[address_complement]" id="address_complement" maxlength="200" value="<?php echo set_value('customer[address_complement]'); ?>">
                          </div>
                        </div>
                        <div class="col-12 col-sm-4">
                          <div class="form-group mb-3">
                            <label class="form-label" for="address_district">* Bairro</label>
                            <input type="text" class="form-control" name="customer[address_district]" id="address_district" required maxlength="150" value="<?php echo set_value('customer[address_district]'); ?>">
                          </div>
                        </div>
                        <div class="col-12 col-sm-4">
                          <div class="form-group mb-3">
                            <label class="form-label" for="id_state">* Estado</label>
                            <select class="form-control select2" name="customer[id_state]" id="id_state" required>
                              <option value="">-- Selecione --</option>
                              <?php $estadoSel = set_value('customer[id_state]'); ?>
                              <?php foreach ($states as $row) { ?>
                                <option <?php if ((string) $estadoSel === (string) $row->id) echo 'selected=""'; ?> value="<?php echo (int) $row->id; ?>" data-uf="<?php echo $row->uf; ?>"><?php echo $row->uf; ?> - <?php echo $row->name; ?></option>
                              <?php } ?>
                            </select>
                          </div>
                        </div>
                        <div class="col-12 col-sm-4">
                          <div class="form-group mb-3">
                            <label class="form-label" for="id_city">* Cidade</label>
                            <select class="form-control select2" name="customer[id_city]" id="id_city" required>
                              <option value="">-- Selecione o estado --</option>
                              <?php $cidadeSel = set_value('customer[id_city]'); ?>
                              <?php foreach ($cities as $row) { ?>
                                <option <?php if ((string) $cidadeSel === (string) $row->id) echo 'selected=""'; ?> value="<?php echo (int) $row->id; ?>"><?php echo $row->name; ?></option>
                              <?php } ?>
                            </select>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- PASSO 3 — REPRESENTANTE -->
                    <div id="passo-3" class="tab-pane" role="tabpanel">
                      <div class="row pt-3">
                        <div class="col-12">
                          <h5 id="titulo_representante">Proprietário e/ou sócio representante</h5>
                          <p class="text-muted" id="texto_representante">Preencha com os dados do proprietário da empresa e/ou sócio representante que assinará o contrato.</p>
                        </div>
                        <div class="col-12 col-sm-6">
                          <div class="form-group mb-3">
                            <label class="form-label" for="rep_name">* Nome completo</label>
                            <input type="text" class="form-control" name="attr[representative][name]" id="rep_name" required maxlength="150" value="<?php echo set_value('attr[representative][name]'); ?>">
                          </div>
                        </div>
                        <div class="col-12 col-sm-6">
                          <div class="form-group mb-3">
                            <label class="form-label" for="nationality">* Nacionalidade</label>
                            <select class="form-control" name="attr[representative][nationality]" id="nationality" required>
                              <option value="brasileira" <?php if (set_value('attr[representative][nationality]', 'brasileira') === 'brasileira') echo 'selected=""'; ?>>Brasileira</option>
                              <option value="outra" <?php if (set_value('attr[representative][nationality]') === 'outra') echo 'selected=""'; ?>>Outra</option>
                            </select>
                          </div>
                        </div>
                        <div class="col-12 col-sm-4">
                          <div class="form-group mb-3">
                            <label class="form-label" for="marital_status">* Estado civil</label>
                            <select class="form-control" name="attr[representative][marital_status]" id="marital_status" required>
                              <option value="">-- Selecione --</option>
                              <?php $ecSel = set_value('attr[representative][marital_status]'); ?>
                              <?php foreach ($marital_statuses as $alias => $rotulo) { ?>
                                <option <?php if ($ecSel === $alias) echo 'selected=""'; ?> value="<?php echo $alias; ?>"><?php echo $rotulo; ?></option>
                              <?php } ?>
                            </select>
                          </div>
                        </div>
                        <div class="col-12 col-sm-4">
                          <div class="form-group mb-3">
                            <label class="form-label" for="rep_profession">* Profissão</label>
                            <input type="text" class="form-control" name="attr[representative][profession]" id="rep_profession" required maxlength="100" value="<?php echo set_value('attr[representative][profession]'); ?>">
                          </div>
                        </div>
                        <div class="col-12 col-sm-4">
                          <div class="form-group mb-3">
                            <label class="form-label" for="rep_whatsapp">* WhatsApp</label>
                            <input type="text" class="form-control phonemask" name="attr[representative][whatsapp]" id="rep_whatsapp" required value="<?php echo set_value('attr[representative][whatsapp]'); ?>" placeholder="(00) 00000-0000">
                          </div>
                        </div>
                        <div class="col-12 col-sm-6">
                          <div class="form-group mb-3">
                            <label class="form-label" for="rep_rg">* RG</label>
                            <input type="text" class="form-control" name="attr[representative][rg]" id="rep_rg" required maxlength="20" value="<?php echo set_value('attr[representative][rg]'); ?>">
                          </div>
                        </div>
                        <div class="col-12 col-sm-6">
                          <div class="form-group mb-3">
                            <label class="form-label" for="rep_cpf">* CPF</label>
                            <input type="text" class="form-control" name="attr[representative][cpf]" id="rep_cpf" data-mask="000.000.000-00" data-mask-reverse="TRUE" required value="<?php echo set_value('attr[representative][cpf]'); ?>" placeholder="000.000.000-00">
                          </div>
                        </div>
                        <div class="col-12">
                          <hr>
                          <h5>Endereço residencial</h5>
                        </div>
                        <div class="col-12 col-sm-4">
                          <div class="form-group mb-3">
                            <label class="form-label" for="rep_zip">* CEP</label>
                            <input type="text" class="form-control" name="attr[representative][address][zip]" id="rep_zip" data-mask="00.000-000" data-mask-reverse="TRUE" required value="<?php echo set_value('attr[representative][address][zip]'); ?>" placeholder="00.000-000">
                          </div>
                        </div>
                        <div class="col-12 col-sm-8">
                          <div class="form-group mb-3">
                            <label class="form-label" for="rep_street">* Rua / Avenida</label>
                            <input type="text" class="form-control" name="attr[representative][address][street]" id="rep_street" required maxlength="200" value="<?php echo set_value('attr[representative][address][street]'); ?>">
                          </div>
                        </div>
                        <div class="col-12 col-sm-4">
                          <div class="form-group mb-3">
                            <label class="form-label" for="rep_number">* Número</label>
                            <input type="text" class="form-control" name="attr[representative][address][number]" id="rep_number" required maxlength="20" value="<?php echo set_value('attr[representative][address][number]'); ?>">
                          </div>
                        </div>
                        <div class="col-12 col-sm-8">
                          <div class="form-group mb-3">
                            <label class="form-label" for="rep_complement">Complemento</label>
                            <input type="text" class="form-control" name="attr[representative][address][complement]" id="rep_complement" maxlength="200" value="<?php echo set_value('attr[representative][address][complement]'); ?>">
                          </div>
                        </div>
                        <div class="col-12 col-sm-4">
                          <div class="form-group mb-3">
                            <label class="form-label" for="rep_district">* Bairro</label>
                            <input type="text" class="form-control" name="attr[representative][address][district]" id="rep_district" required maxlength="150" value="<?php echo set_value('attr[representative][address][district]'); ?>">
                          </div>
                        </div>
                        <div class="col-12 col-sm-4">
                          <div class="form-group mb-3">
                            <label class="form-label" for="rep_id_state">* Estado</label>
                            <select class="form-control select2" name="attr[representative][address][id_state]" id="rep_id_state" required>
                              <option value="">-- Selecione --</option>
                              <?php $repEstadoSel = set_value('attr[representative][address][id_state]'); ?>
                              <?php foreach ($states as $row) { ?>
                                <option <?php if ((string) $repEstadoSel === (string) $row->id) echo 'selected=""'; ?> value="<?php echo (int) $row->id; ?>" data-uf="<?php echo $row->uf; ?>"><?php echo $row->uf; ?> - <?php echo $row->name; ?></option>
                              <?php } ?>
                            </select>
                          </div>
                        </div>
                        <div class="col-12 col-sm-4">
                          <div class="form-group mb-3">
                            <label class="form-label" for="rep_id_city">* Cidade</label>
                            <select class="form-control select2" name="attr[representative][address][id_city]" id="rep_id_city" required>
                              <option value="">-- Selecione o estado --</option>
                              <?php $repCidadeSel = set_value('attr[representative][address][id_city]'); ?>
                              <?php foreach ($rep_cities as $row) { ?>
                                <option <?php if ((string) $repCidadeSel === (string) $row->id) echo 'selected=""'; ?> value="<?php echo (int) $row->id; ?>"><?php echo $row->name; ?></option>
                              <?php } ?>
                            </select>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- PASSO 4 — INFORMAÇÕES COMPLEMENTARES -->
                    <div id="passo-4" class="tab-pane" role="tabpanel">
                      <div class="row pt-3">
                        <div class="col-12">
                          <h5>Informações complementares</h5>
                          <p class="text-muted">Necessárias para a elaboração do seu contrato.</p>
                        </div>
                        <div class="col-12 col-sm-6">
                          <div class="form-group mb-3">
                            <label class="form-label" for="domain_primary">Domínio principal do site</label>
                            <input type="text" class="form-control" name="attr[domains][primary]" id="domain_primary" maxlength="255" placeholder="exemplo.com.br" value="<?php echo set_value('attr[domains][primary]'); ?>">
                          </div>
                        </div>
                        <div class="col-12 col-sm-6">
                          <div class="form-group mb-3">
                            <label class="form-label" for="domain_secondary">Domínio secundário (se houver)</label>
                            <input type="text" class="form-control" name="attr[domains][secondary]" id="domain_secondary" maxlength="255" value="<?php echo set_value('attr[domains][secondary]'); ?>">
                          </div>
                        </div>
                        <div class="col-12 col-sm-4">
                          <div class="form-group mb-3">
                            <label class="form-label" for="billing_name">* Nome do responsável financeiro</label>
                            <input type="text" class="form-control" name="attr[billing][name]" id="billing_name" required maxlength="150" value="<?php echo set_value('attr[billing][name]'); ?>">
                          </div>
                        </div>
                        <div class="col-12 col-sm-4">
                          <div class="form-group mb-3">
                            <label class="form-label" for="billing_email">* E-mail para envio das faturas</label>
                            <input type="email" class="form-control" name="attr[billing][email]" id="billing_email" required maxlength="150" value="<?php echo set_value('attr[billing][email]'); ?>">
                          </div>
                        </div>
                        <div class="col-12 col-sm-4">
                          <div class="form-group mb-3">
                            <label class="form-label" for="billing_whatsapp">* WhatsApp do responsável financeiro</label>
                            <input type="text" class="form-control phonemask" name="attr[billing][whatsapp]" id="billing_whatsapp" required value="<?php echo set_value('attr[billing][whatsapp]'); ?>" placeholder="(00) 00000-0000">
                          </div>
                        </div>
                        <div class="col-12 col-sm-4">
                          <div class="form-group mb-3">
                            <label class="form-label" for="needs_invoice">* Necessita nota fiscal?</label>
                            <select class="form-control" name="attr[billing][needs_invoice]" id="needs_invoice" required>
                              <option value="">-- Selecione --</option>
                              <option value="S" <?php if (set_value('attr[billing][needs_invoice]') === 'S') echo 'selected=""'; ?>>Sim</option>
                              <option value="N" <?php if (set_value('attr[billing][needs_invoice]') === 'N') echo 'selected=""'; ?>>Não</option>
                            </select>
                          </div>
                        </div>
                        <div class="col-12">
                          <div class="form-group mb-3">
                            <label class="form-label" for="comments">Comentários</label>
                            <textarea class="form-control" name="attr[contract][comments]" id="comments" rows="2" maxlength="1000"><?php echo set_value('attr[contract][comments]'); ?></textarea>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- PASSO 5 — REVISÃO E ENVIO -->
                    <div id="passo-5" class="tab-pane" role="tabpanel">
                      <div class="row pt-3">
                        <div class="col-12">
                          <h5>Confira seus dados</h5>
                        </div>
                        <div class="col-12 col-md-6">
                          <dl class="row mb-2">
                            <dt class="col-5" id="rev_label_document">CNPJ</dt>
                            <dd class="col-7" id="rev_document">—</dd>
                            <dt class="col-5" id="rev_label_name">Razão Social</dt>
                            <dd class="col-7" id="rev_name">—</dd>
                            <dt class="col-5">Nome Fantasia</dt>
                            <dd class="col-7" id="rev_byname">—</dd>
                            <dt class="col-5">E-mail do contrato</dt>
                            <dd class="col-7" id="rev_email">—</dd>
                            <dt class="col-5">Endereço comercial</dt>
                            <dd class="col-7" id="rev_address">—</dd>
                          </dl>
                        </div>
                        <div class="col-12 col-md-6">
                          <dl class="row mb-2">
                            <dt class="col-5">Representante</dt>
                            <dd class="col-7" id="rev_rep">—</dd>
                            <dt class="col-5">Responsável financeiro</dt>
                            <dd class="col-7" id="rev_billing">—</dd>
                          </dl>
                        </div>
                        <div class="col-12">
                          <hr>
                          <div class="alert alert-secondary" role="alert">
                            <div class="alert-message">
                              A CDW Tech se preocupa em respeitar e resguardar a privacidade de seus usuários. Honrando nossos valores de comprometimento e transparência, os dados fornecidos neste formulário são utilizados exclusivamente para a elaboração do seu contrato de prestação de serviço, respeitando a legislação aplicável no Brasil (LGPD).
                            </div>
                          </div>
                          <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="lgpd" id="lgpd" value="S" required <?php if (set_value('lgpd') === 'S') echo 'checked'; ?>>
                            <label class="form-check-label" for="lgpd">
                              * Li e estou de acordo com o tratamento dos meus dados para a elaboração do contrato.
                            </label>
                          </div>
                          <div class="form-group text-center mt-3">
                            <button id="btn_enviar" type="submit" class="btn btn-primary w-100 btn-lg"><i class="mdi mdi-content-save"></i> ENVIAR CADASTRO</button>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </form>

              <div class="text-center mb-3">
                <a href="<?php echo base_url('login'); ?>">Voltar para o login</a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="<?php echo base_url('theme/js/app.js'); ?>"></script>
  <script src="<?php echo base_url('theme/custom/sweetalert2/sweetalert2.all.min.js'); ?>"></script>
  <?php $this->load->view('partials/csrf_js'); ?>
  <?php $this->load->view('partials/form_saving_js'); ?>
  <script>
    document.addEventListener("DOMContentLoaded", function() {
      var pularValidacao = false;

      $('#wizard_cliente').smartWizard({
        selected: 0,
        theme: 'arrows',
        enableURLhash: false,
        autoAdjustHeight: true,
        backButtonSupport: false,
        transition: {
          animation: 'none'
        },
        lang: {
          next: 'PRÓXIMO',
          previous: 'ANTERIOR'
        },
        toolbarSettings: {
          toolbarPosition: 'bottom'
        },
        anchorSettings: {
          anchorClickable: false,
          markDoneStep: true,
          removeDoneStepOnNavigateBack: true
        },
        keyboardSettings: {
          keyNavigation: false
        }
      });

      $('.select2').select2({
        width: '100%'
      });

      // ----------------------------------------------------------------
      // Estado/cidade encadeados: a cidade é sempre um registro de
      // crm_country_cities (id_city resolvido — requisito da NF-e futura).
      // ----------------------------------------------------------------
      function normalizar(s) {
        return (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().trim();
      }

      function carregarCidadesEm($city, idState, idCitySelecionar, nomeCidade) {
        $.ajax({
          type: 'POST',
          data: '',
          url: '<?php echo base_url('cadastro-cliente/json_get_cities_by_id/'); ?>' + idState,
          dataType: 'json',
          success: function(data) {
            $city.empty().append($('<option />').val('').text('-- Selecione --'));
            $.each(data, function(i, el) {
              $city.append($('<option />').val(el.id).text(el.name));
            });
            if (idCitySelecionar && parseInt(idCitySelecionar, 10) > 0) {
              $city.val(String(idCitySelecionar));
            } else if (nomeCidade) {
              var alvo = normalizar(nomeCidade);
              $city.find('option').each(function() {
                if (normalizar($(this).text()) === alvo) {
                  $city.val($(this).val());
                  return false;
                }
              });
            }
            $city.trigger('change.select2');
          }
        });
      }

      function carregarCidades(idState, idCitySelecionar, nomeCidade) {
        carregarCidadesEm($('#id_city'), idState, idCitySelecionar, nomeCidade);
      }

      function selecionarEstadoPorUfEm($state, $city, uf, idCity, nomeCidade) {
        var $opt = $state.find('option').filter(function() {
          return ($(this).data('uf') || '') === (uf || '').toUpperCase();
        }).first();
        if (!$opt.length) return;
        $state.val($opt.val()).trigger('change.select2');
        carregarCidadesEm($city, $opt.val(), idCity, nomeCidade);
      }

      function selecionarEstadoPorUf(uf, idCity, nomeCidade) {
        selecionarEstadoPorUfEm($('#id_state'), $('#id_city'), uf, idCity, nomeCidade);
      }

      $('#id_state').on('change', function() {
        if ($(this).val()) carregarCidades($(this).val(), null, null);
      });

      $('#rep_id_state').on('change', function() {
        if ($(this).val()) carregarCidadesEm($('#rep_id_city'), $(this).val(), null, null);
      });

      // Valida os campos visíveis do passo atual antes de avançar (só UX —
      // a validação de verdade acontece no servidor, no POST único do final).
      // Selects com select2 ficam escondidos e o balão nativo do navegador
      // não aparece neles — o aviso sai por Swal.
      function validarPasso(idx) {
        var valido = true;
        $('#passo-' + (idx + 1)).find('input, select, textarea').each(function() {
          if (this.willValidate && !this.checkValidity()) {
            if ($(this).hasClass('select2-hidden-accessible')) {
              var rotulo = $(this).closest('.form-group').find('label').first().text().replace('*', '').trim();
              Swal.fire('Atenção', 'Preencha o campo "' + rotulo + '".', 'warning');
            } else {
              this.reportValidity();
            }
            valido = false;
            return false;
          }
        });
        return valido;
      }

      $('#wizard_cliente').on('leaveStep', function(e, anchorObject, currentStepIdx, nextStepIdx, stepDirection) {
        if (pularValidacao || stepDirection !== 'forward') return true;
        return validarPasso(currentStepIdx);
      });

      $('#wizard_cliente').on('showStep', function(e, anchorObject, stepIndex) {
        if (stepIndex === 4) {
          preencherRevisao();
          $('#wizard_cliente .sw-btn-next').hide();
        } else {
          $('#wizard_cliente .sw-btn-next').show();
        }

        // Pessoa física: quem assina é o próprio titular — pré-copia os dados
        // do passo 1 (o cliente pode ajustar).
        if (stepIndex === 2 && $('#type').val() === 'F') {
          if (!$('#rep_name').val()) $('#rep_name').val($('#name').val());
          if (!$('#rep_cpf').val()) $('#rep_cpf').val($('#document').val());
        }
      });

      // O submit valida todos os passos (o form tem novalidate: campos
      // required escondidos em outros passos travariam o envio nativo).
      $('#form_wizard').on('submit', function(e) {
        var passoInvalido = -1;
        for (var i = 0; i < 5; i++) {
          if (!validarPasso(i)) {
            passoInvalido = i;
            break;
          }
        }
        if (passoInvalido >= 0) {
          e.preventDefault();
          pularValidacao = true;
          $('#wizard_cliente').smartWizard('goToStep', passoInvalido, true);
          pularValidacao = false;
          setTimeout(function() {
            validarPasso(passoInvalido);
          }, 200);
          return false;
        }
        $('#btn_enviar').prop('disabled', true);
      });

      <?php if (!empty($passo_com_erro) && (int) $passo_com_erro > 1) { ?>
        // O servidor devolveu erro de validação: reabre no primeiro passo com
        // problema, com os campos já repopulados.
        pularValidacao = true;
        $('#wizard_cliente').smartWizard('goToStep', <?php echo (int) $passo_com_erro - 1; ?>, true);
        pularValidacao = false;
      <?php } ?>

      // ----------------------------------------------------------------
      // Máscaras e comportamento por tipo (J/F)
      // ----------------------------------------------------------------
      var mascara89Dig = function(val) {
          return val.replace(/\D/g, '').length === 11 ? '(00) 00000-0000' : '(00) 0000-00009';
        },
        optionsTel = {
          onKeyPress: function(val, e, field, options) {
            field.mask(mascara89Dig.apply({}, arguments), options);
          }
        };
      $('.phonemask').mask(mascara89Dig, optionsTel);

      function aplicarTipo() {
        var tipo = $('#type').val();
        $('#document').unmask();
        if (tipo === 'F') {
          $('#document').mask('000.000.000-00', {reverse: true});
          $('#document').attr('placeholder', '000.000.000-00');
          $('#label_document').text('* CPF');
          $('#label_name').text('* Nome completo');
          $('#label_byname').text('Nome Fantasia (se houver)');
          $('#byname').prop('required', false);
          $('#dica_document').hide();
          $('#btn_buscar_cnpj').prop('disabled', true);
          $('#titulo_representante').text('Dados de quem assina o contrato');
          $('#texto_representante').text('Confirme os seus dados (ou os do responsável que assinará o contrato).');
          $('#rev_label_document').text('CPF');
          $('#rev_label_name').text('Nome completo');
          $('#bloco_ie').hide();
        } else {
          $('#document').mask('00.000.000/0000-00', {reverse: true});
          $('#document').attr('placeholder', '00.000.000/0000-00');
          $('#label_document').text('* CNPJ');
          $('#label_name').text('* Razão Social');
          $('#label_byname').text('* Nome Fantasia');
          $('#byname').prop('required', true);
          $('#dica_document').show();
          $('#btn_buscar_cnpj').prop('disabled', false);
          $('#titulo_representante').text('Proprietário e/ou sócio representante');
          $('#texto_representante').text('Preencha com os dados do proprietário da empresa e/ou sócio representante que assinará o contrato.');
          $('#rev_label_document').text('CNPJ');
          $('#rev_label_name').text('Razão Social');
          $('#bloco_ie').show();
        }
      }
      aplicarTipo();
      $('#type').on('change', aplicarTipo);

      $(window).keydown(function(event) {
        if (event.keyCode == 13 && event.target.tagName !== 'TEXTAREA') {
          event.preventDefault();
          return false;
        }
      });

      // ----------------------------------------------------------------
      // Consulta CNPJ (reusa o endpoint público do Login via alias de rota).
      // O objeto de retorno vem em data.message — formato legado, o mesmo
      // consumido pelo register.php.
      // ----------------------------------------------------------------
      function preencherFormularioEmpresa(message) {
        if (!message) return;

        $('#name').val(message.name || '');
        $('#byname').val(message.byname || message.name || '');
        if (message.email && !$('#email').val()) $('#email').val(message.email);

        $('#address_zip').unmask();
        $('#address_zip').val(message.address_zip || '');
        $('#address_zip').mask('00.000-000');

        $('#address').val(message.address || '');
        $('#address_number').val(message.address_number || '');
        $('#address_district').val(message.address_district || '');
        $('#address_complement').val(message.address_complement || '');

        // A consulta da Receita já traz id_state/id_city resolvidos; se algum
        // registro antigo não tiver, cai no casamento por UF + nome da cidade.
        if (message.id_state && parseInt(message.id_state, 10) > 0) {
          $('#id_state').val(String(message.id_state)).trigger('change.select2');
          carregarCidades(message.id_state, message.id_city, message.address_city);
        } else if (message.address_uf) {
          selecionarEstadoPorUf(message.address_uf, null, message.address_city);
        }

        var membership = [];
        if (message.membership) {
          try {
            membership = typeof message.membership === 'string' ? JSON.parse(message.membership) : message.membership;
          } catch (error) {
            membership = [];
          }
        }
        if (Array.isArray(membership) && membership.length) {
          var socio = membership[0].nome || membership[0].nome_socio || '';
          if (socio && !$('#rep_name').val()) $('#rep_name').val(socio);
        }

        // Telefone da Receita só serve como WhatsApp se for celular (11 dígitos).
        if (message.phone && message.phone.replace(/\D/g, '').length === 11 && !$('#rep_whatsapp').val()) {
          $('#rep_whatsapp').val(message.phone);
        }

        $('#cnpj_autofill').val('1');
        $('#name').trigger('focus');
      }

      function buscarCnpj() {
        var cnpj = $('#document').val().replace(/\D/g, '');
        if (cnpj.length !== 14) {
          Swal.fire('Atenção', 'Informe um CNPJ completo com 14 dígitos.', 'warning');
          return;
        }

        $("#loading").show();
        $("#btn_buscar_cnpj").prop('disabled', true);

        $.ajax({
          type: "POST",
          data: "",
          dataType: 'json',
          url: "<?php echo base_url('cadastro-cliente/json_getsefaz/'); ?>" + cnpj,
          success: function(data) {
            if (data && data.return) {
              preencherFormularioEmpresa(data.message);
              return;
            }
            Swal.fire('Ops', (data && data.message) ? data.message : 'Não foi possível consultar o CNPJ. Sem problema: preencha os dados manualmente.', 'warning');
          },
          error: function() {
            Swal.fire('Erro', 'Não foi possível consultar o CNPJ. Sem problema: preencha os dados manualmente.', 'error');
          }
        }).always(function() {
          $("#loading").hide();
          $("#btn_buscar_cnpj").prop('disabled', $('#type').val() === 'F');
        });
      }

      $('#btn_buscar_cnpj').on('click', function() {
        buscarCnpj();
      });

      $('#document').on('blur', function() {
        if ($('#type').val() !== 'J') return;
        var cnpj = $(this).val().replace(/\D/g, '');
        if (cnpj.length === 14) buscarCnpj();
      });

      // ----------------------------------------------------------------
      // Consulta de CEP (ViaCEP, via alias de rota para o endpoint do Login)
      // ----------------------------------------------------------------
      function consultarCep(zipcode, preencher) {
        if (!zipcode) return;
        $.ajax({
          type: "POST",
          data: "",
          url: "<?php echo base_url('cadastro-cliente/json_getcep/'); ?>" + encodeURIComponent(zipcode),
          dataType: 'json',
          success: function(data) {
            if (data && data.return) preencher(data);
          }
        });
      }

      $('#address_zip').blur(function() {
        consultarCep($(this).val(), function(data) {
          if (data.logradouro) $('#address').val(data.logradouro);
          if (data.bairro) $('#address_district').val(data.bairro);
          if (data.id_state && parseInt(data.id_state, 10) > 0) {
            $('#id_state').val(String(data.id_state)).trigger('change.select2');
            carregarCidades(data.id_state, data.id_city, data.localidade);
          } else if (data.uf) {
            selecionarEstadoPorUf(data.uf, null, data.localidade);
          }
          $('#address_number').focus();
        });
      });

      $('#rep_zip').blur(function() {
        consultarCep($(this).val(), function(data) {
          if (data.logradouro) $('#rep_street').val(data.logradouro);
          if (data.bairro) $('#rep_district').val(data.bairro);
          if (data.id_state && parseInt(data.id_state, 10) > 0) {
            $('#rep_id_state').val(String(data.id_state)).trigger('change.select2');
            carregarCidadesEm($('#rep_id_city'), data.id_state, data.id_city, data.localidade);
          } else if (data.uf) {
            selecionarEstadoPorUfEm($('#rep_id_state'), $('#rep_id_city'), data.uf, null, data.localidade);
          }
          $('#rep_number').focus();
        });
      });

      // ----------------------------------------------------------------
      // Revisão (passo 5)
      // ----------------------------------------------------------------
      function preencherRevisao() {
        $('#rev_document').text($('#document').val() || '—');
        $('#rev_name').text($('#name').val() || '—');
        $('#rev_byname').text($('#byname').val() || '—');
        $('#rev_email').text($('#email').val() || '—');

        var endereco = [$('#address').val(), $('#address_number').val()].filter(Boolean).join(', ');
        var cidade = '';
        if ($('#id_city').val()) {
          cidade = $('#id_city option:selected').text() + '/' + ($('#id_state option:selected').data('uf') || '');
        }
        $('#rev_address').text([endereco, $('#address_district').val(), cidade, $('#address_zip').val()].filter(Boolean).join(' - ') || '—');

        var rep = [$('#rep_name').val(), $('#rep_cpf').val()].filter(Boolean).join(' - CPF ');
        $('#rev_rep').text(rep || '—');

        var fin = [$('#billing_name').val(), $('#billing_email').val()].filter(Boolean).join(' - ');
        $('#rev_billing').text(fin || '—');
      }
    });
  </script>
</body>

</html>
