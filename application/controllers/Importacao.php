<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Importação da base do sistema anterior (gestor-interno).
 *
 * Comando de CLI, não rotina de cron: isto roda algumas vezes nos ensaios e
 * uma vez no cutover. Por isso não está no `Cron.php` e não tem registro em
 * `crm_cron_logs` — só as guardas que aquele arquivo já provou serem
 * necessárias (CLI-only e lock de arquivo).
 *
 * Estende `CI_Controller`, e não `MY_Controller`: aquele carrega sessão e
 * exige login, o que não existe em linha de comando.
 *
 * USO
 * ---
 *   php index.php importacao gestor <dump.sql> <pasta-arquivos> [opções]
 *
 * Opções:
 *   --dry-run           simula tudo e não grava nada (comece sempre por aqui)
 *   --id-company=1      tenant de destino (padrão 1)
 *   --id-user=3         quem assina os registros importados (padrão 3)
 *   --id-origem         grava o cliente com o MESMO id que ele tinha na origem
 *                       (só em INSERT, e só serve em base limpa — ver o model)
 *   --avisos=50         quantos avisos listar (padrão 30; `todos` para tudo)
 *
 * Exemplo:
 *   php index.php importacao gestor ~/dump.sql ~/arquivos-b2 --dry-run
 */
class Importacao extends CI_Controller
{
  /**
   * Usuário que assina o que for importado. O 3 é PROCESSOS AUTOMÁTICOS —
   * assim a coluna "criado por" das telas mostra a procedência sozinha, em vez
   * de a importação se passar por cadastro manual do suporte.
   */
  const ID_USER_PADRAO = 3;

  /**
   * Quantos avisos imprimir por padrão. A lista inteira em um cutover pode ter
   * dezenas de linhas; o total sempre aparece.
   */
  const AVISOS_PADRAO = 30;

  public function __construct()
  {
    parent::__construct();

    // Guarda antes de qualquer trabalho: pela web isto nunca deve rodar.
    if (!$this->input->is_cli_request()) {
      show_error('Este processo só pode ser executado via CLI.', 403);
    }
  }

  public function index()
  {
    $this->linha('Uso:');
    $this->linha('  php index.php importacao arquivos <dump.sql> > lista.json');
    $this->linha('  php index.php importacao gestor <dump.sql> <pasta-arquivos> [--dry-run] [--id-company=1] [--id-user=3]');
  }

  /**
   * Emite, em JSON, a lista dos documentos de contrato que precisam ser
   * baixados do Backblaze B2 — o insumo do `scripts/baixar-arquivos-b2.mjs`
   * do gestor-interno.
   *
   * Existe para que o tokenizador do dump viva num lugar só. O script de
   * download precisaria reimplementá-lo em JavaScript para achar os
   * `b2FileName`, e duas implementações do mesmo parser divergem com o tempo.
   *
   * Saída EXCLUSIVAMENTE JSON (é redirecionada para arquivo):
   *   [{ "id": 1, "b2FileName": "...", "arquivo": "1.pdf", "bytes": 12345 }, ...]
   *
   * @param string|null $caminhoDump
   */
  public function arquivos($caminhoDump = NULL)
  {
    $posicionais = $this->argumentosPosicionais();
    $caminhoDump = isset($posicionais[0]) ? $posicionais[0] : $caminhoDump;

    if (empty($caminhoDump)) {
      fwrite(STDERR, 'ERRO: informe o caminho do dump.' . PHP_EOL);
      exit(1);
    }

    $this->load->library('pgdump_parser');

    if (!$this->pgdump_parser->carregar($caminhoDump)) {
      fwrite(STDERR, 'ERRO: ' . $this->pgdump_parser->ultimoErro() . PHP_EOL);
      exit(1);
    }

    $lista = [];

    foreach ($this->pgdump_parser->tabela('clientesContratosDocumentos') as $documento) {
      $id = (int) $documento['id'];
      $extensao = mb_strtolower(trim((string) $documento['fileExtension'], '. '));

      if ($extensao === '') {
        $extensao = mb_strtolower(pathinfo((string) $documento['fileNameOriginal'], PATHINFO_EXTENSION));
      }

      $lista[] = [
        'id' => $id,
        'b2FileName' => (string) $documento['b2FileName'],
        // Nome de destino previsível: o nome original tem acento, espaço e
        // barra, e é o que o manifesto existe para não ter de adivinhar.
        'arquivo' => $id . ($extensao !== '' ? '.' . $extensao : ''),
        'nome_original' => (string) $documento['fileNameOriginal'],
        'bytes' => (int) $documento['fileSizeBytes'],
      ];
    }

    echo json_encode($lista, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
  }

  /**
   * @param string|null $caminhoDump
   * @param string|null $pastaArquivos
   */
  public function gestor($caminhoDump = NULL, $pastaArquivos = NULL)
  {
    $opcoes = $this->lerOpcoes();

    // Os argumentos posicionais chegam pelo roteador, mas as flags `--x` também
    // entram nele; separá-las evita que uma flag vire caminho de arquivo.
    $posicionais = $this->argumentosPosicionais();

    $caminhoDump = isset($posicionais[0]) ? $posicionais[0] : $caminhoDump;
    $pastaArquivos = isset($posicionais[1]) ? $posicionais[1] : $pastaArquivos;

    if (empty($caminhoDump)) {
      $this->linha('ERRO: informe o caminho do dump.');
      $this->index();
      return;
    }

    if (empty($pastaArquivos)) {
      $this->linha('ERRO: informe a pasta com os arquivos baixados do B2.');
      $this->index();
      return;
    }

    // As guardas de argumento vêm ANTES do lock, de propósito (mesma ordem do
    // Cron::executarWhois): erro de digitação não deve deixar lock para trás.
    @set_time_limit(0);

    $arquivoLock = APPPATH . 'cache/importacao_gestor.lock';
    $lock = @fopen($arquivoLock, 'c');

    if ($lock === FALSE || !flock($lock, LOCK_EX | LOCK_NB)) {
      if (is_resource($lock)) {
        fclose($lock);
      }

      $this->linha('Já existe uma importação em andamento.');
      return;
    }

    $this->load->model('import_gestor_model');

    $simulacao = !empty($opcoes['dry-run']);

    $this->linha('');
    $this->linha('=====================================================');
    $this->linha($simulacao ? ' SIMULAÇÃO (nada será gravado)' : ' IMPORTAÇÃO REAL');
    $this->linha('=====================================================');
    $this->linha(' dump     : ' . $caminhoDump);
    $this->linha(' arquivos : ' . $pastaArquivos);
    $this->linha(' tenant   : ' . (isset($opcoes['id-company']) ? (int) $opcoes['id-company'] : 1));
    $this->linha(' usuário  : ' . (isset($opcoes['id-user']) ? (int) $opcoes['id-user'] : self::ID_USER_PADRAO));
    $this->linha(' id do cliente: ' . (!empty($opcoes['id-origem']) ? 'o mesmo da origem' : 'AUTO_INCREMENT'));
    $this->linha('');

    $inicio = microtime(TRUE);

    try {
      $resultado = $this->import_gestor_model->importar($caminhoDump, $pastaArquivos, [
        'id_company' => isset($opcoes['id-company']) ? (int) $opcoes['id-company'] : 1,
        'id_user' => isset($opcoes['id-user']) ? (int) $opcoes['id-user'] : self::ID_USER_PADRAO,
        'simulacao' => $simulacao,
        'id_cliente_origem' => !empty($opcoes['id-origem']),
      ]);
    } catch (Throwable $e) {
      // Converte a exceção no mesmo shape de retorno, para o relatório final
      // ser único (mesmo tratamento do loop do cron_sync_servers).
      $resultado = [
        'success' => FALSE,
        'message' => 'Exceção: ' . $e->getMessage(),
        'data' => [],
      ];

      log_message('error', '[IMPORTACAO] ' . $e->getMessage());
    }

    $segundos = round(microtime(TRUE) - $inicio, 1);

    $this->imprimirResultado($resultado, $segundos, $opcoes);

    flock($lock, LOCK_UN);
    fclose($lock);
  }

  // ------------------------------------------------------------------
  // Saída
  // ------------------------------------------------------------------

  /**
   * @param array $resultado
   * @param float $segundos
   * @param array $opcoes
   */
  private function imprimirResultado(array $resultado, $segundos, array $opcoes)
  {
    if (empty($resultado['success']) && empty($resultado['data']['etapas'])) {
      $this->linha('ERRO: ' . $resultado['message']);
      $this->linha('');
      return;
    }

    $dados = $resultado['data'];

    $this->linha('Origem (linhas lidas do dump)');
    $this->linha('-----------------------------------------------------');

    foreach ($dados['contagens_origem'] as $tabela => $quantidade) {
      $this->linha(sprintf('  %-34s %6d', $tabela, $quantidade));
    }

    $this->linha('');
    $this->linha('Destino');
    $this->linha('-----------------------------------------------------');
    $this->linha(sprintf('  %-14s %7s %7s %7s %7s %7s', 'etapa', 'total', 'novos', 'atual.', 'ignor.', 'erros'));

    foreach ($dados['etapas'] as $nome => $etapa) {
      $this->linha(sprintf(
        '  %-14s %7d %7d %7d %7d %7d',
        $nome,
        $etapa['total'],
        $etapa['novos'],
        $etapa['atualizados'],
        $etapa['ignorados'],
        $etapa['erros']
      ));
    }

    // Mudança de estado de contrato ganha linha própria, e não some no meio de
    // "atualizados": é o efeito da importação que a operação de fato precisa
    // ver — o serviço nos painéis NÃO acompanha esta reescrita.
    $alterados = isset($dados['contratos_com_status_alterado']) ? (int) $dados['contratos_com_status_alterado'] : 0;

    if ($alterados > 0) {
      $this->linha('');
      $this->linha('  ATENÇÃO: ' . $alterados . ' contrato(s) tiveram o STATUS reescrito pelo dump.');
      $this->linha('  As contas dos painéis não foram alteradas. Detalhe na aba Históricos de cada contrato.');
    }

    $avisos = isset($dados['avisos']) ? $dados['avisos'] : [];

    if (!empty($avisos)) {
      $limite = $this->limiteDeAvisos($opcoes, count($avisos));

      $this->linha('');
      $this->linha('Avisos (' . count($avisos) . ')');
      $this->linha('-----------------------------------------------------');

      foreach (array_slice($avisos, 0, $limite) as $aviso) {
        $this->linha(sprintf('  [%s #%d] %s', $aviso['etapa'], $aviso['legacy_id'], $aviso['mensagem']));
      }

      if (count($avisos) > $limite) {
        $this->linha('  ... e mais ' . (count($avisos) - $limite) . '. Use --avisos=todos para ver a lista inteira.');
      }
    }

    $this->linha('');
    $this->linha('-----------------------------------------------------');
    $this->linha(' ' . $resultado['message'] . ' (' . $segundos . 's)');

    if (!empty($dados['simulacao'])) {
      $this->linha(' NADA FOI GRAVADO — rode sem --dry-run para valer.');
    }

    $this->linha('');
  }

  /**
   * @param  array $opcoes
   * @param  int   $total
   * @return int
   */
  private function limiteDeAvisos(array $opcoes, $total)
  {
    if (!isset($opcoes['avisos'])) {
      return self::AVISOS_PADRAO;
    }

    if ($opcoes['avisos'] === 'todos' || $opcoes['avisos'] === TRUE) {
      return $total;
    }

    $limite = (int) $opcoes['avisos'];

    return ($limite > 0) ? $limite : self::AVISOS_PADRAO;
  }

  /**
   * @param string $texto
   */
  private function linha($texto)
  {
    echo $texto . PHP_EOL;
  }

  // ------------------------------------------------------------------
  // Argumentos
  // ------------------------------------------------------------------

  /**
   * Flags no formato `--nome` ou `--nome=valor`.
   *
   * @return array
   */
  private function lerOpcoes()
  {
    $opcoes = [];

    foreach ($this->argumentos() as $argumento) {
      if (strpos($argumento, '--') !== 0) {
        continue;
      }

      $limpo = substr($argumento, 2);
      $posicao = strpos($limpo, '=');

      if ($posicao === FALSE) {
        $opcoes[$limpo] = TRUE;
        continue;
      }

      $opcoes[substr($limpo, 0, $posicao)] = substr($limpo, $posicao + 1);
    }

    return $opcoes;
  }

  /**
   * Argumentos que não são flags, na ordem — dump e pasta.
   *
   * @return array
   */
  private function argumentosPosicionais()
  {
    $posicionais = [];

    foreach ($this->argumentos() as $argumento) {
      if (strpos($argumento, '--') !== 0) {
        $posicionais[] = $argumento;
      }
    }

    return $posicionais;
  }

  /**
   * `$argv` sem o script, o controller e o método.
   *
   * @return array
   */
  private function argumentos()
  {
    if (!isset($_SERVER['argv']) || !is_array($_SERVER['argv'])) {
      return [];
    }

    return array_slice($_SERVER['argv'], 3);
  }
}
