<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * E-mail que leva a nota fiscal ao cliente (etapa F).
 *
 * Vale **só para `pos_compensacao`** — no `com_boleto` a nota já foi junto do
 * boleto, no e-mail da etapa B, e mandá-la de novo aqui seria o segundo
 * disparo que o requisito proíbe.
 *
 * `$mensagem` vem do editor da tela, em HTML, e por isso NÃO é escapado —
 * mesma decisão de confiança dos outros dois templates. Texto salvo sem tags
 * continua funcionando, com as quebras de linha viradas em <br />.
 *
 * O PDF e o XML vão ANEXOS quando o download do ERP funciona. Quando não
 * funciona, os links entram no corpo: receber a nota por link é entrega
 * degradada, mas é entrega — e melhor que não receber.
 */
$mensagem = (string) $mensagem;
$mensagemHtml = ($mensagem === strip_tags($mensagem))
	? nl2br(htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8'))
	: $mensagem;

$esc = function ($valor) {
	return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
};

$temLink = (trim((string) $link_nota_pdf) !== '' || trim((string) $link_nota_xml) !== '');
?>
<!-- title end -->
<tr>
	<td height="13" style="font-size:0px">&nbsp;</td>
</tr>

<tr>
	<td style="color:#333; font-size:16px; line-height:26px;">
		<?php echo $mensagemHtml; ?>
	</td>
</tr>

<tr>
	<td height="24" style="font-size:0px">&nbsp;</td>
</tr>

<tr>
	<td>
		<table align="center" class="res-full" border="0" cellpadding="0" cellspacing="0" width="100%">
			<tr>
				<td bgcolor="#F4F6F9" style="border-radius: 6px; padding: 18px 22px;">
					<table border="0" cellpadding="0" cellspacing="0" width="100%">
						<tr>
							<td style="color:#707070; font-size:15px; line-height:22px; padding-bottom:6px;">Competência</td>
							<td style="color:#333; font-size:15px; line-height:22px; padding-bottom:6px; text-align:right;">
								<strong><?php echo $esc($competencia); ?></strong>
							</td>
						</tr>
						<?php if (trim((string) $emitida_em) !== '') { ?>
							<tr>
								<td style="color:#707070; font-size:15px; line-height:22px; padding-bottom:6px;">Emitida em</td>
								<td style="color:#333; font-size:15px; line-height:22px; padding-bottom:6px; text-align:right;">
									<strong><?php echo $esc($emitida_em); ?></strong>
								</td>
							</tr>
						<?php } ?>
						<tr>
							<td style="color:#707070; font-size:17px; line-height:24px; padding-top:8px; border-top:1px solid #E3E7ED;">Valor</td>
							<td style="color:#333; font-size:17px; line-height:24px; padding-top:8px; border-top:1px solid #E3E7ED; text-align:right;">
								<strong>R$ <?php echo $esc($valor); ?></strong>
							</td>
						</tr>
					</table>
				</td>
			</tr>
		</table>
	</td>
</tr>

<tr>
	<td height="18" style="font-size:0px">&nbsp;</td>
</tr>

<?php // Diz onde a nota está — anexo ou link. Sem isso o cliente que recebeu só
      // o link não sabe que é a nota fiscal dele. ?>
<?php if (!empty($tem_anexo)) { ?>
	<tr>
		<td style="color:#707070; font-size:14px; line-height:22px;">
			A nota fiscal está <strong>em anexo</strong> neste e-mail (PDF e XML).
		</td>
	</tr>
<?php } ?>

<?php if ($temLink) { ?>
	<tr>
		<td style="color:#707070; font-size:14px; line-height:22px;">
			<?php if (trim((string) $link_nota_pdf) !== '') { ?>
				<a href="<?php echo $esc($link_nota_pdf); ?>" style="color:#3B7DDD;">Abrir a nota fiscal (PDF)</a><br />
			<?php } ?>
			<?php if (trim((string) $link_nota_xml) !== '') { ?>
				<a href="<?php echo $esc($link_nota_xml); ?>" style="color:#3B7DDD;">Baixar o XML</a>
			<?php } ?>
		</td>
	</tr>
<?php } ?>

<tr>
	<td height="13" style="font-size:0px">&nbsp;</td>
</tr>
