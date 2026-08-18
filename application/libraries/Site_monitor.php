<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Checagem de DNS e da home de um site.
 *
 * Duas operações independentes, `checarDns()` e `checarHttp()`, cada uma
 * devolvendo o mesmo formato de array das integrações do projeto. NUNCA lança
 * exceção: quem chama é um cron que varre centenas de domínios, e uma falha num
 * deles não pode derrubar a rodada.
 *
 *   ['success' => bool, 'message' => string, 'data' => array|null, 'transient' => bool]
 *
 * `transient` marca falha de INFRA nossa (resolvedor, saída de internet) em
 * oposição a problema do site. É o que alimenta o disjuntor da rodada no model —
 * sem essa distinção, uma piscada na rede viraria 455 e-mails de "site fora".
 *
 * NÃO estende `Whois_provider`: aquela classe abstrata exige `lookup()` e
 * `servico()` e carrega as flags `premium`/`livre`, que não significam nada
 * aqui. O que se compartilha é a FORMA do retorno, não a hierarquia.
 *
 * ------------------------------------------------------------------
 * Decisões de cURL que existem para não gerar alarme falso
 * ------------------------------------------------------------------
 * - A leitura é ABORTADA no `</head>` ou em MAX_BYTES: não faz sentido baixar a
 *   home inteira de centenas de sites só para ler o `<title>`. O abort produz
 *   `CURLE_WRITE_ERROR` (23), que é NOSSO e precisa contar como sucesso — sem
 *   essa exceção, todo site do mundo reportaria falha.
 *
 * - NÃO pedimos `gzip` (nada de CURLOPT_ENCODING). Com o corpo comprimido, um
 *   buffer truncado é um stream incompleto que não infla: o título sairia vazio
 *   e viraria "título alterado" todo santo dia.
 *
 * - `SSL_VERIFYPEER` desligado, certificado avaliado por nós via CERTINFO. Com a
 *   verificação ligada, um site de certificado vencido falha por inteiro e
 *   perderíamos o título justamente onde há problema; assim, certificado ruim
 *   vira INFORMAÇÃO em vez de apagão.
 *
 * - `PROTOCOLS`/`REDIR_PROTOCOLS` restritos a HTTP/HTTPS: seguimos redirect
 *   apontado por terceiro, a partir do servidor da aplicação. Sem a trava, um
 *   `Location: file:///etc/passwd` seria seguido — SSRF.
 *
 * - User-Agent de navegador real. WAF (Cloudflare, Sucuri, Wordfence) responde
 *   403 para `curl/7.x`, e como isso é característica ESTÁVEL do site, o alarme
 *   seria diário e permanente.
 */
class Site_monitor
{
  /** Timeout total por tentativa. Site que passa disso já é um problema. */
  const TIMEOUT_PADRAO = 12;

  /** Conexão separada do total: host morto tem de desistir rápido. */
  const CONNECT_TIMEOUT = 5;

  /** Teto de leitura do corpo. O `<title>` mora bem no começo do documento. */
  const MAX_BYTES = 65536;

  const MAX_REDIRECTS = 5;

  const USER_AGENT = 'Mozilla/5.0 (compatible; CDWFinanceMonitor/1.0; +https://cdwtech.com.br/monitor)';

  /**
   * Marcadores de problema, procurados NO TÍTULO e no primeiro `<h1>` — nunca no
   * corpo inteiro.
   *
   * Buscar `Index of /` no HTML todo casaria com um post de blog sobre Apache, e
   * `Account Suspended` com a base de conhecimento de uma hospedagem. Ancorado,
   * a detecção fica precisa: a página de suspensão do cPanel tem literalmente
   * `<title>Account Suspended</title>` com HTTP 200, e o autoindex do Apache tem
   * `<title>Index of /...</title>`.
   */
  const MARCADORES = [
    'suspenso' => '/account suspended|conta suspensa|site suspenso|suspended page/iu',
    'index_of' => '/^index of |^diret[óo]rio de /iu',
    'padrao_servidor' => '/apache2? (ubuntu|debian|centos)? ?default page|welcome to nginx|iis windows server|test page for|cpanel®? default|it works!/iu',
    'parking' => '/dom[íi]nio (registrado|estacionado)|parked (domain|free)|em constru[çc][ãa]o|under construction|future home of/iu',
  ];

  /**
   * Nameservers do DNS AO VIVO, consultados no apex.
   *
   * O apex é obrigatório: `dns_get_record('www.exemplo.com', DNS_NS)` volta
   * vazio, porque NS só existe no ápice da zona — e em algumas zonas o
   * resolvedor devolve o NS da zona-pai, o que é pior, porque acerta por
   * acidente. Quem reduz ao apex é o model, com `registrableDomain()`.
   *
   * Resposta vazia NÃO é "o domínio perdeu o NS": `dns_get_record` devolve `[]`
   * tanto para domínio inexistente quanto para SERVFAIL do resolvedor, e não há
   * como separar os dois por aqui. Vira `nao_checado`, e o model preserva o valor
   * anterior — a alternativa seria transformar uma piscada do resolvedor em
   * "todos os domínios trocaram de NS para nada".
   *
   * @param  string $apex domínio registrável
   * @return array
   */
  public function checarDns($apex)
  {
    $apex = $this->paraAscii($apex);

    if ($apex === '') {
      return $this->resultado(FALSE, 'Domínio inválido para consulta de DNS.', NULL, FALSE);
    }

    // O @ é necessário: dns_get_record emite warning em SERVFAIL, e num cron que
    // roda centenas de domínios isso polui o stdout sem acrescentar nada — a
    // falha já vem no retorno.
    $registros = @dns_get_record($apex, DNS_NS);

    if ($registros === FALSE || !is_array($registros) || empty($registros)) {
      return $this->resultado(
        FALSE,
        'O DNS não respondeu com nameservers para ' . $apex . '.',
        NULL,
        TRUE
      );
    }

    $lista = [];
    foreach ($registros as $registro) {
      if (empty($registro['target'])) continue;
      $host = rtrim(mb_strtolower(trim((string) $registro['target'])), '.');
      if ($host !== '') $lista[] = mb_substr($host, 0, 255);
    }

    if (empty($lista)) {
      return $this->resultado(FALSE, 'Resposta de DNS sem nameserver legível.', NULL, TRUE);
    }

    // Único e ORDENADO, no mesmo formato de `whois_nameservers`: é o que torna a
    // comparação confiável. O resolvedor devolve o mesmo conjunto em ordem
    // diferente entre consultas (verificado), e comparar posicionalmente geraria
    // "NS trocado" em massa. O preço é que ns1/ns2 passam a ser o primeiro e o
    // segundo em ordem alfabética, não o literal NS1 do registrador.
    $lista = array_values(array_unique($lista));
    sort($lista);

    return $this->resultado(TRUE, 'Consulta de DNS concluída.', [
      'nameservers' => $lista,
      'ns_list' => mb_substr(implode(',', $lista), 0, 500),
      'ns1' => isset($lista[0]) ? $lista[0] : NULL,
      'ns2' => isset($lista[1]) ? $lista[1] : NULL,
    ], FALSE);
  }

  /**
   * Abre a home e devolve status, título, marcador e certificado.
   *
   * Tenta uma CASCATA de endereços, porque o host cadastrado nem sempre é o que
   * serve o site: é comum o apex não ter registro A e só o `www.` responder, e
   * ainda existe cliente pequeno em http puro. Sem a cascata, essas duas classes
   * inteiras entrariam no resumo como "fora do ar".
   *
   * @param  string      $host          host a checar (sem esquema)
   * @param  string|null $urlPreferida  URL COMPLETA que respondeu da última vez
   * @param  int         $timeout
   * @return array
   */
  public function checarHttp($host, $urlPreferida = NULL, $timeout = self::TIMEOUT_PADRAO)
  {
    $host = $this->normalizarHost($host);
    if ($host === '') {
      return $this->resultado(FALSE, 'Host inválido.', ['http_result' => 'nao_checado'], FALSE);
    }

    $primeiraResposta = NULL;
    $primeiraFalha = NULL;
    $hostsSemDns = [];

    foreach ($this->cascata($host, $urlPreferida) as $url) {
      // Host que não resolve em DNS não resolve em esquema nenhum: tentar o
      // `http://` do mesmo nome depois do `https://` é esperar duas vezes pela
      // mesma resposta. Só o par apex/www justifica nova tentativa, porque são
      // nomes diferentes e podem ter registros diferentes.
      $hostDaVez = $this->hostDaUrl($url);
      if ($hostDaVez !== NULL && isset($hostsSemDns[$hostDaVez])) continue;

      $tentativa = $this->requisitar($url, $timeout);

      if ($hostDaVez !== NULL && isset($tentativa['data']['http_result'])
          && $tentativa['data']['http_result'] === 'dns') {
        $hostsSemDns[$hostDaVez] = TRUE;
      }

      // 2xx/3xx: o site respondeu de verdade, pode parar a cascata aqui.
      if ($tentativa['data']['http_status'] >= 200 && $tentativa['data']['http_status'] < 400) {
        return $tentativa;
      }

      // Respondeu, mas com erro (403 de WAF, 404, 5xx). Guarda a PRIMEIRA
      // dessas: se nenhum endereço da cascata der 2xx, é este o diagnóstico
      // certo — bem melhor que o erro de transporte do último da fila.
      if ($primeiraResposta === NULL && $tentativa['data']['http_status'] > 0) {
        $primeiraResposta = $tentativa;
      }

      if ($primeiraFalha === NULL) $primeiraFalha = $tentativa;
    }

    if ($primeiraResposta !== NULL) return $primeiraResposta;

    return $primeiraFalha;
  }

  // ------------------------------------------------------------------
  // Internos
  // ------------------------------------------------------------------

  /**
   * Endereços a tentar, em ordem, sem repetir.
   *
   * Da checagem anterior aproveita-se APENAS O ESQUEMA, nunca o host.
   *
   * O esquema é o que custa caro: num site só-http, o `https://` fica pendurado
   * até o timeout antes de a cascata cair para o `http://` — medido em 15,9s
   * contra 0,3s. Sem essa memória, toda a classe de sites sem HTTPS pagaria isso
   * em toda rodada, para sempre.
   *
   * Já o HOST tem de voltar sempre à ordem canônica (apex antes de www), porque
   * reaproveitá-lo TRAVA o monitor na variante errada: bastou uma falha
   * passageira no apex, numa única rodada, para um domínio ficar preso em
   * `www.` — e como o certificado dele cobria só o apex, a checagem passou a
   * relatar "certificado não cobre este domínio" indefinidamente, enquanto o
   * endereço canônico estava perfeito. Um retrato ruim não pode se
   * autoperpetuar.
   *
   * @param  string      $host
   * @param  string|null $urlPreferida URL completa da última resposta boa
   * @return array
   */
  private function cascata($host, $urlPreferida)
  {
    $comWww = (strpos($host, 'www.') === 0) ? $host : 'www.' . $host;
    $semWww = preg_replace('/^www\./', '', $host);

    $esquemas = ['https', 'http'];

    $urlPreferida = trim((string) $urlPreferida);
    if ($urlPreferida !== '' && preg_match('#^https?://#i', $urlPreferida)) {
      $esquema = strtolower((string) parse_url($urlPreferida, PHP_URL_SCHEME));
      if ($esquema === 'http') $esquemas = ['http', 'https'];
    }

    $urls = [];
    foreach ($esquemas as $esquema) {
      $urls[] = $esquema . '://' . $semWww . '/';
      $urls[] = $esquema . '://' . $comWww . '/';
    }

    return array_values(array_unique($urls));
  }

  /**
   * Uma requisição da cascata, já interpretada.
   *
   * @param  string $url
   * @param  int    $timeout
   * @return array
   */
  private function requisitar($url, $timeout)
  {
    $timeout = (int) $timeout;
    if ($timeout <= 0) $timeout = self::TIMEOUT_PADRAO;

    $buffer = '';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_FOLLOWLOCATION => TRUE,
      CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
      CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
      CURLOPT_TIMEOUT => $timeout,
      // Ver o docblock da classe: desligado de propósito, para o certificado
      // ruim virar informação em vez de apagar o título.
      CURLOPT_SSL_VERIFYPEER => FALSE,
      CURLOPT_SSL_VERIFYHOST => 0,
      CURLOPT_CERTINFO => TRUE,
      CURLOPT_USERAGENT => self::USER_AGENT,
      CURLOPT_HTTPHEADER => [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
      ],
      CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
      CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
      CURLOPT_UNRESTRICTED_AUTH => FALSE,
      CURLOPT_HEADER => FALSE,
      CURLOPT_RETURNTRANSFER => FALSE,
      CURLOPT_WRITEFUNCTION => function ($recurso, $pedaco) use (&$buffer) {
        $buffer .= $pedaco;
        // -1 aborta o download. É o nosso corte, não um erro: o chamador trata
        // CURLE_WRITE_ERROR como sucesso.
        if (strlen($buffer) >= self::MAX_BYTES || stripos($buffer, '</head>') !== FALSE) return -1;
        return strlen($pedaco);
      },
    ]);

    curl_exec($ch);

    $erro = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $urlFinal = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $certificado = curl_getinfo($ch, CURLINFO_CERTINFO);
    $erroTexto = curl_error($ch);
    curl_close($ch);

    // CURLE_WRITE_ERROR (23) é o nosso próprio abort da leitura.
    $abortadoPorNos = ($erro === CURLE_WRITE_ERROR);
    if ($erro !== 0 && !$abortadoPorNos) {
      return $this->falhaTransporte($erro, $erroTexto, $urlFinal);
    }

    $titulo = $this->extrairTitulo($buffer, $contentType);
    $ssl = $this->interpretarCertificado($certificado, $urlFinal);

    $dados = [
      'http_status' => $status,
      'http_result' => $this->classificarStatus($status),
      'http_final_url' => mb_substr($urlFinal, 0, 500),
      'check_host' => $this->hostDaUrl($urlFinal),
      'title' => $titulo,
      'flag' => $this->detectarMarcador($titulo, $buffer),
      'ssl_expiration_date' => $ssl['expiration_date'],
      'ssl_issuer' => $ssl['issuer'],
      'ssl_status' => $ssl['status'],
    ];

    $ok = ($status >= 200 && $status < 400);

    return $this->resultado(
      $ok,
      $ok ? 'Home carregada.' : 'O site respondeu HTTP ' . $status . '.',
      $dados,
      FALSE
    );
  }

  /**
   * Falha de transporte, traduzida para uma causa acionável.
   *
   * Loop de redirect NÃO é "site fora": o servidor está respondendo, é
   * `.htaccess` de http↔https mal configurado — e o diagnóstico certo poupa uma
   * investigação inteira.
   *
   * @param  int    $erro
   * @param  string $texto
   * @param  string $urlFinal
   * @return array
   */
  private function falhaTransporte($erro, $texto, $urlFinal)
  {
    switch ($erro) {
      case CURLE_COULDNT_RESOLVE_HOST:
        $resultado = 'dns';
        $mensagem = 'O domínio não resolve em DNS.';
        break;
      case CURLE_OPERATION_TIMEOUTED:
        $resultado = 'timeout';
        $mensagem = 'O site não respondeu dentro do tempo limite.';
        break;
      case CURLE_TOO_MANY_REDIRECTS:
        $resultado = 'redirect_loop';
        $mensagem = 'O site entrou em laço de redirecionamento (o servidor responde, mas nunca chega numa página).';
        break;
      case CURLE_COULDNT_CONNECT:
        $resultado = 'conexao';
        $mensagem = 'Não foi possível conectar no servidor do site.';
        break;
      default:
        $resultado = 'conexao';
        $mensagem = 'Falha ao abrir o site: ' . $texto;
    }

    return $this->resultado(FALSE, $mensagem, [
      'http_status' => 0,
      'http_result' => $resultado,
      'http_final_url' => mb_substr((string) $urlFinal, 0, 500),
      'check_host' => NULL,
      'title' => NULL,
      'flag' => NULL,
      'ssl_expiration_date' => NULL,
      'ssl_issuer' => NULL,
      'ssl_status' => NULL,
    ], FALSE);
  }

  /**
   * 401/403/429 são `bloqueado`, e não "fora do ar".
   *
   * É WAF recusando bot — o site está no ar e funcionando para o visitante. Como
   * é característica estável, tratar como queda produziria um alarme diário
   * permanente para esses domínios.
   *
   * @param  int $status
   * @return string
   */
  private function classificarStatus($status)
  {
    if ($status >= 200 && $status < 400) return 'ok';
    if (in_array($status, [401, 403, 429], TRUE)) return 'bloqueado';
    if ($status >= 400) return 'http_erro';
    return 'nao_checado';
  }

  /**
   * Título da home, normalizado e seguro para uma coluna utf8 de 3 bytes.
   *
   * @param  string $html
   * @param  string $contentType
   * @return string|null NULL = não foi possível medir (o model preserva o anterior)
   */
  private function extrairTitulo($html, $contentType)
  {
    if ($html === '') return NULL;

    $html = $this->paraUtf8($html, $contentType);
    if ($html === NULL) return NULL;

    if (!preg_match('/<title[^>]*>(.*?)<\/title>/isu', $html, $captura)) return NULL;

    return $this->limparTexto($captura[1]);
  }

  /**
   * Converte o documento para UTF-8 usando a ordem FIXA de declarações.
   *
   * Nada de `mb_detect_encoding`: o palpite dele sobre um buffer truncado em
   * ponto variável muda entre rodadas, e o título "mudaria" sozinho. Se não der
   * para converter com segurança, devolve NULL e o campo vira "não medido".
   *
   * @param  string $html
   * @param  string $contentType
   * @return string|null
   */
  private function paraUtf8($html, $contentType)
  {
    $charset = '';

    if (preg_match('/charset=["\']?([a-z0-9_\-]+)/i', (string) $contentType, $m)) {
      $charset = strtolower($m[1]);
    } elseif (preg_match('/<meta[^>]+charset=["\']?([a-z0-9_\-]+)/i', $html, $m)) {
      $charset = strtolower($m[1]);
    }

    if ($charset === '' || $charset === 'utf8') $charset = 'utf-8';

    if ($charset !== 'utf-8') {
      $convertido = @mb_convert_encoding($html, 'UTF-8', $charset);
      if ($convertido !== FALSE && $convertido !== '') $html = $convertido;
    }

    // Truncar no meio de um caractere multibyte deixa bytes órfãos no fim; o
    // título mora bem antes disso, então basta descartar o resto inválido.
    if (!mb_check_encoding($html, 'UTF-8')) {
      $html = @mb_convert_encoding($html, 'UTF-8', 'UTF-8');
      if ($html === FALSE || !mb_check_encoding($html, 'UTF-8')) return NULL;
    }

    return $html;
  }

  /**
   * Decodifica entidades, colapsa espaço e REMOVE caracteres de 4 bytes.
   *
   * O emoji é o ponto crítico: a coluna é `utf8` de 3 bytes e o MySQL REJEITA o
   * INSERT em vez de truncar. Com `db_debug = FALSE` isso falharia em silêncio,
   * e o título nunca seria gravado justamente nos sites que usam emoji. A mesma
   * limpeza é aplicada antes de COMPARAR, senão o título "mudaria" a cada rodada.
   *
   * @param  string $texto
   * @return string|null
   */
  private function limparTexto($texto)
  {
    $texto = html_entity_decode((string) $texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $texto = strip_tags($texto);
    $texto = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $texto);
    $texto = preg_replace('/\s+/u', ' ', $texto);
    $texto = trim((string) $texto);

    if ($texto === '') return NULL;

    return mb_substr($texto, 0, 255);
  }

  /**
   * Marcador de problema, procurado no título e no primeiro `<h1>`.
   *
   * Título ausente também é sinal: página em branco, erro de PHP engolido ou
   * diretório vazio não têm `<title>`.
   *
   * @param  string|null $titulo
   * @param  string      $html
   * @return string|null
   */
  private function detectarMarcador($titulo, $html)
  {
    $alvos = [];
    if ($titulo !== NULL && $titulo !== '') $alvos[] = $titulo;

    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/isu', (string) $html, $captura)) {
      $h1 = $this->limparTexto($captura[1]);
      if ($h1 !== NULL) $alvos[] = $h1;
    }

    if (empty($alvos)) return 'sem_titulo';

    foreach (self::MARCADORES as $flag => $padrao) {
      foreach ($alvos as $alvo) {
        if (preg_match($padrao, $alvo)) return $flag;
      }
    }

    return NULL;
  }

  /**
   * Vencimento, emissor e validade do certificado, a partir do CERTINFO.
   *
   * Como a verificação de SSL está desligada, é aqui que o certificado ruim é
   * percebido: sem esta conferência, certificado de nome errado ou autoassinado
   * passaria por "no ar" enquanto o navegador do cliente mostra tela vermelha.
   *
   * Com `FOLLOWLOCATION`, o CERTINFO é o da ÚLTIMA conexão da cadeia — por isso
   * o nome é conferido contra o host da URL final, e não contra o host pedido.
   *
   * @param  mixed  $certificado retorno de CURLINFO_CERTINFO
   * @param  string $urlFinal
   * @return array
   */
  private function interpretarCertificado($certificado, $urlFinal)
  {
    $vazio = ['expiration_date' => NULL, 'issuer' => NULL, 'status' => NULL];

    if (strpos((string) $urlFinal, 'https://') !== 0) {
      return ['expiration_date' => NULL, 'issuer' => NULL, 'status' => 'ausente'];
    }

    if (!is_array($certificado) || empty($certificado[0])) return $vazio;

    $folha = $certificado[0];

    $expiracao = NULL;
    if (!empty($folha['Expire date'])) {
      $timestamp = strtotime($folha['Expire date']);
      if ($timestamp !== FALSE) {
        $ano = (int) date('Y', $timestamp);
        // Mesma guarda de sanidade do Whois_provider: data corrompida não entra.
        if ($ano >= 1990 && $ano <= 2100) $expiracao = date('Y-m-d', $timestamp);
      }
    }

    $emissor = NULL;
    if (!empty($folha['Issuer'])) {
      if (preg_match('/(?:^|,\s*)(?:CN|O)\s*=\s*([^,\/]+)/i', $folha['Issuer'], $m)) {
        $emissor = mb_substr(trim($m[1]), 0, 150);
      }
    }

    $status = 'ok';
    if ($expiracao !== NULL && $expiracao < date('Y-m-d')) {
      $status = 'vencido';
    } elseif (!$this->certificadoCobreHost($folha, $this->hostDaUrl($urlFinal))) {
      $status = 'nome_divergente';
    }

    return ['expiration_date' => $expiracao, 'issuer' => $emissor, 'status' => $status];
  }

  /**
   * O certificado vale para este host? Confere SAN e, na falta dele, o CN.
   *
   * @param  array  $folha
   * @param  string $host
   * @return bool
   */
  private function certificadoCobreHost(array $folha, $host)
  {
    $host = mb_strtolower((string) $host);
    if ($host === '') return TRUE;

    $nomes = [];

    if (!empty($folha['Subject Alternative Name'])) {
      foreach (explode(',', $folha['Subject Alternative Name']) as $entrada) {
        $entrada = trim($entrada);
        if (stripos($entrada, 'DNS:') === 0) $nomes[] = mb_strtolower(trim(substr($entrada, 4)));
      }
    }

    if (empty($nomes) && !empty($folha['Subject'])
        && preg_match('/(?:^|,\s*)CN\s*=\s*([^,\/]+)/i', $folha['Subject'], $m)) {
      $nomes[] = mb_strtolower(trim($m[1]));
    }

    // Sem nome legível não dá para afirmar divergência — e acusar por falta de
    // dado seria inventar um problema.
    if (empty($nomes)) return TRUE;

    foreach ($nomes as $nome) {
      if ($nome === $host) return TRUE;

      if (strpos($nome, '*.') === 0) {
        $base = substr($nome, 2);

        // O curinga também cobre o DOMÍNIO NU. Pela RFC 6125, `*.foo.com` não
        // vale para `foo.com` — mas na prática todo certificado curinga emitido
        // hoje traz o apex junto no SAN, e quando o certificado é antigo (só CN,
        // sem SAN) essa informação simplesmente não está visível aqui. Exigir a
        // letra da RFC transformaria toda hospedagem com curinga num alarme
        // permanente: foi o que aconteceu no primeiro teste com a base real.
        if ($host === $base) return TRUE;

        $sufixo = substr($nome, 1);
        // Um nível só: *.foo.com vale para a.foo.com, não para a.b.foo.com.
        if (substr($host, -strlen($sufixo)) === $sufixo
            && substr_count($host, '.') === substr_count($nome, '.')) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Host de uma URL, minúsculo e sem porta.
   *
   * @param  string $url
   * @return string|null
   */
  private function hostDaUrl($url)
  {
    $host = parse_url((string) $url, PHP_URL_HOST);
    if (!is_string($host) || $host === '') return NULL;

    return mb_substr(mb_strtolower($host), 0, 255);
  }

  /**
   * Host limpo: minúsculo, sem esquema, caminho, porta ou ponto final.
   *
   * @param  string $host
   * @return string
   */
  private function normalizarHost($host)
  {
    $host = mb_strtolower(trim((string) $host));
    $host = preg_replace('#^[a-z]+://#', '', $host);
    $host = explode('/', $host)[0];
    $host = explode('?', $host)[0];
    $host = explode(':', $host)[0];

    return rtrim(trim($host), '.');
  }

  /**
   * Host em ASCII/punycode, para a consulta de DNS.
   *
   * A base tem (e volta a ter) domínio acentuado, e o resolvedor precisa do
   * punycode. Sem isso o domínio some do monitoramento em silêncio.
   *
   * @param  string $host
   * @return string
   */
  private function paraAscii($host)
  {
    $host = $this->normalizarHost($host);
    if ($host === '') return '';

    if (preg_match('/^[a-z0-9.\-]+$/', $host)) return $host;

    if (function_exists('idn_to_ascii')) {
      $convertido = @idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
      if (is_string($convertido) && $convertido !== '') return mb_strtolower($convertido);
    }

    return '';
  }

  /**
   * @param  bool       $sucesso
   * @param  string     $mensagem
   * @param  array|null $dados
   * @param  bool       $transitorio falha de infra nossa, não do site
   * @return array
   */
  private function resultado($sucesso, $mensagem, $dados, $transitorio)
  {
    return [
      'success' => (bool) $sucesso,
      'message' => (string) $mensagem,
      'data' => $dados,
      'transient' => (bool) $transitorio,
    ];
  }
}
