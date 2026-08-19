<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * E-mail que leva a cobrança ao cliente (etapa B).
 *
 * Fragmento de <tr>, como os demais templates: o Global_model::body_email()
 * envolve isto com emails/header e emails/footer.
 *
 * `$mensagem` é o texto que o usuário escreveu em Parâmetros gerais › Aviso
 * faturamento, com os marcadores já substituídos. Vem do editor da tela, em
 * HTML, e por isso NÃO é escapado — mesma decisão de confiança do aviso de
 * reajuste: quem edita é usuário autenticado do painel escrevendo o e-mail da
 * própria empresa. Conteúdo vindo do cliente final ou de API continuaria
 * precisando de escape.
 *
 * Texto salvo sem tags continua funcionando, com as quebras de linha viradas
 * em <br />.
 *
 * O BOLETO NÃO APARECE AQUI: ele vai ANEXO. O Banco Inter não publica URL do
 * boleto, então não há link para colocar no corpo — e é justamente por isso que
 * o e-mail carrega o PDF. O quadro abaixo repete os números essenciais para o
 * cliente reconhecer a cobrança sem abrir o anexo.
 */
$mensagem = (string) $mensagem;
$mensagemHtml = ($mensagem === strip_tags($mensagem))
	? nl2br(htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8'))
	: $mensagem;

$esc = function ($valor) {
	return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
};
?>
<!-- title end -->
<tr>
	<td height="13" style="font-size:0px">&nbsp;</td>
</tr>

<!-- mensagem configurada -->
<tr>
	<td style="color:#333; font-size:16px; line-height:26px;">
		<?php echo $mensagemHtml; ?>
	</td>
</tr>

<tr>
	<td height="24" style="font-size:0px">&nbsp;</td>
</tr>

<!-- quadro-resumo: os números essenciais fora do texto livre, para o cliente
     reconhecer a cobrança mesmo que a mensagem configurada seja curta -->
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
						<?php // Parcela só aparece quando há mais de uma: "1/1" em toda
						      // cobrança mensal é ruído que esconde as de fato parceladas. ?>
						<?php if (trim((string) $parcela) !== '') { ?>
							<tr>
								<td style="color:#707070; font-size:15px; line-height:22px; padding-bottom:6px;">Parcela</td>
								<td style="color:#333; font-size:15px; line-height:22px; padding-bottom:6px; text-align:right;">
									<strong><?php echo $esc($parcela); ?></strong>
								</td>
							</tr>
						<?php } ?>
						<tr>
							<td style="color:#707070; font-size:15px; line-height:22px; padding-bottom:6px;">Vencimento</td>
							<td style="color:#333; font-size:15px; line-height:22px; padding-bottom:6px; text-align:right;">
								<strong><?php echo $esc($vencimento); ?></strong>
							</td>
						</tr>
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

<!-- o anexo é o único caminho para o boleto: sem URL pública, dizer onde ele
     está evita o cliente responder perguntando -->
<tr>
	<td style="color:#707070; font-size:14px; line-height:22px;">
		O boleto está <strong>em anexo</strong> neste e-mail, em PDF.
	</td>
</tr>

<?php // Só chegam preenchidos quando o download da nota falhou (contrato
      // `com_boleto`): aí o link é o que garante que o cliente receba a nota
      // no MESMO e-mail, sem precisar de um segundo disparo. ?>
<?php
$linkPdf = isset($link_nota_pdf) ? trim((string) $link_nota_pdf) : '';
$linkXml = isset($link_nota_xml) ? trim((string) $link_nota_xml) : '';
?>
<?php if ($linkPdf !== '' || $linkXml !== '') { ?>
	<tr>
		<td style="color:#707070; font-size:14px; line-height:22px;">
			<?php if ($linkPdf !== '') { ?>
				<a href="<?php echo $esc($linkPdf); ?>" style="color:#3B7DDD;">Abrir a nota fiscal (PDF)</a><br />
			<?php } ?>
			<?php if ($linkXml !== '') { ?>
				<a href="<?php echo $esc($linkXml); ?>" style="color:#3B7DDD;">Baixar o XML da nota</a>
			<?php } ?>
		</td>
	</tr>
<?php } ?>

<tr>
	<td height="13" style="font-size:0px">&nbsp;</td>
</tr>
