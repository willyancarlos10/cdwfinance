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
 * - A leitura é ABORTADA em MAX_BYTES. O abort produz `CURLE_WRITE_ERROR` (23),
 *   que é NOSSO e precisa contar como sucesso — sem essa exceção, todo site do
 *   mundo reportaria falha.
 *
 *   Até o marcador `erro_pagina` existir, a leitura também parava no `</head>`:
 *   só o `<title>` interessava, e baixar a home inteira de centenas de sites
 *   seria desperdício. Erro de PHP mora no CORPO — medido na base, o
 *   `A PHP Error was encountered` de dois sites aparecia nos bytes 35.132 e
 *   42.344, com o `</head>` em 6.282 e 9.411 —, então parar no head era o mesmo
 *   que não procurar. Custo medido da troca: de ~22 KB para ~92 KB por site
 *   (4,1x) e, no enquadramento desfavorável do A/B, +0,33 s por site — cerca de
 *   2,5 min numa rodada de 454 domínios, contra orçamento de 1.800 s.
 *
 * - NÃO pedimos `gzip` (nada de CURLOPT_ENCODING). Com o corpo comprimido, um
 *   buffer truncado é um stream incompleto que não infla: o título sairia vazio
 *   e viraria "título alterado" todo santo dia.
 *
 * - `SSL_VERIFYPEER` desligado. Certificado NÃO é mais avaliado aqui (ver o
 *   CLAUDE.md), mas a verificação continua desligada de propósito: com ela
 *   ligada, um site de certificado vencido falha por INTEIRO, e perderíamos o
 *   título e o marcador justamente no site que está com problema — o certificado
 *   ruim viraria um apagão em vez de deixar o resto ser medido.
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

  /**
   * Teto de leitura do corpo.
   *
   * 256 KB, e não os 64 KB de quando só o título importava: o `erro_pagina`
   * procura no corpo inteiro. Na base real 12% das home passam disso, e nelas um
   * erro no rodapé escapa — é o preço de não multiplicar por oito o tráfego da
   * rodada por causa da cauda.
   */
  const MAX_BYTES = 262144;

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

  /** Teto do trecho de erro gravado no evento (a coluna `detail` é varchar 500). */
  const MAX_TRECHO_ERRO = 300;

  /**
   * Erro de servidor EXIBIDO na página, procurado no corpo inteiro.
   *
   * Diferente das `MARCADORES`, que se ancoram no título: aqui o sintoma é a
   * página estar servindo, com HTTP 200 e título perfeitamente normal, a saída
   * de um erro. Foi medido na base — seis sites em 395, com o backtrace do
   * CodeIgniter expondo inclusive o caminho absoluto no servidor.
   *
   * A REGRA que dá precisão a tudo isto: exigir a assinatura COMPLETA, jamais a
   * palavra isolada. `Warning:` sozinho casaria com página de qualquer assunto;
   * `Warning: … on line 231` é o formato com que o PHP imprime. Foi a lição do
   * SSL — pattern que "quase" identifica gera alarme que ninguém mais lê.
   *
   * O que NÃO está aqui, e não por esquecimento: erro de JAVASCRIPT. Ele é de
   * runtime, acontece no navegador, e o cURL não executa script nenhum — um
   * `Uncaught TypeError` simplesmente não existe no HTML que chega até nós.
   * Detectá-lo exigiria navegador headless (Chrome/Puppeteer), que é outra
   * infraestrutura. O que já cobre parte do sintoma é o `sem_titulo`: página que
   * quebrou a ponto de renderizar vazia cai lá.
   */
  const ERROS_SERVIDOR = [
    // PHP cru. O `on line N` é obrigatório, e é ele que separa erro de texto.
    'php' => '/(?:Fatal error|Parse error|Warning|Notice|Deprecated|Recoverable fatal error)\s*(?:<\/b>)?\s*:.{0,400}?\bon line\b\s*(?:<b>)?\s*\d+/isu',
    'php_uncaught' => '/Uncaught\s+(?:Error|Exception|TypeError|ValueError|ArgumentCountError|DivisionByZeroError)\b.{0,200}/isu',
    'php_stack' => '/Stack trace:\s*(?:<[^>]+>\s*)*#0\s.{0,200}/isu',
    // CodeIgniter — o caso mais comum desta base.
    'codeigniter' => '/(?:A PHP Error was encountered|An Error Was Encountered|An uncaught Exception was encountered).{0,300}/isu',
    'laravel' => '/(?:Whoops, looks like something went wrong|Illuminate\\\\[A-Za-z]+\\\\[A-Za-z\\\\]{0,80}).{0,120}/su',
    'symfony' => '/Symfony\\\\Component\\\\[A-Za-z]+\\\\[A-Za-z\\\\]{0,80}Exception.{0,120}/su',
    'wordpress' => '/(?:There has been a critical error on (?:this|your) website|Houve um erro cr[íi]tico (?:neste|no seu) site).{0,120}/isu',
    'banco' => '/(?:Error establishing a database connection|Erro ao estabelecer (?:uma )?(?:liga[çc][ãa]o|conex[ãa]o).{0,40}(?:base|banco) de dados|You have an error in your SQL syntax|MySQL server has gone away|Access denied for user \'|SQLSTATE\[[A-Z0-9]+\]|Table \'[^\']{1,120}\' doesn\'t exist).{0,200}/isu',
    'dotnet' => '/(?:Server Error in \'[^\']{0,80}\' Application|System\.(?:Web|Data)\.[A-Za-z]{0,40}Exception).{0,120}/su',
    // PHP não executado: o servidor está entregando o CÓDIGO-FONTE. É o mais
    // grave da lista — vaza credencial de banco e chave de API para quem abrir
    // o site. Ancorado no COMEÇO da resposta, senão casaria com tutorial.
    'fonte_php' => '/\A\s*<\?php\s.{0,200}/su',
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
   * Abre a home e devolve status, título e marcador.
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
   * passageira no apex, numa única rodada, para um domínio ficar preso no
   * `www.` e continuar sendo medido ali indefinidamente, enquanto o endereço
   * canônico respondia perfeitamente. Um retrato ruim não pode se
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
      // ruim não apagar a medição do site inteiro.
      CURLOPT_SSL_VERIFYPEER => FALSE,
      CURLOPT_SSL_VERIFYHOST => 0,
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
        if (strlen($buffer) >= self::MAX_BYTES) return -1;
        return strlen($pedaco);
      },
    ]);

    curl_exec($ch);

    $erro = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $urlFinal = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $erroTexto = curl_error($ch);
    curl_close($ch);

    // CURLE_WRITE_ERROR (23) é o nosso próprio abort da leitura.
    $abortadoPorNos = ($erro === CURLE_WRITE_ERROR);
    if ($erro !== 0 && !$abortadoPorNos) {
      return $this->falhaTransporte($erro, $erroTexto, $urlFinal);
    }

    // A conversão de charset acontece UMA VEZ, aqui, e o documento convertido é
    // o que title, `<h1>` e varredura de erro enxergam. Antes ela vivia dentro
    // do extrairTitulo() e o detectarMarcador() recebia o buffer CRU — com o
    // modificador `u` das expressões, isso significa `preg_match` devolvendo
    // FALSE em silêncio em toda página latin-1, e a âncora do `<h1>` nunca
    // disparando ali. O erro de servidor não pode depender do charset do site.
    $documento = $this->paraUtf8($buffer, $contentType);
    $titulo = ($documento === NULL) ? NULL : $this->extrairTitulo($documento);
    $marcador = $this->detectarMarcador($titulo, $documento, $buffer);

    $dados = [
      'http_status' => $status,
      'http_result' => $this->classificarStatus($status),
      'http_final_url' => mb_substr($urlFinal, 0, 500),
      'check_host' => $this->hostDaUrl($urlFinal),
      'title' => $titulo,
      'flag' => $marcador['flag'],
      'flag_detail' => $marcador['detail'],
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
      'flag_detail' => NULL,
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
   * @param  string $html documento JÁ convertido para UTF-8
   * @return string|null NULL = não foi possível medir (o model preserva o anterior)
   */
  private function extrairTitulo($html)
  {
    if ($html === '') return NULL;

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
   * Marcador de problema da home, com o trecho que o justifica.
   *
   * Três origens, NESTA ordem, e a ordem é a decisão:
   *
   *  1. Os quatro marcadores ancorados no `<title>`/`<h1>`. Vêm primeiro porque
   *     descrevem um ESTADO conhecido e preciso da página ("conta suspensa",
   *     "listagem de diretório") — ordem preservada exatamente como era, para a
   *     chegada do `erro_pagina` não reclassificar nada que já funcionava.
   *
   *  2. `erro_pagina`: erro de servidor no CORPO. A varredura roda mesmo com
   *     título normal — é o caso que motivou o marcador: HTTP 200, título certo,
   *     e um `A PHP Error was encountered` no meio da página.
   *
   *  3. `sem_titulo`, o último. E precisa ser o último: a página que é SÓ um
   *     fatal error não tem `<title>` nem `<h1>`, então com o antigo retorno
   *     antecipado ela seria classificada como "sem título" e o erro — que é a
   *     informação útil — nunca apareceria.
   *
   * @param  string|null $titulo
   * @param  string|null $documento documento em UTF-8; NULL = charset ilegível
   * @param  string      $bruto     buffer cru, usado só quando não houve conversão
   * @return array ['flag' => string|null, 'detail' => string|null]
   */
  private function detectarMarcador($titulo, $documento, $bruto)
  {
    // Sem conversão, o `u` das expressões faria todo preg_match devolver FALSE.
    // O buffer cru só serve de último recurso, e é o que já valia antes.
    $html = ($documento === NULL) ? (string) $bruto : $documento;

    $alvos = [];
    if ($titulo !== NULL && $titulo !== '') $alvos[] = $titulo;

    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/isu', $html, $captura)) {
      $h1 = $this->limparTexto($captura[1]);
      if ($h1 !== NULL) $alvos[] = $h1;
    }

    foreach (self::MARCADORES as $flag => $padrao) {
      foreach ($alvos as $alvo) {
        if (preg_match($padrao, $alvo)) return ['flag' => $flag, 'detail' => NULL];
      }
    }

    // A varredura de erro só roda no documento CONVERTIDO: no buffer cru o `u`
    // das expressões devolveria FALSE em silêncio, e um "não achei" indistinguível
    // de "não procurei" é justamente o defeito que derrubou a checagem de SSL.
    if ($documento !== NULL) {
      $erro = $this->detectarErroServidor($documento);
      if ($erro !== NULL) return ['flag' => 'erro_pagina', 'detail' => $erro];
    }

    if (empty($alvos)) return ['flag' => 'sem_titulo', 'detail' => NULL];

    return ['flag' => NULL, 'detail' => NULL];
  }

  /**
   * Erro de servidor sendo EXIBIDO na página, e o trecho que prova.
   *
   * A precisão vem de exigir a assinatura inteira, nunca a palavra solta:
   * `Warning:` aparece em página de qualquer assunto, mas `Warning: … on line
   * 231` é o formato com que o PHP imprime, e não texto de site. Verificado
   * contra 395 sites da base: 6 achados, nenhum falso positivo.
   *
   * O trecho volta junto porque "a home tem um erro" e "a home tem
   * `Undefined variable: partnerList` em views/home.php:231" pedem ações
   * diferentes — e quem lê o resumo por e-mail não tem o site aberto.
   *
   * @param  string $html documento em UTF-8
   * @return string|null
   */
  private function detectarErroServidor($html)
  {
    foreach (self::ERROS_SERVIDOR as $padrao) {
      if (preg_match($padrao, $html, $captura)) {
        $trecho = $this->limparTexto($captura[0]);
        if ($trecho === NULL) continue;

        return mb_substr($trecho, 0, self::MAX_TRECHO_ERRO);
      }
    }

    return NULL;
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
