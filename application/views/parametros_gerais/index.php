<?php
$tabsDefault = !empty($tabs_default) ? $tabs_default : 'tab_email';
$email = !empty($email_settings) ? $email_settings : [];
$mailActive = !empty($email['mail_active']) && $email['mail_active'] !== '0';
if ($this->input->post('email') !== null) {
  $postedEmail = $this->input->post('email');
  $mailActive = !empty($postedEmail['mail_active']);
}
$serviceType = set_value('email[mail_service_type]', $email['mail_service_type'] ?? 'smtp_local');
$isBrevo = $serviceType === 'brevo';

$ninjas = !empty($ninjas_settings) ? $ninjas_settings : [];
$ninjasKeySet = !empty($ninjas_key_set);
$ninjasKeyModified = !empty($ninjas_key_modified) ? $ninjas_key_modified : '';
$ninjasActive = !empty($ninjas['ninjas_active']) && $ninjas['ninjas_active'] !== '0';
if ($this->input->post('ninjas') !== null) {
  $postedNinjas = $this->input->post('ninjas');
  $ninjasActive = !empty($postedNinjas['ninjas_active']);
}
$cryptoReady = !isset($crypto_ready) || !empty($crypto_ready);

$rdap = !empty($rdap_settings) ? $rdap_settings : [];
$rdapActive = !empty($rdap['rdap_active']) && $rdap['rdap_active'] !== '0';
if ($this->input->post('rdap') !== null) {
  $postedRdap = $this->input->post('rdap');
  $rdapActive = !empty($postedRdap['rdap_active']);
}
?>

<div class="row align-items-center mb-3">
  <div class="col">
    <h1 class="h3 mb-0">Parâmetros gerais</h1>
    <p class="text-muted small mb-0">Configurações globais do sistema, disponíveis apenas para a empresa principal.</p>
  </div>
</div>

<div class="row">
  <div class="col-12">
    <div class="card w-100">
      <div class="card-body pt-2">
        <ul class="nav nav-pills mt-2">
          <li class="nav-item">
            <a class="nav-link <?php if ($tabsDefault === 'tab_email') echo 'active'; ?>" href="#tab_email" data-bs-toggle="tab" role="tab" aria-selected="<?php echo $tabsDefault === 'tab_email' ? 'true' : 'false'; ?>">
              Configuração de e-mails
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?php if ($tabsDefault === 'tab_ninjas') echo 'active'; ?>" href="#tab_ninjas" data-bs-toggle="tab" role="tab" aria-selected="<?php echo $tabsDefault === 'tab_ninjas' ? 'true' : 'false'; ?>">
              Ninjas API
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?php if ($tabsDefault === 'tab_rdap') echo 'active'; ?>" href="#tab_rdap" data-bs-toggle="tab" role="tab" aria-selected="<?php echo $tabsDefault === 'tab_rdap' ? 'true' : 'false'; ?>">
              RDAP .br
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?php if ($tabsDefault === 'tab_faturamento') echo 'active'; ?>" href="#tab_faturamento" data-bs-toggle="tab" role="tab" aria-selected="<?php echo $tabsDefault === 'tab_faturamento' ? 'true' : 'false'; ?>">
              Faturamento
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?php if ($tabsDefault === 'tab_monitoramento') echo 'active'; ?>" href="#tab_monitoramento" data-bs-toggle="tab" role="tab" aria-selected="<?php echo $tabsDefault === 'tab_monitoramento' ? 'true' : 'false'; ?>">
              Monitoramento
            </a>
          </li>
          <li class="nav-item">
            <a class="nav-link <?php if ($tabsDefault === 'tab_contratos') echo 'active'; ?>" href="#tab_contratos" data-bs-toggle="tab" role="tab" aria-selected="<?php echo $tabsDefault === 'tab_contratos' ? 'true' : 'false'; ?>">
              Contratos
            </a>
          </li>
        </ul>

        <?php // Alertas fora do tab-content: dentro de uma aba só, o feedback da outra ficaria invisível. 
        ?>
        <?php if ($this->session->flashdata('success')) { ?>
          <div class="alert alert-success alert-dismissible mt-3" role="alert">
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
            <div class="alert-message"><?php echo htmlspecialchars($this->session->flashdata('success'), ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
        <?php } ?>

        <?php if ($this->session->flashdata('error')) { ?>
          <div class="alert alert-danger alert-dismissible mt-3" role="alert">
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
            <div class="alert-message"><?php echo htmlspecialchars($this->session->flashdata('error'), ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
        <?php } ?>

        <?php if (validation_errors()) { ?>
          <div class="alert alert-danger alert-dismissible mt-3" role="alert">
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
            <div class="alert-message"><?php echo validation_errors('<div class="small mt-1">', '</div>'); ?></div>
          </div>
        <?php } ?>

        <div class="tab-content p-0" style="box-shadow: none;">
          <div class="tab-pane <?php if ($tabsDefault === 'tab_email') echo 'active'; ?>" id="tab_email" role="tabpanel">
            <form method="POST" action="<?php echo base_url('parametros_gerais/post_email'); ?>" class="mt-3">
              <div class="row g-3">
                <div class="col-12">
                  <?php // Mesmo motivo do hidden da aba Ninjas. 
                  ?>
                  <input type="hidden" name="email[mail_active]" value="0">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="mail_active" name="email[mail_active]" value="1" <?php echo $mailActive ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="mail_active">Envio de e-mails ativo</label>
                  </div>
                  <small class="text-muted">Quando desativado, a fila de envio do CRON não processará novos e-mails.</small>
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="mail_service_type">* Tipo de serviço</label>
                  <select class="form-control select2" id="mail_service_type" name="email[mail_service_type]" required>
                    <option value="brevo" <?php echo $serviceType === 'brevo' ? 'selected' : ''; ?>>BREVO</option>
                    <option value="smtp_local" <?php echo $serviceType === 'smtp_local' ? 'selected' : ''; ?>>SMTP Local</option>
                  </select>
                </div>
                <div class="col-md-8 <?php echo $isBrevo ? '' : 'd-none'; ?>" id="wrap_brevo_api_key">
                  <label class="form-label" for="brevo_api_key">Brevo API Key</label>
                  <div class="input-group">
                    <input type="password" class="form-control" id="brevo_api_key" name="email[brevo_api_key]" maxlength="255" autocomplete="new-password" data-lpignore="true" data-form-type="other" placeholder="<?php echo !empty($email['brevo_api_key']) ? 'Preencha apenas para alterar a chave atual' : 'Informe a Brevo API Key'; ?>">
                    <button type="button" class="btn btn-outline-secondary" id="btn_toggle_brevo_api_key" title="Ver chave" aria-label="Ver chave">
                      <i class="mdi mdi-eye"></i>
                    </button>
                  </div>
                  <small class="text-muted">Deixe em branco para manter a chave já cadastrada.</small>
                </div>
                <div class="col-md-8">
                  <label class="form-label" for="mail_smtp_host">* Host SMTP</label>
                  <input type="text" class="form-control" id="mail_smtp_host" name="email[mail_smtp_host]" maxlength="255" required value="<?php echo htmlspecialchars(set_value('email[mail_smtp_host]', $email['mail_smtp_host'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="smtp.exemplo.com.br">
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="mail_smtp_port">* Porta SMTP</label>
                  <input type="number" class="form-control" id="mail_smtp_port" name="email[mail_smtp_port]" min="0" max="65535" required value="<?php echo htmlspecialchars(set_value('email[mail_smtp_port]', $email['mail_smtp_port'] ?? '587'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="mail_sender">* E-mail remetente</label>
                  <input type="email" class="form-control" id="mail_sender" name="email[mail_sender]" maxlength="255" required value="<?php echo htmlspecialchars(set_value('email[mail_sender]', $email['mail_sender'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="noreply@exemplo.com.br">
                </div>
                <div class="col-md-6" id="wrap_mail_smtp_pass">
                  <label class="form-label" for="mail_smtp_pass">Senha SMTP</label>
                  <div class="input-group">
                    <input type="password" class="form-control" id="mail_smtp_pass" name="email[mail_smtp_pass]" maxlength="255" autocomplete="new-password" data-lpignore="true" data-form-type="other" value="<?php echo htmlspecialchars($isBrevo ? '' : set_value('email[mail_smtp_pass]', $email['mail_smtp_pass'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php echo $isBrevo ? '' : 'Informe a senha SMTP'; ?>" <?php echo $isBrevo ? 'readonly' : ''; ?>>
                    <button type="button" class="btn btn-outline-secondary" id="btn_toggle_mail_smtp_pass" title="Ver senha" aria-label="Ver senha" <?php echo $isBrevo ? 'disabled' : ''; ?>>
                      <i class="mdi mdi-eye"></i>
                    </button>
                  </div>
                  <small class="text-muted" id="help_mail_smtp_pass">A senha atual é exibida. Edite o campo para alterá-la; se limpar, a senha já cadastrada é mantida.</small>
                </div>
              </div>
              <div class="row mt-4">
                <div class="col-md-4">
                  <button type="submit" class="btn btn-primary w-100">
                    <i class="mdi mdi-content-save"></i> SALVAR E-MAIL
                  </button>
                </div>
              </div>
            </form>
          </div>

          <div class="tab-pane <?php if ($tabsDefault === 'tab_ninjas') echo 'active'; ?>" id="tab_ninjas" role="tabpanel">
            <?php if (!$cryptoReady) { ?>
              <div class="alert alert-danger mt-3" role="alert">
                <div class="alert-message">
                  <i class="fa fa-exclamation-triangle"></i>
                  A chave de criptografia (<code>secret_crypto_key</code>) não está configurada neste ambiente.
                  A chave da API não pode ser gravada com segurança e o formulário recusará o salvamento.
                </div>
              </div>
            <?php } ?>

            <form method="POST" action="<?php echo base_url('parametros_gerais/post_ninjas'); ?>" class="mt-3" id="form_ninjas">
              <div class="row g-3">
                <div class="col-12">
                  <?php // Checkbox desmarcado não é enviado pelo navegador. O hidden garante que a
                  // chave sempre exista no POST — sem ele, "desmarcado" e "campo ausente"
                  // chegam iguais ao controller. O PHP fica com o último valor, então o
                  // checkbox marcado continua vencendo o hidden. 
                  ?>
                  <input type="hidden" name="ninjas[ninjas_active]" value="0">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="ninjas_active" name="ninjas[ninjas_active]" value="1" <?php echo $ninjasActive ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="ninjas_active">Consulta de WHOIS ativa</label>
                  </div>
                  <small class="text-muted">Quando desativada, o CRON <code>cron_sync_whois</code> não consulta nenhum domínio.</small>
                </div>

                <div class="col-md-8">
                  <label class="form-label" for="ninjas_api_key">
                    Chave da API Ninjas
                    <?php if ($ninjasKeySet) { ?>
                      <span class="badge bg-success ms-1">
                        <i class="fa fa-check"></i> Cadastrada<?php echo $ninjasKeyModified ? ' em ' . nice_date($ninjasKeyModified, 'd/m/Y H:i') : ''; ?>
                      </span>
                    <?php } else { ?>
                      <span class="badge bg-secondary ms-1">Nenhuma chave cadastrada</span>
                    <?php } ?>
                  </label>
                  <?php // O olho é o ÚNICO controle: com o campo em branco ele busca a chave salva
                  // em json_postrevelarninjas (o valor não vem no HTML da página); com algo
                  // digitado, só mostra/esconde o que está no campo. data-key-set diz ao JS
                  // se existe chave a buscar. 
                  ?>
                  <div class="input-group">
                    <input type="password" class="form-control" id="ninjas_api_key" name="ninjas_api_key" maxlength="255" autocomplete="new-password" data-lpignore="true" data-form-type="other" placeholder="<?php echo $ninjasKeySet ? 'Preencha apenas para alterar a chave atual' : 'Informe a chave da API Ninjas'; ?>">
                    <button type="button" class="btn btn-outline-secondary" id="btn_toggle_ninjas_api_key" data-key-set="<?php echo $ninjasKeySet ? '1' : '0'; ?>" title="Ver chave salva" aria-label="Ver chave salva">
                      <i class="mdi mdi-eye"></i>
                    </button>
                  </div>
                  <div class="form-text text-warning d-none" id="aviso_revelado_ninjas">
                    <i class="fa fa-exclamation-triangle"></i> Chave salva visível. Clique no olho de novo para ocultá-la e limpar o campo — em branco, o SALVAR mantém a chave atual.
                  </div>
                  <small class="text-muted">
                    A chave é gravada <strong>cifrada</strong> no banco e não viaja no HTML desta tela — por isso o campo
                    <strong>nasce sempre em branco</strong>, mesmo com a chave salva. Clique no <strong>olho</strong> para conferi-la
                    (a consulta fica registrada no log). Deixe o campo em branco para manter a chave já cadastrada.
                  </small>
                </div>

                <div class="col-md-4">
                  <label class="form-label">&nbsp;&nbsp;</label>
                  <button type="button" class="btn btn-outline-primary w-100" id="btn_testar_ninjas" <?php echo $ninjasKeySet ? '' : 'disabled'; ?>>
                    <i class="fa fa-plug"></i> TESTAR CHAVE
                  </button>
                </div>

                <div class="col-12">
                  <div id="resultado_teste_ninjas"></div>
                </div>

                <div class="col-12">
                  <div class="alert alert-primary alert-outline" role="alert">
                    <div class="alert-message">
                      <h4 class="alert-heading">Como a rotina funciona</h4>
                      <hr>
                      <p class="mb-1">Somente domínios <strong>internacionais</strong> são consultados — os terminados em <code>.br</code> ficam de fora, porque o WHOIS do Registro.br não é atendido por esta API.</p>
                      <p class="mb-1">Cada domínio é reconsultado a cada <strong>7 dias</strong>, no máximo <strong>100 domínios por execução</strong> do CRON. Domínios nunca consultados entram na frente da fila.</p>
                      <p class="mb-1">O vencimento encontrado <strong>sobrescreve</strong> a data dos domínios de contrato que estiverem vinculados a um servidor.</p>
                      <p class="mb-0">O plano gratuito da API Ninjas atende apenas <code>.com</code>; outros TLDs exigem plano pago e aparecem com erro na tela de domínios.</p>
                    </div>
                  </div>
                </div>
              </div>

              <div class="row mt-4">
                <div class="col-md-4">
                  <button type="submit" class="btn btn-primary w-100">
                    <i class="mdi mdi-content-save"></i> SALVAR API NINJAS
                  </button>
                </div>
              </div>
            </form>
          </div>

          <div class="tab-pane <?php if ($tabsDefault === 'tab_rdap') echo 'active'; ?>" id="tab_rdap" role="tabpanel">
            <form method="POST" action="<?php echo base_url('parametros_gerais/post_rdap'); ?>" class="mt-3">
              <div class="row g-3">
                <div class="col-12">
                  <?php // Mesmo motivo do hidden da aba Ninjas. 
                  ?>
                  <input type="hidden" name="rdap[rdap_active]" value="0">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="rdap_active" name="rdap[rdap_active]" value="1" <?php echo $rdapActive ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="rdap_active">Consulta de domínios .br ativa</label>
                  </div>
                  <small class="text-muted">Quando desativada, o CRON <code>cron_sync_whois_br</code> não consulta nenhum domínio.</small>
                </div>

                <div class="col-md-4">
                  <button type="button" class="btn btn-outline-primary w-100" id="btn_testar_rdap">
                    <i class="fa fa-plug"></i> TESTAR CONEXÃO
                  </button>
                </div>

                <div class="col-12">
                  <div id="resultado_teste_rdap"></div>
                </div>

                <div class="col-12">
                  <div class="alert alert-primary alert-outline mb-0" role="alert">
                    <div class="alert-message">
                      <h4 class="alert-heading">Como a rotina funciona</h4>
                      <hr>
                      <p class="mb-1">Atende os domínios terminados em <code>.br</code>, consultando o <strong>RDAP do Registro.br</strong> — serviço <strong>público e sem chave</strong>, por isso não há nada a cadastrar aqui.</p>
                      <p class="mb-1">Cada domínio é reconsultado a cada <strong>7 dias</strong>, no máximo <strong>200 por execução</strong>, com um segundo de intervalo entre consultas para respeitar o limite do serviço.</p>
                      <p class="mb-0">O vencimento encontrado <strong>sobrescreve</strong> a data dos domínios de contrato vinculados a um servidor, igual à rotina internacional.</p>
                    </div>
                  </div>
                </div>
              </div>

              <div class="row mt-4">
                <div class="col-md-4">
                  <button type="submit" class="btn btn-primary w-100">
                    <i class="mdi mdi-content-save"></i> SALVAR RDAP
                  </button>
                </div>
              </div>
            </form>
          </div>

          <?php
          // O grupo `faturamento` só nasce no primeiro salvamento, então o valor
          // exibido cai no default do model quando a chave ainda não existe —
          // sem isso a tela abriria com os campos vazios e "salvar" gravaria
          // zeros por cima de parâmetros que os motores já usam.
          $fat = function ($chave) use ($faturamento_settings, $faturamento_defaults) {
            $postado = $this->input->post('faturamento');
            if (is_array($postado) && array_key_exists($chave, $postado)) {
              return (string) $postado[$chave];
            }
            if (isset($faturamento_settings[$chave]) && $faturamento_settings[$chave] !== '') {
              return (string) $faturamento_settings[$chave];
            }
            return (string) $faturamento_defaults[$chave];
          };
          ?>
          <div class="tab-pane <?php if ($tabsDefault === 'tab_faturamento') echo 'active'; ?>" id="tab_faturamento" role="tabpanel">
            <form method="POST" action="<?php echo base_url('parametros_gerais/post_faturamento'); ?>" class="mt-3">
              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label" for="faturamento_dias_antecedencia">* Gerar a fatura com quantos dias de antecedência</label>
                  <input type="number" class="form-control" id="faturamento_dias_antecedencia" name="faturamento[faturamento_dias_antecedencia]" min="1" max="90" value="<?php echo htmlspecialchars($fat('faturamento_dias_antecedencia'), ENT_QUOTES, 'UTF-8'); ?>">
                  <small class="text-muted">Contados a partir do vencimento.</small>
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="faturamento_dia_padrao">* Dia de vencimento sugerido</label>
                  <input type="number" class="form-control" id="faturamento_dia_padrao" name="faturamento[faturamento_dia_padrao]" min="1" max="31" value="<?php echo htmlspecialchars($fat('faturamento_dia_padrao'), ENT_QUOTES, 'UTF-8'); ?>">
                  <small class="text-muted">Preenchido ao ligar o faturamento de um contrato.</small>
                </div>
                <div class="col-md-4">
                  <label class="form-label" for="reajuste_dias_aviso">* Avisar reajuste com dias de antecedência</label>
                  <input type="number" class="form-control" id="reajuste_dias_aviso" name="faturamento[reajuste_dias_aviso]" min="1" max="180" value="<?php echo htmlspecialchars($fat('reajuste_dias_aviso'), ENT_QUOTES, 'UTF-8'); ?>">
                  <small class="text-muted">O e-mail sai antes da data do reajuste.</small>
                </div>
              </div>

              <hr class="my-4">

              <?php // Abas internas: os dois avisos têm assunto, corpo e marcadores
              // próprios, e empilhados na vertical um escondia o outro. Ids
              // com prefixo `fat_` para não colidirem com as abas de fora,
              // que vivem na mesma página. 
              ?>
              <ul class="nav nav-pills" role="tablist">
                <li class="nav-item">
                  <a class="nav-link active" href="#fat_aviso_faturamento" data-bs-toggle="tab" role="tab" aria-selected="true">
                    Aviso faturamento
                  </a>
                </li>
                <li class="nav-item">
                  <a class="nav-link" href="#fat_aviso_nota" data-bs-toggle="tab" role="tab" aria-selected="false">
                    Aviso Nota Fiscal
                  </a>
                </li>
                <li class="nav-item">
                  <a class="nav-link" href="#fat_aviso_reajuste" data-bs-toggle="tab" role="tab" aria-selected="false">
                    Aviso Reajuste
                  </a>
                </li>
              </ul>

              <div class="tab-content p-0 pt-3" style="box-shadow: none;">

                <?php // ---------- Aviso de faturamento ---------- 
                ?>
                <div class="tab-pane active" id="fat_aviso_faturamento" role="tabpanel">

                  <div class="row g-3">
                    <div class="col-12">
                      <label class="form-label" for="fatura_email_assunto">* Assunto</label>
                      <input type="text" class="form-control" id="fatura_email_assunto" name="faturamento[fatura_email_assunto]" maxlength="200" value="<?php echo htmlspecialchars($fat('fatura_email_assunto'), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="col-12">
                      <label class="form-label" for="fatura_email_corpo">* Corpo do e-mail</label>
                      <?php // `wysiwyg` é o seletor do editor (TinyMCE, escolhido no
                      // MY_Controller para o painel inteiro). O conteúdo é HTML, e o
                      // escape na saída é obrigatório — sem ele, o próprio valor
                      // gravado fecharia o textarea. 
                      ?>
                      <textarea class="form-control wysiwyg" id="fatura_email_corpo" name="faturamento[fatura_email_corpo]" rows="12"><?php echo htmlspecialchars($fat('fatura_email_corpo'), ENT_QUOTES, 'UTF-8'); ?></textarea>
                      <small class="text-muted">Formatação livre (negrito, listas, links). O boleto vai em anexo.</small>
                    </div>

                    <div class="col-12">
                      <div class="alert alert-secondary mb-0" role="alert">
                        <div class="alert-message">
                          <h4 class="alert-heading">Marcadores disponíveis</h4>
                          <hr>
                          <p class="mb-2">Escreva o marcador no texto e ele será trocado pelo dado da fatura no momento do envio.</p>
                          <div class="row">
                            <?php foreach ($fatura_marcadores as $marcador => $descricao) { ?>
                              <div class="col-md-6 mb-1">
                                <code><?php echo htmlspecialchars($marcador, ENT_QUOTES, 'UTF-8'); ?></code>
                                <small class="text-muted"> — <?php echo htmlspecialchars($descricao, ENT_QUOTES, 'UTF-8'); ?></small>
                              </div>
                            <?php } ?>
                          </div>
                          <p class="mb-0 mt-2"><small>Marcador digitado errado aparece literalmente no e-mail, em vez de sumir — assim o erro fica visível e pode ser corrigido.</small></p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <?php // ---------- Aviso de reajuste ---------- 
                ?>
                <div class="tab-pane" id="fat_aviso_nota" role="tabpanel">
                  <p class="text-muted"><small>
                    Mensagem que leva a nota fiscal ao cliente. Vale <strong>só para contratos que emitem a NF depois da compensação</strong> —
                    em "Emitir junto com o boleto" a nota vai anexada no próprio e-mail da cobrança, e este texto não é usado.
                  </small></p>

                  <div class="row g-3">
                    <div class="col-12">
                      <label class="form-label" for="nota_email_assunto">* Assunto</label>
                      <input type="text" class="form-control" id="nota_email_assunto" name="faturamento[nota_email_assunto]" maxlength="200" value="<?php echo htmlspecialchars($fat('nota_email_assunto'), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="col-12">
                      <label class="form-label" for="nota_email_corpo">* Corpo do e-mail</label>
                      <textarea class="form-control wysiwyg" id="nota_email_corpo" name="faturamento[nota_email_corpo]" rows="10"><?php echo htmlspecialchars($fat('nota_email_corpo'), ENT_QUOTES, 'UTF-8'); ?></textarea>
                      <small class="text-muted">Formatação livre. O PDF e o XML vão em anexo; se o download do ERP falhar, entram como link no corpo — receber por link é entrega degradada, mas é entrega.</small>
                    </div>

                    <div class="col-12">
                      <div class="alert alert-secondary mb-0" role="alert">
                        <div class="alert-message">
                          <h4 class="alert-heading">Marcadores disponíveis</h4>
                          <hr>
                          <p class="mb-2">Os mesmos do aviso de faturamento; os que não fizerem sentido aqui saem vazios.</p>
                          <div class="row">
                            <?php foreach ($fatura_marcadores as $marcador => $descricao) { ?>
                              <div class="col-md-6 mb-1">
                                <code><?php echo htmlspecialchars($marcador, ENT_QUOTES, 'UTF-8'); ?></code>
                                <small class="text-muted"> — <?php echo htmlspecialchars($descricao, ENT_QUOTES, 'UTF-8'); ?></small>
                              </div>
                            <?php } ?>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <?php // ---------- Aviso de reajuste ---------- ?>
                <div class="tab-pane" id="fat_aviso_reajuste" role="tabpanel">
                
                  <div class="row g-3">
                    <div class="col-12">
                      <label class="form-label" for="reajuste_email_assunto">* Assunto</label>
                      <input type="text" class="form-control" id="reajuste_email_assunto" name="faturamento[reajuste_email_assunto]" maxlength="200" value="<?php echo htmlspecialchars($fat('reajuste_email_assunto'), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="col-12">
                      <label class="form-label" for="reajuste_email_corpo">* Corpo do e-mail</label>
                      <?php // Mesmo editor do aviso de faturamento. O template do e-mail de
                      // reajuste passou a aceitar HTML por causa desta mudança —
                      // conteúdo antigo, salvo como texto puro, continua saindo com
                      // as quebras de linha preservadas. 
                      ?>
                      <textarea class="form-control wysiwyg" id="reajuste_email_corpo" name="faturamento[reajuste_email_corpo]" rows="10"><?php echo htmlspecialchars($fat('reajuste_email_corpo'), ENT_QUOTES, 'UTF-8'); ?></textarea>
                      <small class="text-muted">Formatação livre. Abaixo do texto, o sistema acrescenta automaticamente um quadro com índice, percentual, valores e a data.</small>
                    </div>

                    <div class="col-12">
                      <div class="alert alert-secondary mb-0" role="alert">
                        <div class="alert-message">
                          <h4 class="alert-heading">Marcadores disponíveis</h4>
                          <hr>
                          <p class="mb-2">Escreva o marcador no texto e ele será trocado pelo dado do contrato no momento do envio.</p>
                          <div class="row">
                            <?php foreach ($reajuste_marcadores as $marcador => $descricao) { ?>
                              <div class="col-md-6 mb-1">
                                <code><?php echo htmlspecialchars($marcador, ENT_QUOTES, 'UTF-8'); ?></code>
                                <small class="text-muted"> — <?php echo htmlspecialchars($descricao, ENT_QUOTES, 'UTF-8'); ?></small>
                              </div>
                            <?php } ?>
                          </div>
                          <p class="mb-0 mt-2"><small>Marcador digitado errado aparece literalmente no e-mail, em vez de sumir — assim o erro fica visível e pode ser corrigido.</small></p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

              </div>

              <div class="row mt-4">
                <div class="col-md-4">
                  <button type="submit" class="btn btn-primary w-100">
                    <i class="mdi mdi-content-save"></i> SALVAR FATURAMENTO
                  </button>
                </div>
              </div>
            </form>
          </div>
          <?php
          // Mesmo padrão do faturamento: o grupo `monitoramento` só nasce no
          // primeiro salvamento, então o valor cai no default do model enquanto
          // a chave não existe — senão a tela abriria vazia e "salvar" gravaria
          // zeros por cima do que a rotina já usa.
          $mon = function ($chave) use ($monitoramento_settings, $monitoramento_defaults) {
            $postado = $this->input->post('monitoramento');
            if (is_array($postado) && array_key_exists($chave, $postado)) {
              return (string) $postado[$chave];
            }
            if (isset($monitoramento_settings[$chave]) && $monitoramento_settings[$chave] !== '') {
              return (string) $monitoramento_settings[$chave];
            }
            return (string) $monitoramento_defaults[$chave];
          };
          ?>
          <div class="tab-pane <?php if ($tabsDefault === 'tab_monitoramento') echo 'active'; ?>" id="tab_monitoramento" role="tabpanel">
            <form method="POST" action="<?php echo base_url('parametros_gerais/post_monitoramento'); ?>" class="mt-3">
              <div class="alert alert-info p-2" role="alert">
                A rotina "cron_monitorar_sites" checa, uma vez por dia, os domínios de contrato vigente cujo tipo de serviço esteja marcado como "tem site" em GESTÃO/Tipos de serviços. Ela compara os nameservers e o título da home com a checagem anterior e detecta site fora do ar, página de erro ou suspensão e certificado vencendo.
              </div>

              <div class="row g-3">
                <div class="col-md-4">
                  <label class="form-label" for="monitoramento_intervalo_horas">* Intervalo mínimo entre checagens (horas)</label>
                  <input type="number" min="1" max="168" class="form-control" id="monitoramento_intervalo_horas" name="monitoramento[monitoramento_intervalo_horas]" value="<?php echo htmlspecialchars($mon('monitoramento_intervalo_horas'), ENT_QUOTES, 'UTF-8'); ?>" required>
                  <small class="form-text text-muted">Com a rotina rodando 1x/dia, 20 horas garante que todo domínio entre em toda execução.</small>
                </div>

                <div class="col-md-4">
                  <label class="form-label" for="monitoramento_timeout">* Tempo limite por site (segundos)</label>
                  <input type="number" min="3" max="60" class="form-control" id="monitoramento_timeout" name="monitoramento[monitoramento_timeout]" value="<?php echo htmlspecialchars($mon('monitoramento_timeout'), ENT_QUOTES, 'UTF-8'); ?>" required>
                  <small class="form-text text-muted">Site que passa disso já é um problema. Valores altos fazem a rodada estourar a janela por causa dos domínios sem resposta.</small>
                </div>

                <div class="col-md-4">
                  <label class="form-label" for="monitoramento_ssl_dias_aviso">* Avisar do SSL com quantos dias</label>
                  <input type="number" min="1" max="90" class="form-control" id="monitoramento_ssl_dias_aviso" name="monitoramento[monitoramento_ssl_dias_aviso]" value="<?php echo htmlspecialchars($mon('monitoramento_ssl_dias_aviso'), ENT_QUOTES, 'UTF-8'); ?>" required>
                  <small class="form-text text-muted">Não use 30: o Let's Encrypt renova sozinho aos ~30 dias e a base inteira entraria em "vencendo" a cada ciclo.</small>
                </div>

                <div class="col-md-12">
                  <label class="form-label" for="monitoramento_email_destinatarios">Destinatários do resumo diário</label>
                  <input type="text" class="form-control" id="monitoramento_email_destinatarios" maxlength="500" name="monitoramento[monitoramento_email_destinatarios]" value="<?php echo htmlspecialchars($mon('monitoramento_email_destinatarios'), ENT_QUOTES, 'UTF-8'); ?>" placeholder="suporte@empresa.com.br, ti@empresa.com.br">
                  <small class="form-text text-muted">
                    Separe por vírgula. Em branco, o resumo vai para o e-mail cadastrado na empresa. Rodada sem
                    nenhuma alteração não envia e-mail, e mudança de título da home não entra no resumo — ela fica
                    só no painel, porque muda sozinha com promoção e plugin de SEO.
                  </small>
                </div>
              </div>

              <div class="row mt-4">
                <div class="col-md-4">
                  <button type="submit" class="btn btn-primary w-100">
                    <i class="mdi mdi-content-save"></i> SALVAR MONITORAMENTO
                  </button>
                </div>
              </div>
            </form>
          </div>

          <?php
          // O switch precisa distinguir "desmarcado" de "campo ausente", e
          // checkbox desmarcado simplesmente não é enviado pelo navegador — daí
          // o hidden na frente, mesmo truque do filtro do Bom Controle na
          // listagem de clientes.
          $contratosAtivo = $contratos_aviso_ativo;
          $postadoContratos = $this->input->post('contratos');
          if (is_array($postadoContratos)) {
            $contratosAtivo = (isset($postadoContratos['contratos_aviso_ativo']) && $postadoContratos['contratos_aviso_ativo'] === '1');
          }

          $contratosUsuarios = $contratos_usuarios_marcados;
          if (is_array($postadoContratos)) {
            $contratosUsuarios = isset($postadoContratos['contratos_aviso_usuarios'])
              ? array_map('intval', (array) $postadoContratos['contratos_aviso_usuarios']) : [];
          }

          $contratosMarcados = $contratos_eventos_marcados;
          if (is_array($postadoContratos)) {
            $contratosMarcados = isset($postadoContratos['contratos_aviso_eventos'])
              ? (array) $postadoContratos['contratos_aviso_eventos'] : [];
          }
          ?>
          <div class="tab-pane <?php if ($tabsDefault === 'tab_contratos') echo 'active'; ?>" id="tab_contratos" role="tabpanel">
            <form method="POST" action="<?php echo base_url('parametros_gerais/post_contratos'); ?>" class="mt-3">
              <?php // O `.alert` do tema é `display: flex`, então cada filho vira um flex
              // item: sem o `.alert-message` em volta, todo <strong> quebra numa coluna
              // própria e o espaço em volta dele some. É o padrão do AppStack. ?>
              <div class="alert alert-info p-2" role="alert">
                <div class="alert-message">
                  Toda mudança de estado de um contrato — criação, suspensão, reativação, encerramento, reabertura e
                  exclusão — fica registrada na aba <strong>Históricos</strong> do próprio contrato, com data, autor e
                  <strong>origem</strong> (painel, importação ou rotina automática). Este bloco decide quem é avisado
                  por e-mail e de quais eventos. O envio é enfileirado e sai pelo "cron_enviar_email", que já roda.
                </div>
              </div>

              <div class="row g-3">
                <div class="col-12">
                  <input type="hidden" name="contratos[contratos_aviso_ativo]" value="0">
                  <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="contratos_aviso_ativo" name="contratos[contratos_aviso_ativo]" value="1" <?php echo $contratosAtivo ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="contratos_aviso_ativo">Avisar por e-mail as mudanças de estado</label>
                  </div>
                  <small class="text-muted">Desligado, o histórico continua sendo gravado — só o e-mail deixa de sair.</small>
                </div>

                <div class="col-md-12">
                  <?php // O `for` só é emitido quando o select existe: apontar para um id
                  // ausente deixa o clique no rótulo sem efeito nenhum. ?>
                  <label class="form-label d-block" <?php echo empty($contratos_usuarios) ? '' : 'for="contratos_aviso_usuarios"'; ?>>Quem recebe o aviso</label>

                  <?php if (empty($contratos_usuarios)) { ?>
                    <div class="alert alert-warning p-2 mb-1" role="alert">
                      <div class="alert-message">
                        Nenhum usuário ativo tem e-mail cadastrado. O aviso vai para o e-mail da empresa até que
                        algum usuário seja selecionado aqui.
                      </div>
                    </div>
                  <?php } else { ?>
                    <?php // O `$(".select2").select2()` do footer roda no ready e pega este
                    // select pela classe — não há inicialização própria aqui. O `width: 100%`
                    // é necessário: sem ele o select2 mede o elemento já escondido pela aba
                    // inativa e nasce com alguns pixels de largura. ?>
                    <select class="form-control select2 select2-multiple" multiple
                            id="contratos_aviso_usuarios"
                            name="contratos[contratos_aviso_usuarios][]"
                            style="width: 100%;"
                            data-placeholder="Selecione os usuários que receberão o aviso">
                      <?php foreach ($contratos_usuarios as $u) {
                        // Nome E e-mail no rótulo: é o e-mail que vai receber, e quem escolhe
                        // precisa conferir sem abrir o cadastro do usuário — no dropdown e no
                        // chip do selecionado. A empresa entra só quando há mais de uma, senão
                        // é a mesma palavra repetida em toda linha.
                        $rotulo = (string) $u->name . ' — ' . (string) $u->email;
                        if (!empty($contratos_multiempresa)) {
                          $rotulo .= ' (' . (string) $u->company_byname . ')';
                        }
                        ?>
                        <option value="<?php echo (int) $u->id; ?>" <?php echo in_array((int) $u->id, $contratosUsuarios, TRUE) ? 'selected' : ''; ?>>
                          <?php echo htmlspecialchars($rotulo, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                      <?php } ?>
                    </select>
                  <?php } ?>

                  <small class="form-text text-muted d-block mt-2">
                    A lista traz os usuários <strong>ativos com e-mail cadastrado</strong> — o endereço vem do
                    cadastro de cada um, então trocar o e-mail no perfil já vale no próximo aviso, e usuário
                    inativado sai da lista sozinho. Sem ninguém selecionado, o aviso vai para o e-mail da empresa.
                  </small>
                  <small class="form-text text-muted d-block">
                    <i class="mdi mdi-account-check-outline"></i>
                    <strong>Quem executou a ação não recebe o próprio aviso</strong> — ele já viu a confirmação na
                    tela. No resumo da importação a regra só vale para quem executou <em>todas</em> as mudanças do
                    e-mail.
                  </small>
                  <small class="form-text text-muted d-block">
                    É um aviso <strong>interno</strong>: o cliente nunca o recebe — quem avisa o cliente sobre
                    boleto e nota fiscal é o bloco "Notificações ao cliente" da tela do contrato.
                  </small>
                </div>

                <div class="col-md-12">
                  <label class="form-label d-block">Eventos que geram e-mail</label>
                  <div class="row g-2">
                    <?php foreach ($contratos_eventos as $evSlug => $evMeta) { ?>
                      <div class="col-md-4">
                        <div class="form-check">
                          <input class="form-check-input" type="checkbox" id="ev_<?php echo htmlspecialchars($evSlug, ENT_QUOTES, 'UTF-8'); ?>" name="contratos[contratos_aviso_eventos][]" value="<?php echo htmlspecialchars($evSlug, ENT_QUOTES, 'UTF-8'); ?>" <?php echo in_array($evSlug, $contratosMarcados, TRUE) ? 'checked' : ''; ?>>
                          <label class="form-check-label" for="ev_<?php echo htmlspecialchars($evSlug, ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($evMeta['rotulo'], ENT_QUOTES, 'UTF-8'); ?>
                          </label>
                        </div>
                      </div>
                    <?php } ?>
                  </div>
                  <small class="form-text text-muted">
                    Todos são registrados no histórico; só os marcados viram e-mail. A importação do gestor-interno
                    manda <strong>um resumo</strong> no fim da rodada, e não uma mensagem por contrato — uma execução
                    reescreve centenas de linhas.
                  </small>
                </div>
              </div>

              <div class="row mt-4">
                <div class="col-md-4">
                  <button type="submit" class="btn btn-primary w-100">
                    <i class="mdi mdi-content-save"></i> SALVAR CONTRATOS
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    function toggleSecretField(buttonId, inputId) {
      $(buttonId).on('click', function() {
        var $input = $(inputId);
        var $icon = $(this).find('i');
        if ($input.attr('type') === 'password') {
          $input.attr('type', 'text');
          $icon.removeClass('mdi-eye').addClass('mdi-eye-off');
        } else {
          $input.attr('type', 'password');
          $icon.removeClass('mdi-eye-off').addClass('mdi-eye');
        }
      });
    }

    function applyServiceType() {
      var isBrevo = $('#mail_service_type').val() === 'brevo';
      var $smtpPass = $('#mail_smtp_pass');
      var $toggleSmtpPass = $('#btn_toggle_mail_smtp_pass');

      $('#wrap_brevo_api_key').toggleClass('d-none', !isBrevo);

      if (isBrevo) {
        $smtpPass.val('').prop('readonly', true);
        $toggleSmtpPass.prop('disabled', true);
        $('#help_mail_smtp_pass').addClass('d-none');
      } else {
        $smtpPass.prop('readonly', false);
        $toggleSmtpPass.prop('disabled', false);
        $('#help_mail_smtp_pass').removeClass('d-none');
      }
    }

    toggleSecretField('#btn_toggle_mail_smtp_pass', '#mail_smtp_pass');
    toggleSecretField('#btn_toggle_brevo_api_key', '#brevo_api_key');
    <?php // O olho da chave Ninjas NÃO usa o toggleSecretField: além de mostrar/esconder,
    // ele busca a chave salva no servidor. Ver o bloco próprio mais abaixo. 
    ?>
    $('#mail_service_type').on('change', applyServiceType);
    applyServiceType();

    // Testa a chave JÁ SALVA — nunca a digitada no campo, que pode estar em
    // branco justamente porque o usuário quer manter a atual.
    function testarOrigem(botao, saida, url, aguardando) {
      $(botao).on('click', function() {
        var $botao = $(this);
        var $saida = $(saida);

        $botao.prop('disabled', true);
        $saida.html($('<div class="alert alert-info mb-0"><div class="alert-message"></div></div>')
          .find('.alert-message').text(aguardando).end());

        $.post(url, {}, null, 'json')
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
    }

    <?php // O estado de "enviando" do botão saiu daqui: virou partials/form_saving_js,
    // carregado pelo footer.php e pelas telas públicas, valendo para todo
    // formulário POST do sistema. 
    ?>

      // Olho da chave da API Ninjas: um controle só, com dois comportamentos.
      //
      // Campo EM BRANCO e chave cadastrada -> busca a chave no servidor
      // (json_postrevelarninjas), porque ela não vem no HTML da página. Era o que
      // fazia o olho parecer quebrado: ele alternava password/text num campo
      // vazio, então clicar nele não mostrava nada.
      //
      // Campo PREENCHIDO -> só mostra/esconde o que está digitado, sem ir ao
      // servidor: o que o usuário acabou de digitar tem precedência sobre a chave
      // salva; buscá-la aqui apagaria a digitação.
      //
      // Ocultar uma chave que veio do servidor limpa o campo — é o estado que faz
      // o SALVAR manter a chave atual. Ocultar o que foi digitado preserva.
      (function() {
        var $btn = $('#btn_toggle_ninjas_api_key');
        var $campo = $('#ninjas_api_key');
        if (!$btn.length || !$campo.length) {
          return;
        }

        var temChaveSalva = $btn.data('key-set') === 1 || $btn.data('key-set') === '1';
        // Distingue "visível porque veio do servidor" de "visível porque foi
        // digitado" — só o primeiro caso limpa o campo ao ocultar.
        var veioDoServidor = false;

        function pintarBotao(visivel, revelada) {
          $btn.find('i').removeClass('mdi-eye mdi-eye-off').addClass(visivel ? 'mdi-eye-off' : 'mdi-eye');
          var titulo = visivel ? 'Ocultar' : (temChaveSalva ? 'Ver chave salva' : 'Mostrar o que foi digitado');
          $btn.attr('title', titulo).attr('aria-label', titulo);
          $('#aviso_revelado_ninjas').toggleClass('d-none', !revelada);
        }

        // Editar a chave revelada a transforma em digitação: a partir daí ocultar
        // preserva o texto em vez de limpar, senão o usuário perderia a alteração.
        $campo.on('input', function() {
          if (veioDoServidor) {
            veioDoServidor = false;
            $('#aviso_revelado_ninjas').addClass('d-none');
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
            url: '<?php echo base_url('parametros_gerais/json_postrevelarninjas'); ?>',
            dataType: 'json',
            success: function(data) {
              if (!data || !data.success) {
                Swal.fire('Não foi possível exibir', (data && data.message) ? data.message : 'Falha ao ler a chave.', 'error');
                return;
              }
              veioDoServidor = true;
              $campo.val(data.data.ninjas_api_key).attr('type', 'text');
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

    testarOrigem('#btn_testar_ninjas', '#resultado_teste_ninjas',
      '<?php echo base_url('parametros_gerais/json_posttestarninjas'); ?>', 'Consultando a API Ninjas...');
    testarOrigem('#btn_testar_rdap', '#resultado_teste_rdap',
      '<?php echo base_url('parametros_gerais/json_posttestarrdap'); ?>', 'Consultando o RDAP do Registro.br...');

    // --- Editor dentro de aba ----------------------------------------
    // Os dois corpos de e-mail nascem ESCONDIDOS: a aba Faturamento não é a
    // padrão da tela, e a aba interna "Aviso Reajuste" também começa fechada.
    // O TinyMCE calcula a altura da barra de ferramentas na inicialização, e
    // num container `display:none` essa medida sai zerada — o editor aparece
    // achatado quando a aba abre.
    //
    // Mandar recalcular no `shown.bs.tab` resolve porque aí o container já tem
    // dimensão real. Vale para as abas de fora e as de dentro, daí o seletor
    // amplo.
    $('a[data-bs-toggle="tab"]').on('shown.bs.tab', function() {
      if (typeof tinymce === 'undefined' || !tinymce.editors) return;

      tinymce.editors.forEach(function(editor) {
        var container = editor.getContainer();
        // Só os que estão de fato visíveis agora: mexer num editor ainda
        // escondido repetiria o mesmo cálculo zerado.
        if (container && container.offsetParent !== null) {
          editor.dispatch('ResizeEditor');
        }
      });
    });

  });
</script>