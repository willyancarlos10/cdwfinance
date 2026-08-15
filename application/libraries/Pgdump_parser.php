<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Leitor de dump PostgreSQL — entende os DOIS formatos que aparecem aqui.
 *
 * Existe para uma coisa só: a importação da base do gestor-interno. O PHP 7.4
 * do MAMP não tem a extensão `pgsql`, então não há como consultar o Postgres
 * de origem — o caminho é ler o dump.
 *
 * OS DOIS FORMATOS
 * ----------------
 *  - **COPY** (`pg_dump` nativo): `COPY public.clientes (...) FROM stdin;`
 *    seguido de linhas separadas por TAB e encerradas por `\.`. É o formato
 *    do dump de produção.
 *  - **INSERT** (TablePlus e afins): `INSERT INTO "public"."clientes" (...)
 *    VALUES (...), (...);`
 *
 * O formato é detectado pelo conteúdo, não pelo nome do arquivo. Os dois
 * continuam suportados de propósito: os dumps chegam de ferramentas
 * diferentes conforme quem exporta, e descobrir isso na hora do cutover seria
 * tarde.
 *
 * No COPY só o schema `public` é lido — o banco é Neon e traz junto um schema
 * `neon_auth` (sessões, contas, tokens) que não tem nada a ver com a migração.
 *
 * POR QUE UM TOKENIZADOR E NÃO REGEX/EXPLODE POR LINHA
 * ----------------------------------------------------
 * O jeito óbvio (uma tupla por linha, `explode(',')` nos valores) devolve
 * resultado ERRADO e silenciosamente: campos de texto do dump têm quebra de
 * linha no meio (as `observacao` dos domínios de contrato são o caso), e têm
 * vírgulas e parênteses dentro das aspas. Contando por linha, a tabela
 * `clientesContratosDominios` devolve 156 registros; o valor certo é 393.
 * Um importador que engula 156 de 393 domínios sem reclamar é pior do que um
 * que falhe. Por isso a leitura é caractere a caractere, controlando estado de
 * aspas.
 *
 * O QUE O DUMP USA (verificado no arquivo, não presumido)
 * ------------------------------------------------------
 *  - `'texto'`, com `''` como escape de aspas simples;
 *  - `NULL` sem aspas;
 *  - números sem aspas;
 *  - booleanos como `'t'` / `'f'` (entre aspas, então chegam como string);
 *  - JSON (`jsonb`) como string entre aspas.
 *
 * NÃO há escape string do Postgres (`E'...'`) nem uma única barra invertida no
 * arquivo — o que casa com o fato de o dump não precisar delas. Se um dump
 * futuro passar a trazer `E'...'`, este parser lê o `E` como valor sem aspas e
 * a linha sai errada; por isso `carregar()` recusa o arquivo nesse caso em vez
 * de seguir adiante.
 *
 * Distinguir valor COM aspas de valor SEM aspas importa: sem isso, um campo de
 * texto cujo conteúdo fosse a palavra `NULL` viraria `null` de verdade.
 */
class Pgdump_parser
{
  /**
   * Tabela => lista de linhas (arrays associativos coluna => valor).
   *
   * @var array
   */
  private $tabelas = [];

  /**
   * Motivo da última falha de `carregar()`.
   *
   * @var string
   */
  private $erro = '';

  /**
   * Lê o dump inteiro e indexa as linhas por tabela.
   *
   * Um mesmo nome de tabela pode aparecer em vários blocos INSERT (o TablePlus
   * quebra tabelas grandes); os blocos são concatenados.
   *
   * @param string $caminho
   * @return bool
   */
  public function carregar($caminho)
  {
    $this->tabelas = [];
    $this->erro = '';

    if (!is_file($caminho) || !is_readable($caminho)) {
      $this->erro = 'Arquivo de dump não encontrado ou sem permissão de leitura: ' . $caminho;
      return FALSE;
    }

    $conteudo = file_get_contents($caminho);

    if ($conteudo === FALSE || $conteudo === '') {
      $this->erro = 'Não foi possível ler o dump (arquivo vazio?): ' . $caminho;
      return FALSE;
    }

    // Escape string do Postgres: o modo INSERT não trata as sequências com
    // barra invertida que o `E'...'` habilita. Melhor recusar do que importar
    // texto corrompido. (No modo COPY o escape é outro e é tratado.)
    $temCopy = (bool) preg_match('/^COPY\s+public\./m', $conteudo);

    if (!$temCopy && preg_match('/[(,]\s*E\'/', $conteudo)) {
      $this->erro = 'O dump usa escape string do Postgres (E\'...\'), que este parser não trata.';
      return FALSE;
    }

    $lidos = $temCopy ? $this->lerBlocosCopy($conteudo) : $this->lerBlocosInsert($conteudo);

    if ($lidos === 0) {
      $this->erro = $temCopy
        ? 'Nenhum bloco COPY do schema public encontrado no dump.'
        : 'Nenhum INSERT INTO "public"."..." encontrado no dump.';

      return FALSE;
    }

    return TRUE;
  }

  /**
   * Formato `pg_dump` nativo:
   *
   *   COPY public."clientesContatos" (id, "clienteId", nome) FROM stdin;
   *   1\t259\tFulano
   *   \.
   *
   * Aqui cada registro é exatamente UMA linha — quebras de linha dentro do
   * texto vêm escapadas como `\n`. É o oposto do formato INSERT, onde um
   * campo pode atravessar várias linhas do arquivo.
   *
   * @param  string $conteudo
   * @return int    quantidade de blocos lidos
   */
  private function lerBlocosCopy($conteudo)
  {
    $encontrados = preg_match_all(
      '/^COPY\s+public\.(?:"([^"]+)"|([A-Za-z0-9_]+))\s*\(([^)]*)\)\s+FROM\s+stdin;\r?$/m',
      $conteudo,
      $blocos,
      PREG_OFFSET_CAPTURE
    );

    if (empty($encontrados)) {
      return 0;
    }

    foreach ($blocos[0] as $indice => $cabecalho) {
      // O nome vem no grupo 1 (com aspas) ou no 2 (sem) — `pg_dump` só cita o
      // que precisa, então `clientes` vem cru e `clientesContatos` vem citado.
      $tabela = ($blocos[1][$indice][0] !== '') ? $blocos[1][$indice][0] : $blocos[2][$indice][0];
      $colunas = $this->lerColunas($blocos[3][$indice][0]);

      $inicio = $cabecalho[1] + strlen($cabecalho[0]);
      $fim = strpos($conteudo, "\n\\.", $inicio);

      if ($fim === FALSE) {
        // Bloco sem terminador: dump truncado. Não inventa dado.
        continue;
      }

      $corpo = substr($conteudo, $inicio, $fim - $inicio);

      if (!isset($this->tabelas[$tabela])) {
        $this->tabelas[$tabela] = [];
      }

      foreach (explode("\n", $corpo) as $linhaBruta) {
        if ($linhaBruta === '' || $linhaBruta === "\r") {
          continue;
        }

        $valores = explode("\t", rtrim($linhaBruta, "\r"));
        $linha = [];

        foreach ($colunas as $posicao => $coluna) {
          $linha[$coluna] = array_key_exists($posicao, $valores)
            ? $this->decodificarCopy($valores[$posicao])
            : NULL;
        }

        $this->tabelas[$tabela][] = $linha;
      }
    }

    return count($blocos[0]);
  }

  /**
   * Formato de ferramenta gráfica (TablePlus): `INSERT INTO "public"."x" ...`
   *
   * @param  string $conteudo
   * @return int    quantidade de blocos lidos
   */
  private function lerBlocosInsert($conteudo)
  {
    $encontrados = preg_match_all(
      '/INSERT INTO "public"\."([^"]+)" \(([^)]*)\) VALUES/',
      $conteudo,
      $blocos,
      PREG_OFFSET_CAPTURE
    );

    if (empty($encontrados)) {
      return 0;
    }

    foreach ($blocos[0] as $indice => $cabecalho) {
      $tabela = $blocos[1][$indice][0];
      $colunas = $this->lerColunas($blocos[2][$indice][0]);
      $posicao = $cabecalho[1] + strlen($cabecalho[0]);

      $linhas = $this->lerTuplas($conteudo, $posicao, $colunas);

      if (!isset($this->tabelas[$tabela])) {
        $this->tabelas[$tabela] = [];
      }

      foreach ($linhas as $linha) {
        $this->tabelas[$tabela][] = $linha;
      }
    }

    return count($blocos[0]);
  }

  /**
   * Decodifica um valor do formato texto do COPY.
   *
   * `\N` é o marcador de NULL do Postgres — e é por isso que ele NÃO pode ser
   * tratado junto com os outros escapes: `\N` significa "sem valor", enquanto
   * `\n` (minúsculo) é uma quebra de linha dentro do texto. Confundir os dois
   * transformaria campo vazio em texto e vice-versa.
   *
   * @param  string $valor
   * @return string|null
   */
  private function decodificarCopy($valor)
  {
    if ($valor === '\\N') {
      return NULL;
    }

    if (strpos($valor, '\\') === FALSE) {
      return $valor;
    }

    $saida = '';
    $tamanho = strlen($valor);

    for ($i = 0; $i < $tamanho; $i++) {
      if ($valor[$i] !== '\\' || $i + 1 >= $tamanho) {
        $saida .= $valor[$i];
        continue;
      }

      $proximo = $valor[++$i];

      switch ($proximo) {
        case 'n': $saida .= "\n"; break;
        case 't': $saida .= "\t"; break;
        case 'r': $saida .= "\r"; break;
        case 'b': $saida .= "\x08"; break;
        case 'f': $saida .= "\x0C"; break;
        case 'v': $saida .= "\x0B"; break;
        case '\\': $saida .= '\\'; break;
        default: $saida .= $proximo; break;
      }
    }

    return $saida;
  }

  /**
   * Linhas de uma tabela. Tabela ausente do dump devolve array vazio — é o
   * caso legítimo das tabelas que existem na origem mas estão sem dados.
   *
   * @param string $nome
   * @return array
   */
  public function tabela($nome)
  {
    return isset($this->tabelas[$nome]) ? $this->tabelas[$nome] : [];
  }

  /**
   * Quantas linhas cada tabela trouxe — o número que se confere contra o
   * gabarito antes de deixar a importação escrever qualquer coisa.
   *
   * @return array
   */
  public function contagens()
  {
    $contagens = [];

    foreach ($this->tabelas as $nome => $linhas) {
      $contagens[$nome] = count($linhas);
    }

    return $contagens;
  }

  /**
   * @return string
   */
  public function ultimoErro()
  {
    return $this->erro;
  }

  /**
   * `"id", "documento", "tipoPessoa"` => ['id', 'documento', 'tipoPessoa'].
   *
   * @param string $lista
   * @return array
   */
  private function lerColunas($lista)
  {
    $colunas = [];

    foreach (explode(',', $lista) as $coluna) {
      $colunas[] = trim(trim($coluna), '"');
    }

    return $colunas;
  }

  /**
   * Lê as tuplas `(...), (...);` a partir de `$posicao`.
   *
   * @param string $conteudo
   * @param int    $posicao   Deslocamento logo após o `VALUES`.
   * @param array  $colunas
   * @return array
   */
  private function lerTuplas($conteudo, $posicao, array $colunas)
  {
    $linhas = [];
    $tamanho = strlen($conteudo);

    while ($posicao < $tamanho) {
      // Espaços, quebras de linha e a vírgula entre tuplas.
      while ($posicao < $tamanho && (ctype_space($conteudo[$posicao]) || $conteudo[$posicao] === ',')) {
        $posicao++;
      }

      // `;` fecha o INSERT; qualquer outra coisa que não abra tupla também
      // encerra o bloco (é o começo do próximo comando do dump).
      if ($posicao >= $tamanho || $conteudo[$posicao] !== '(') {
        break;
      }

      $posicao++;
      $valores = $this->lerValores($conteudo, $posicao);

      $linha = [];

      foreach ($colunas as $indice => $coluna) {
        $linha[$coluna] = array_key_exists($indice, $valores) ? $valores[$indice] : NULL;
      }

      $linhas[] = $linha;
    }

    return $linhas;
  }

  /**
   * Lê os valores de UMA tupla, consumindo até o `)` que a fecha.
   *
   * `$posicao` é passado por referência: o chamador precisa saber onde a tupla
   * terminou para continuar dali.
   *
   * @param string $conteudo
   * @param int    $posicao
   * @return array
   */
  private function lerValores($conteudo, &$posicao)
  {
    $valores = [];
    $tamanho = strlen($conteudo);
    $buffer = '';
    $emAspas = FALSE;
    $teveAspas = FALSE;

    while ($posicao < $tamanho) {
      $caractere = $conteudo[$posicao];

      if ($emAspas) {
        // `''` dentro de string é uma aspa simples literal, não o fim dela.
        if ($caractere === "'" && $posicao + 1 < $tamanho && $conteudo[$posicao + 1] === "'") {
          $buffer .= "'";
          $posicao += 2;
          continue;
        }

        if ($caractere === "'") {
          $emAspas = FALSE;
          $posicao++;
          continue;
        }

        $buffer .= $caractere;
        $posicao++;
        continue;
      }

      // Espaço FORA das aspas é separador do SQL, nunca conteúdo: o dump
      // escreve `, 'valor'`, e sem esta guarda o espaço depois da vírgula
      // entraria no valor (a UF viria como " PR" e o booleano como " f",
      // quebrando toda comparação exata depois).
      if (ctype_space($caractere)) {
        $posicao++;
        continue;
      }

      if ($caractere === "'") {
        $emAspas = TRUE;
        $teveAspas = TRUE;
        $posicao++;
        continue;
      }

      if ($caractere === ',') {
        $valores[] = $this->converter($buffer, $teveAspas);
        $buffer = '';
        $teveAspas = FALSE;
        $posicao++;
        continue;
      }

      if ($caractere === ')') {
        $valores[] = $this->converter($buffer, $teveAspas);
        $posicao++;
        return $valores;
      }

      $buffer .= $caractere;
      $posicao++;
    }

    // Chegou ao fim do arquivo sem fechar a tupla — dump truncado.
    $valores[] = $this->converter($buffer, $teveAspas);

    return $valores;
  }

  /**
   * Valor entre aspas é sempre string (inclusive `'NULL'`, `'t'` e o JSON).
   * Sem aspas, só `NULL` tem tratamento especial; número fica como string e
   * quem consome faz o cast que precisa.
   *
   * @param string $bruto
   * @param bool   $entreAspas
   * @return string|null
   */
  private function converter($bruto, $entreAspas)
  {
    if ($entreAspas) {
      return $bruto;
    }

    $limpo = trim($bruto);

    if ($limpo === '' || strcasecmp($limpo, 'NULL') === 0) {
      return NULL;
    }

    return $limpo;
  }
}
