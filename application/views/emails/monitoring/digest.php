<?php
/**
 * Resumo diário do monitoramento de sites (e-mail INTERNO, para a equipe).
 *
 * Fragmento de <tr>, como os demais templates: o Global_model::body_email()
 * envolve isto com emails/header e emails/footer.
 *
 * Tudo que vem da rede — título de site, nameserver, URL de redirecionamento —
 * é escapado aqui: é conteúdo de terceiro entrando num e-mail nosso.
 *
 * Cada seção tem TETO de linhas. No dia em que um servidor cair de verdade, o
 * resumo teria dezenas de linhas e viraria um paredão ilegível; o corte com "e
 * mais N — ver a tela" mantém o e-mail sendo lido e manda o detalhe para onde
 * ele cabe.
 */
$cores = [
  'critico' => ['fundo' => '#FCE8E8', 'borda' => '#D9534F', 'titulo' => 'Críticos'],
  'alerta' => ['fundo' => '#FDF3E3', 'borda' => '#E0A800', 'titulo' => 'Alertas'],
  'info' => ['fundo' => '#EEF3FA', 'borda' => '#4B546C', 'titulo' => 'Avisos'],
];

$esc = function ($valor) {
  return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
};
?>
<!-- title end -->
<tr>
	<td height="13" style="font-size:0px">&nbsp;</td>
</tr>
<!-- dash -->
<tr>
	<td>
		<table align="center" class="res-full" border="0" cellpadding="0" cellspacing="0">
			<tr>
				<td>
					<table bgcolor="#4B546C" align="center" style="border-radius: 10px;" border="0" cellpadding="0" cellspacing="0">
						<tr>
							<td width="50" height="3" style="font-size:0px">&nbsp;</td>
						</tr>
					</table>
				</td>
			</tr>
		</table>
	</td>
</tr>
<!-- dash end -->
<tr>
	<td height="25" style="font-size:0px">&nbsp;</td>
</tr>
<tr>
	<td class="res-left" style="text-align: left; color: #707070; font-size: 16px; letter-spacing: 0.4px; line-height: 23px; word-break: break-word">
		Alterações detectadas nos sites de <strong><?php echo $esc($empresa); ?></strong> na checagem de
		<?php echo date('d/m/Y'); ?>. Só entram aqui os domínios de contrato vigente cujo tipo de serviço
		prevê site.
	</td>
</tr>
<tr>
	<td height="25" style="font-size:0px">&nbsp;</td>
</tr>

<?php foreach ($grupos as $severidade => $eventos) {
  $cor = isset($cores[$severidade]) ? $cores[$severidade] : $cores['info'];
  $mostrados = array_slice($eventos, 0, $limite);
  $restantes = count($eventos) - count($mostrados);
  ?>
  <!-- seção <?php echo $esc($severidade); ?> -->
  <tr>
    <td class="res-left" style="text-align:left; color:#333; font-size:17px; font-weight:bold; line-height:24px; padding-bottom:10px;">
      <?php echo $esc($cor['titulo']); ?> (<?php echo count($eventos); ?>)
    </td>
  </tr>
  <tr>
    <td>
      <table align="center" class="res-full" border="0" cellpadding="0" cellspacing="0" width="100%">
        <?php foreach ($mostrados as $evento) {
          $chave = $evento['domain'];
          // O `clientesPorDominio()` devolve [['id','label'], ...] porque as telas
          // linkam para a visão geral do cliente. Aqui só o rótulo interessa: link
          // para o painel dentro do e-mail exigiria login e não leva a lugar
          // nenhum para quem lê no celular.
          $cliente = isset($clientes[$chave]) ? implode(', ', array_column($clientes[$chave], 'label')) : '';
          $rotulo = isset($rotulos[$evento['type']]['rotulo']) ? $rotulos[$evento['type']]['rotulo'] : $evento['type'];
          ?>
          <tr>
            <td bgcolor="<?php echo $cor['fundo']; ?>" style="border-left:4px solid <?php echo $cor['borda']; ?>; border-radius:4px; padding:12px 16px;">
              <table border="0" cellpadding="0" cellspacing="0" width="100%">
                <tr>
                  <td style="color:#333; font-size:15px; line-height:21px; font-weight:bold;">
                    <?php echo $esc($evento['domain']); ?>
                  </td>
                  <td style="color:#707070; font-size:13px; line-height:21px; text-align:right; white-space:nowrap;">
                    <?php echo $esc($rotulo); ?>
                  </td>
                </tr>
                <?php if ($cliente !== '') { ?>
                  <tr>
                    <td colspan="2" style="color:#707070; font-size:13px; line-height:19px; padding-top:3px;">
                      <?php echo $esc($cliente); ?>
                    </td>
                  </tr>
                <?php } ?>
                <?php if (!empty($evento['detail'])) { ?>
                  <tr>
                    <td colspan="2" style="color:#505050; font-size:14px; line-height:20px; padding-top:5px;">
                      <?php echo $esc($evento['detail']); ?>
                    </td>
                  </tr>
                <?php } ?>
                <?php if (!empty($evento['old_value']) || !empty($evento['new_value'])) { ?>
                  <tr>
                    <td colspan="2" style="color:#707070; font-size:13px; line-height:19px; padding-top:5px; word-break:break-all;">
                      <?php if (!empty($evento['old_value'])) { ?>
                        <span style="text-decoration:line-through;"><?php echo $esc($evento['old_value']); ?></span>
                      <?php } ?>
                      <?php if (!empty($evento['old_value']) && !empty($evento['new_value'])) echo ' &rarr; '; ?>
                      <?php if (!empty($evento['new_value'])) { ?>
                        <strong><?php echo $esc($evento['new_value']); ?></strong>
                      <?php } ?>
                    </td>
                  </tr>
                <?php } ?>
              </table>
            </td>
          </tr>
          <tr>
            <td height="8" style="font-size:0px">&nbsp;</td>
          </tr>
        <?php } ?>

        <?php if ($restantes > 0) { ?>
          <tr>
            <td style="color:#707070; font-size:14px; line-height:20px; padding:4px 0 10px 0;">
              e mais <?php echo (int) $restantes; ?> — ver a lista completa no painel.
            </td>
          </tr>
        <?php } ?>
      </table>
    </td>
  </tr>
  <tr>
    <td height="18" style="font-size:0px">&nbsp;</td>
  </tr>
<?php } ?>

<tr>
	<td>
		<table align="center" class="res-full" border="0" cellpadding="0" cellspacing="0">
			<tr>
				<td bgcolor="#4B546C" style="border-radius:6px;">
					<a href="<?php echo $esc($url_painel); ?>" style="display:block; padding:13px 28px; color:#ffffff; font-size:15px; letter-spacing:0.4px;">
						ABRIR O MONITORAMENTO
					</a>
				</td>
			</tr>
		</table>
	</td>
</tr>
<tr>
	<td height="20" style="font-size:0px">&nbsp;</td>
</tr>
<tr>
	<td class="res-left" style="text-align:left; color:#909090; font-size:13px; line-height:19px;">
		Alterações de título da home não entram neste resumo — elas mudam com promoção, contador e plugin de SEO,
		e ficam registradas apenas no painel.
	</td>
</tr>
<tr>
	<td height="45" style="font-size:0px">&nbsp;</td>
</tr>
