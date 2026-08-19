<?php
/**
 * Aviso prévio de reajuste do contrato.
 *
 * Fragmento de <tr>, como os demais templates: o Global_model::body_email()
 * envolve isto com emails/header e emails/footer.
 *
 * `$mensagem` é o texto que o usuário escreveu em Parâmetros gerais, com os
 * marcadores já substituídos. **Desde 18/08/2026 ele vem do editor da tela, em
 * HTML**, e por isso NÃO é mais escapado aqui.
 *
 * A troca é uma decisão de confiança explícita: quem edita o campo é um usuário
 * autenticado do painel, em Parâmetros gerais, escrevendo o e-mail da própria
 * empresa. É o mesmo grau de confiança de qualquer campo rich-text de CMS.
 * O que NÃO pode entrar aqui é conteúdo vindo do cliente final ou de API —
 * esses continuam precisando de escape.
 *
 * Conteúdo ANTIGO, salvo como texto puro antes do editor, continua funcionando:
 * sem nenhuma tag, as quebras de linha viram <br />. Detectar é mais barato que
 * migrar, e os dois formatos convivem sem data para acabar.
 */
$mensagem = (string) $mensagem;
$mensagemHtml = ($mensagem === strip_tags($mensagem))
    ? nl2br(htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8'))
    : $mensagem;
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
<!-- mensagem configurável -->
<tr>
	<td class="res-left" style="text-align: left; color: #707070; font-size: 17px; letter-spacing: 0.4px; line-height: 23px; word-break: break-word">
		<?php echo $mensagemHtml; ?>
	</td>
</tr>
<!-- mensagem end -->
<tr>
	<td height="35" style="font-size:0px">&nbsp;</td>
</tr>
<!-- quadro-resumo: repete os números fora do texto livre, para que o cliente
     encontre o essencial mesmo que a mensagem configurada seja curta -->
<tr>
	<td>
		<table align="center" class="res-full" border="0" cellpadding="0" cellspacing="0" width="100%">
			<tr>
				<td bgcolor="#F4F6F9" style="border-radius: 6px; padding: 18px 22px;">
					<table border="0" cellpadding="0" cellspacing="0" width="100%">
						<tr>
							<td style="color:#707070; font-size:15px; line-height:22px; padding-bottom:6px;">Índice aplicado</td>
							<td style="color:#333; font-size:15px; line-height:22px; padding-bottom:6px; text-align:right;">
								<strong><?php echo htmlspecialchars((string) $indice, ENT_QUOTES, 'UTF-8'); ?></strong>
							</td>
						</tr>
						<tr>
							<td style="color:#707070; font-size:15px; line-height:22px; padding-bottom:6px;">Variação acumulada (12 meses)</td>
							<td style="color:#333; font-size:15px; line-height:22px; padding-bottom:6px; text-align:right;">
								<strong><?php echo htmlspecialchars((string) $percentual, ENT_QUOTES, 'UTF-8'); ?>%</strong>
							</td>
						</tr>
						<tr>
							<td style="color:#707070; font-size:15px; line-height:22px; padding-bottom:6px;">Valor atual</td>
							<td style="color:#333; font-size:15px; line-height:22px; padding-bottom:6px; text-align:right;">
								R$ <?php echo htmlspecialchars((string) $valor_atual, ENT_QUOTES, 'UTF-8'); ?>
							</td>
						</tr>
						<tr>
							<td style="color:#707070; font-size:15px; line-height:22px; padding-bottom:6px;">Novo valor</td>
							<td style="color:#333; font-size:15px; line-height:22px; padding-bottom:6px; text-align:right;">
								<strong>R$ <?php echo htmlspecialchars((string) $valor_novo, ENT_QUOTES, 'UTF-8'); ?></strong>
							</td>
						</tr>
						<tr>
							<td style="color:#707070; font-size:15px; line-height:22px; padding-bottom:6px;">Periodicidade</td>
							<td style="color:#333; font-size:15px; line-height:22px; padding-bottom:6px; text-align:right;">
								<?php echo htmlspecialchars((string) $ciclo, ENT_QUOTES, 'UTF-8'); ?>
							</td>
						</tr>
						<tr>
							<td style="color:#707070; font-size:15px; line-height:22px;">A partir de</td>
							<td style="color:#333; font-size:15px; line-height:22px; text-align:right;">
								<strong><?php echo htmlspecialchars((string) $data_reajuste, ENT_QUOTES, 'UTF-8'); ?></strong>
							</td>
						</tr>
					</table>
				</td>
			</tr>
		</table>
	</td>
</tr>
<tr>
	<td height="45" style="font-size:0px">&nbsp;</td>
</tr>
