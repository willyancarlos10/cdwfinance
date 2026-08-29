<?php
/**
 * Aviso de mudança de estado de contrato (e-mail INTERNO, para a equipe).
 *
 * Fragmento de <tr>, como os demais templates: o Global_model::body_email()
 * envolve isto com emails/header e emails/footer.
 *
 * UM template serve os dois casos — o evento único do painel e o resumo da
 * importação —, porque a lista de um item é a mesma lista. Dois templates
 * divergiriam na primeira alteração de layout, e o leitor veria a mesma
 * informação com duas caras conforme a origem.
 *
 * A ORIGEM aparece em toda linha, e é o dado mais importante daqui: foi ela
 * que faltou quando os contratos apareceram "suspensos sozinhos" — a trilha
 * antiga dizia apenas PROCESSOS AUTOMÁTICOS, que é o mesmo usuário do cron e
 * da importação.
 */
$cores = [
  'critico' => ['fundo' => '#FCE8E8', 'borda' => '#D9534F'],
  'alerta' => ['fundo' => '#FDF3E3', 'borda' => '#E0A800'],
  'info' => ['fundo' => '#EEF3FA', 'borda' => '#4B546C'],
];

$esc = function ($valor) {
  return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
};

$mostrados = array_slice($eventos, 0, $limite);
$restantes = count($eventos) - count($mostrados);

// Origem diferente de "painel" em QUALQUER linha muda a leitura do e-mail
// inteiro: é a diferença entre "fulano suspendeu" e "algo suspendeu".
$temAutomatico = FALSE;
foreach ($eventos as $ev) {
  if ((string) $ev->origin !== 'painel') { $temAutomatico = TRUE; break; }
}
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
		<?php if (count($eventos) === 1) { ?>
			Um contrato de <strong><?php echo $esc($empresa); ?></strong> mudou de estado.
		<?php } else { ?>
			<?php echo count($eventos); ?> contratos de <strong><?php echo $esc($empresa); ?></strong> mudaram de estado.
		<?php } ?>
	</td>
</tr>
<tr>
	<td height="20" style="font-size:0px">&nbsp;</td>
</tr>

<tr>
	<td>
		<table align="center" class="res-full" border="0" cellpadding="0" cellspacing="0" width="100%">
			<?php foreach ($mostrados as $evento) {
			  $severidade = isset($rotulos[$evento->event]['severidade']) ? $rotulos[$evento->event]['severidade'] : 'info';
			  $cor = isset($cores[$severidade]) ? $cores[$severidade] : $cores['info'];
			  $rotulo = isset($rotulos[$evento->event]['rotulo']) ? $rotulos[$evento->event]['rotulo'] : $evento->event;
			  $origem = isset($origens[$evento->origin]) ? $origens[$evento->origin] : $evento->origin;
			  ?>
				<tr>
					<td bgcolor="<?php echo $cor['fundo']; ?>" style="border-left:4px solid <?php echo $cor['borda']; ?>; border-radius:4px; padding:12px 16px;">
						<table border="0" cellpadding="0" cellspacing="0" width="100%">
							<tr>
								<td style="color:#333; font-size:15px; line-height:21px; font-weight:bold;">
									<?php echo $esc($evento->contract_label); ?>
								</td>
								<td style="color:#707070; font-size:13px; line-height:21px; text-align:right; white-space:nowrap;">
									<?php echo $esc($rotulo); ?>
								</td>
							</tr>
							<tr>
								<td colspan="2" style="color:#707070; font-size:13px; line-height:19px; padding-top:4px;">
									<?php if (!empty($evento->status_from) && !empty($evento->status_to)) { ?>
										<?php echo $esc($evento->status_from); ?> &rarr; <?php echo $esc($evento->status_to); ?> &middot;
									<?php } ?>
									<?php echo $esc(date('d/m/Y H:i', strtotime((string) $evento->created))); ?>
									&middot; <?php echo $esc($origem); ?>
									<?php if (!empty($evento->created_user)) { ?>
										&middot; <?php echo $esc($evento->created_user); ?>
									<?php } ?>
								</td>
							</tr>
							<?php if (!empty($evento->reason) || !empty($evento->comments)) { ?>
								<tr>
									<td colspan="2" style="color:#555; font-size:13px; line-height:19px; padding-top:4px;">
										<?php if (!empty($evento->reason)) { ?>
											Motivo: <strong><?php echo $esc($evento->reason); ?></strong><?php echo !empty($evento->comments) ? ' &middot; ' : ''; ?>
										<?php } ?>
										<?php echo $esc($evento->comments); ?>
									</td>
								</tr>
							<?php } ?>
							<?php if (!empty($evento->detail)) { ?>
								<tr>
									<td colspan="2" style="color:#707070; font-size:12px; line-height:18px; padding-top:4px;">
										<?php echo $esc($evento->detail); ?>
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
						e mais <?php echo (int) $restantes; ?> — ver a lista completa na aba Históricos de cada contrato.
					</td>
				</tr>
			<?php } ?>
		</table>
	</td>
</tr>

<?php // O botão sai só no evento único, e nunca na exclusão: ali o link levaria
// a um contrato que deixou de existir. O `id_contract` ainda vem preenchido
// porque o registro é gravado ANTES do DELETE (ver Contratos::post_excluir).
if (count($eventos) === 1 && !empty($mostrados[0]->id_contract) && (string) $mostrados[0]->event !== 'excluido') { ?>
	<tr>
		<td height="18" style="font-size:0px">&nbsp;</td>
	</tr>
	<tr>
		<td>
			<table align="center" class="res-full" border="0" cellpadding="0" cellspacing="0">
				<tr>
					<td bgcolor="#4B546C" style="border-radius:6px;">
						<a href="<?php echo $esc($url_painel . (int) $mostrados[0]->id_contract); ?>" style="display:block; padding:13px 28px; color:#ffffff; font-size:15px; letter-spacing:0.4px;">
							ABRIR O CONTRATO
						</a>
					</td>
				</tr>
			</table>
		</td>
	</tr>
<?php } ?>

<tr>
	<td height="20" style="font-size:0px">&nbsp;</td>
</tr>
<?php if ($temAutomatico) { ?>
	<tr>
		<td class="res-left" style="text-align:left; color:#909090; font-size:13px; line-height:19px;">
			Mudança que não partiu do painel. A importação do gestor-interno reescreve o estado do contrato com o
			que estiver no dump, sem passar pelos painéis de hospedagem — o serviço do cliente NÃO acompanha essa
			mudança. Confira a aba Históricos do contrato.
		</td>
	</tr>
	<tr>
		<td height="20" style="font-size:0px">&nbsp;</td>
	</tr>
<?php } ?>
<tr>
	<td class="res-left" style="text-align:left; color:#909090; font-size:13px; line-height:19px;">
		Quem recebe este aviso e quais eventos entram nele são configuráveis em Parâmetros gerais &rsaquo; Contratos.
	</td>
</tr>
<tr>
	<td height="45" style="font-size:0px">&nbsp;</td>
</tr>
