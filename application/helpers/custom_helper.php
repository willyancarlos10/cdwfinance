<?php

defined('BASEPATH') or exit('No direct script access allowed');

function retornarPeriodoMesAtual()
{
  ### mês atual ###
  $dt_ini = mktime(0, 0, 0, date('m'), 1, date('Y'));
  $dt_fim = mktime(23, 59, 59, date('m'), date("t"), date('Y'));
  $dt_ini = date('d/m/Y', $dt_ini);
  $dt_fim = date('d/m/Y', $dt_fim);
  return $dt_ini . ' - ' . $dt_fim;
}

function password_strengh($string)
{
  ### Verifica se a senha possui ao menos 8 caracteres com letras e números ###
  if (strlen($string) < 8) return FALSE;
  $have_number = FALSE;
  $have_letter = FALSE;
  if (preg_match("#[0-9]+#", $string)) {
    $have_number = TRUE;
  }
  if (preg_match("#[a-z]+#", $string)) {
    $have_letter = TRUE;
  }
  if (($have_number) && ($have_letter)) {
    return TRUE;
  } else {
    return FALSE;
  }
}

function valorusd($string)
{
  $x = str_replace('.', '', $string);
  $x = str_replace(',', '.', $x);
  return $x;
}

function remover_acentos($string)
{
  $map = array(
    'á' => 'a',
    'à' => 'a',
    'ã' => 'a',
    'â' => 'a',
    'é' => 'e',
    'ê' => 'e',
    'í' => 'i',
    'ó' => 'o',
    'ô' => 'o',
    'õ' => 'o',
    'ú' => 'u',
    'ü' => 'u',
    'ç' => 'c',
    'Á' => 'A',
    'À' => 'A',
    'Ã' => 'A',
    'Â' => 'A',
    'É' => 'E',
    'Ê' => 'E',
    'Í' => 'I',
    'Ó' => 'O',
    'Ô' => 'O',
    'Õ' => 'O',
    'Ú' => 'U',
    'Ü' => 'U',
    'Ç' => 'C',
  );
  return strtr($string, $map);
}

function cnpj($number)
{
  // Devolve formatado com a máscara adequada
  $cnpj_cpf = preg_replace("/\D/", '', $number);
  if (strlen($cnpj_cpf) === 14) {
    return preg_replace("/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/", "\$1.\$2.\$3/\$4-\$5", $cnpj_cpf);
  }
  return preg_replace("/(\d{3})(\d{3})(\d{3})(\d{2})/", "\$1.\$2.\$3-\$4", $cnpj_cpf);
}

function numero($number)
{
  return number_format($number, 0, '.', '.');
}

function reais($decimal)
{
  return number_format($decimal, 2, ",", ".");
}

function data($date)
{
  if (!empty($date)) {
    return nice_date($date, 'd/m/Y H:i');
  } else {
    return null;
  }
}

function databanco($date)
{
  $date = date("Y-m-d H:i:s", strtotime(str_replace('/', '-', $date)));
  return ($date);
}

function comporprazo($diff)
{
  $composicao = "";
  if ($diff->y > 0) {
    $composicao .= $diff->y . ' ano(s) ';
  }
  if ($diff->m > 0) {
    $composicao .= $diff->m . ' mês(es) ';
  } else {
    if ($diff->d > 0) {
      $composicao .= $diff->d . ' dia(s) ';
    } else {
      if ($diff->h > 0) {
        $composicao .= $diff->h . ' hora(s) ';
      } else {
        if ($diff->i > 0) {
          $composicao .= $diff->i . ' minuto(s)';
        }
      }
    }
  }
  return $composicao;
}

function sonumero($str)
{
  return preg_replace("/[^0-9]/", "", $str);
}

function valida_cpf($cpf)
{
  // Extrai somente os números
  $cpf = preg_replace('/[^0-9]/is', '', $cpf);

  // Verifica se foi informado todos os digitos corretamente
  if (strlen($cpf) != 11) {
    return false;
  }

  // Verifica se foi informada uma sequência de digitos repetidos. Ex: 111.111.111-11
  if (preg_match('/(\d)\1{10}/', $cpf)) {
    return false;
  }

  // Faz o calculo para validar o CPF
  for ($t = 9; $t < 11; $t++) {
    for ($d = 0, $c = 0; $c < $t; $c++) {
      $d += $cpf[$c] * (($t + 1) - $c);
    }
    $d = ((10 * $d) % 11) % 10;
    if ($cpf[$c] != $d) {
      return false;
    }
  }
  return true;
}

function valida_cnpj($cnpj)
{
  $cnpj = preg_replace('/[^0-9]/', '', (string) $cnpj);

  // Valida tamanho
  if (strlen($cnpj) != 14)
    return false;

  // Verifica se todos os digitos são iguais
  if (preg_match('/(\d)\1{13}/', $cnpj))
    return false;

  // Valida primeiro dígito verificador
  for ($i = 0, $j = 5, $soma = 0; $i < 12; $i++) {
    $soma += $cnpj[$i] * $j;
    $j = ($j == 2) ? 9 : $j - 1;
  }

  $resto = $soma % 11;

  if ($cnpj[12] != ($resto < 2 ? 0 : 11 - $resto))
    return false;

  // Valida segundo dígito verificador
  for ($i = 0, $j = 6, $soma = 0; $i < 13; $i++) {
    $soma += $cnpj[$i] * $j;
    $j = ($j == 2) ? 9 : $j - 1;
  }

  $resto = $soma % 11;

  return $cnpj[13] == ($resto < 2 ? 0 : 11 - $resto);
}

function valorExtenso($valor = 0, $bolExibirMoeda = true, $bolPalavraFeminina = false)
{
  $valor = removerFormatacaoNumero($valor);
  $singular = null;
  $plural = null;
  if ($bolExibirMoeda) {
    $singular = array("centavo", "real", "mil", "milhão", "bilhão", "trilhão", "quatrilhão");
    $plural = array("centavos", "reais", "mil", "milhões", "bilhões", "trilhões", "quatrilhões");
  } else {
    $singular = array("", "", "mil", "milhão", "bilhão", "trilhão", "quatrilhão");
    $plural = array("", "", "mil", "milhões", "bilhões", "trilhões", "quatrilhões");
  }
  $c = array("", "cem", "duzentos", "trezentos", "quatrocentos", "quinhentos", "seiscentos", "setecentos", "oitocentos", "novecentos");
  $d = array("", "dez", "vinte", "trinta", "quarenta", "cinquenta", "sessenta", "setenta", "oitenta", "noventa");
  $d10 = array("dez", "onze", "doze", "treze", "quatorze", "quinze", "dezesseis", "dezessete", "dezoito", "dezenove");
  $u = array("", "um", "dois", "três", "quatro", "cinco", "seis", "sete", "oito", "nove");
  if ($bolPalavraFeminina) {
    if ($valor == 1) {
      $u = array("", "uma", "duas", "três", "quatro", "cinco", "seis", "sete", "oito", "nove");
    } else {
      $u = array("", "um", "duas", "três", "quatro", "cinco", "seis", "sete", "oito", "nove");
    }
    $c = array("", "cem", "duzentas", "trezentas", "quatrocentas", "quinhentas", "seiscentas", "setecentas", "oitocentas", "novecentas");
  }
  $z = 0;
  $valor = number_format($valor, 2, ".", ".");
  $inteiro = explode(".", $valor);
  for ($i = 0; $i < count($inteiro); $i++) {
    for ($ii = mb_strlen($inteiro[$i]); $ii < 3; $ii++) {
      $inteiro[$i] = "0" . $inteiro[$i];
    }
  }
  // $fim identifica onde que deve se dar junção de centenas por "e" ou por "," ;)
  $rt = null;
  $fim = count($inteiro) - ($inteiro[count($inteiro) - 1] > 0 ? 1 : 2);
  for ($i = 0; $i < count($inteiro); $i++) {
    $valor = $inteiro[$i];
    $rc = (($valor > 100) && ($valor < 200)) ? "cento" : $c[$valor[0]];
    $rd = ($valor[1] < 2) ? "" : $d[$valor[1]];
    $ru = ($valor > 0) ? (($valor[1] == 1) ? $d10[$valor[2]] : $u[$valor[2]]) : "";
    $r = $rc . (($rc && ($rd || $ru)) ? " e " : "") . $rd . (($rd && $ru) ? " e " : "") . $ru;
    $t = count($inteiro) - 1 - $i;
    $r .= $r ? " " . ($valor > 1 ? $plural[$t] : $singular[$t]) : "";
    if ($valor == "000")
      $z++;
    elseif ($z > 0)
      $z--;
    if (($t == 1) && ($z > 0) && ($inteiro[0] > 0))
      $r .= (($z > 1) ? " de " : "") . $plural[$t];
    if ($r)
      $rt = $rt . ((($i > 0) && ($i <= $fim) && ($inteiro[0] > 0) && ($z < 1)) ? (($i < $fim) ? ", " : " e ") : " ") . $r;
  }
  $rt = mb_substr($rt, 1);
  return ($rt ? trim($rt) : "zero");
}

function removerFormatacaoNumero($strNumero)
{
  $strNumero = trim(str_replace("R$", "", $strNumero));
  $vetVirgula = explode(",", $strNumero);
  if (count($vetVirgula) == 1) {
    $acentos = array(".");
    $resultado = str_replace($acentos, "", $strNumero);
    return $resultado;
  } else if (count($vetVirgula) != 2) {
    return $strNumero;
  }
  $strNumero = $vetVirgula[0];
  $strDecimal = mb_substr($vetVirgula[1], 0, 2);
  $acentos = array(".");
  $resultado = str_replace($acentos, "", $strNumero);
  $resultado = $resultado . "." . $strDecimal;
  return $resultado;
}

function primeiro_nome($string)
{
  $name = explode(' ', $string);
  return $name[0];
}

function somar_dias_uteis($str_data, $int_qtd_dias_somar = 7)
{
  // Caso seja informado uma data do MySQL do tipo DATETIME - aaaa-mm-dd 00:00:00
  // Transforma para DATE - aaaa-mm-dd
  $str_data = substr($str_data, 0, 10);
  // Se a data estiver no formato brasileiro: dd/mm/aaaa
  // Converte-a para o padrão americano: aaaa-mm-dd
  if (preg_match("@/@", $str_data) == 1) {
    $str_data = implode("-", array_reverse(explode("/", $str_data)));
  }
  $array_data = explode('-', $str_data);
  $count_days = 0;
  $int_qtd_dias_uteis = 0;

  while ($int_qtd_dias_uteis < $int_qtd_dias_somar) {
    $count_days++;
    if (($dias_da_semana = gmdate('w', strtotime('+' . $count_days . ' day', mktime(0, 0, 0, $array_data[1], $array_data[2], $array_data[0])))) != '0' && $dias_da_semana != '6') {
      $int_qtd_dias_uteis++;
    }
  }
  return gmdate('Y-m-d', strtotime('+' . $count_days . ' day', strtotime($str_data)));
}

function subtrair_dias_uteis($str_data, $int_qtd_dias_subtrair = 7)
{
  // Caso seja informado uma data do MySQL do tipo DATETIME - aaaa-mm-dd 00:00:00
  // Transforma para DATE - aaaa-mm-dd
  $str_data = substr($str_data, 0, 10);
  // Se a data estiver no formato brasileiro: dd/mm/aaaa
  // Converte-a para o padrão americano: aaaa-mm-dd
  if (preg_match("@/@", $str_data) == 1) {
    $str_data = implode("-", array_reverse(explode("/", $str_data)));
  }
  $array_data = explode('-', $str_data);
  $count_days = 0;
  $int_qtd_dias_uteis = 0;

  while ($int_qtd_dias_uteis < $int_qtd_dias_subtrair) {
    $count_days++;
    if (($dias_da_semana = gmdate('w', strtotime('-' . $count_days . ' day', mktime(0, 0, 0, $array_data[1], $array_data[2], $array_data[0])))) != '0' && $dias_da_semana != '6') {
      $int_qtd_dias_uteis++;
    }
  }
  return gmdate('Y-m-d', strtotime('-' . $count_days . ' day', strtotime($str_data)));
}

function dd(...$var)
{
  foreach ($var as $dump) {
    echo '<pre style="background-color: #fff">';
    highlight_string("<?php\n\$data =\n" . var_export($dump, true) . ";\n?>");
    echo '</pre>';
    echo '<hr>';
  }
  die();
}

function dnd(...$var)
{
  foreach ($var as $dump) {
    echo '<pre style="background-color: #fff">';
    highlight_string("<?php\n\$data =\n" . var_export($dump, true) . ";\n?>");
    echo '</pre>';
    echo '<hr>';
  }
}

function di($value)
{
  echo '<pre>';
  echo json_encode($value);
  echo '</pre>';
  echo '<hr>';
}

function mask($mask, $str)
{
  $str = str_replace(" ", "", $str);
  for ($i = 0; $i < strlen($str); $i++) {
    $mask[strpos($mask, "#")] = $str[$i];
  }
  return $mask;
}

if (!function_exists('mailbox_is_protected')) {
  /**
   * Contas técnicas (ex.: DMARC) não devem aparecer na listagem nem ser
   * alteradas/excluídas pelo cliente no painel.
   */
  function mailbox_is_protected($email)
  {
    $email = strtolower(trim((string) $email));
    if ($email === '' || strpos($email, '@') === FALSE) {
      return FALSE;
    }

    $local = substr($email, 0, strrpos($email, '@'));
    if ($local === 'dmarc') {
      return TRUE;
    }

    return strpos($local, 'dmarc.') === 0
      || strpos($local, 'dmarc-') === 0
      || strpos($local, 'dmarc_') === 0;
  }
}

if (!function_exists('mailbox_protected_message')) {
  function mailbox_protected_message()
  {
    return 'Esta conta é técnica (DMARC) e não pode ser gerenciada pelo painel.';
  }
}

if (!function_exists('mailbox_filter_protected')) {
  /**
   * Remove contas protegidas de uma listagem retornada pelo painel de hospedagem.
   *
   * @param array<int, array<string, mixed>> $mailboxes
   * @return array<int, array<string, mixed>>
   */
  function mailbox_filter_protected(array $mailboxes)
  {
    return array_values(array_filter($mailboxes, function ($box) {
      $email = is_array($box) ? ($box['email'] ?? '') : '';
      return !mailbox_is_protected($email);
    }));
  }
}

if (!function_exists('sessao_suspender')) {
  /**
   * Fecha a sessão antes de uma perna longa de rede (FTP/SFTP, cURL, GA4).
   *
   * O driver de sessão em banco segura um GET_LOCK do MySQL do início ao fim
   * da request. Enquanto um upload FTP roda, esse lock fica preso e a conexão
   * fica ociosa — e se a request morrer no meio (max_execution_time, client
   * abort), o RELEASE_LOCK do shutdown cai numa conexão dessincronizada
   * ("Commands out of sync"). Fechar antes descarrega o UPDATE e o
   * RELEASE_LOCK enquanto a conexão ainda está sã.
   *
   * ATENÇÃO: entre suspender e retomar não escreva na sessão
   * (set_userdata / set_flashdata / sess_destroy). Ler continua funcionando,
   * mas o session_start() da retomada sobrescreve $_SESSION com a cópia do
   * banco e descarta silenciosamente o que foi escrito na janela.
   *
   * @return bool TRUE se a sessão foi realmente fechada (passar para sessao_retomar)
   */
  function sessao_suspender()
  {
    if (session_status() !== PHP_SESSION_ACTIVE) {
      return FALSE;
    }

    session_write_close();

    return TRUE;
  }
}

if (!function_exists('sessao_retomar')) {
  /**
   * Reabre a sessão fechada por sessao_suspender().
   *
   * @param  bool $suspendeu retorno de sessao_suspender()
   * @return bool
   */
  function sessao_retomar($suspendeu)
  {
    if (!$suspendeu || session_status() !== PHP_SESSION_NONE) {
      return FALSE;
    }

    // O PHP só reemite Set-Cookie quando o ID é novo; um ID vindo do $_COOKIE
    // não gera header. Ainda assim, não tenta reabrir com o corpo já enviado.
    if (headers_sent()) {
      return FALSE;
    }

    return (bool) @session_start();
  }
}

if (!function_exists('inscricao_estadual')) {
  /**
   * Normaliza a inscrição estadual do cliente (crm_customers.state_registration).
   *
   * Regra única, usada pelo wizard público e pela edição do painel: em branco é
   * ISENTO, que é o padrão da coluna e o caso da maioria dos clientes da CDW
   * (prestador de serviço não contribuinte de ICMS). Sobe para maiúsculas
   * porque a coluna alimenta o ERP e comparações do tipo "é isento?" são feitas
   * contra a palavra exata — deixar "Isento" e "ISENTO" convivendo criaria dois
   * valores para o mesmo estado.
   *
   * Não valida o formato: cada estado tem o seu (de 8 a 14 dígitos, alguns com
   * máscara), e recusar um número legítimo travaria o cadastro público.
   *
   * @param  string $valor
   * @return string
   */
  function inscricao_estadual($valor)
  {
    $valor = trim((string) $valor);
    if ($valor === '') {
      return 'ISENTO';
    }

    // "ISENTA" é o jeito que muita gente digita; guardar as duas formas criaria
    // dois valores para o mesmo estado.
    $valor = mb_strtoupper($valor);
    if ($valor === 'ISENTA' || $valor === 'ISENTO') {
      return 'ISENTO';
    }

    return mb_substr($valor, 0, 20);
  }
}
