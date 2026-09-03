<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * O Inter passa a usar sempre a CONTA PADRÃO: sai o `conta_corrente`.
 *
 * O campo existia porque o `x-conta-corrente` do Inter permite escolher a
 * conta quando o mesmo `client_id` atende mais de uma. Nesta operação esse
 * caso não existe — há uma conta por integração —, então o header só
 * acrescentava um cadastro a mais para dizer ao banco o que ele já faria
 * sozinho.
 *
 * O campo saiu da tela, o model parou de gravá-lo e a library parou de mandar
 * o header. **Esta migration existe para o dado já gravado não sobreviver ao
 * código que o lia**: sem ela, `crm_psp_accounts.extra` continuaria com
 * `{"conta_corrente":"..."}` numa linha que ninguém mais lê — e quem abrisse o
 * banco depois concluiria, com razão, que o header está sendo enviado. É a
 * mesma regra que fez a 029 derrubar o `issue_boleto` em vez de deixá-lo como
 * constante: configuração inerte mente.
 *
 * **Não há mudança de schema.** A coluna `extra` fica: ela é o espaço de
 * configuração específica de provedor, e o próximo PSP pode precisar dela.
 * Hoje nenhum provedor a usa, e por isso ela volta a `NULL` — que é a
 * diferença entre "não configurado" e "configurado com nada".
 *
 * A limpeza é feita em PHP, e não com `JSON_REMOVE`: a coluna é `longtext` e
 * não `json`, então o suporte a funções JSON depende da versão do servidor —
 * decodificar aqui funciona igual no MariaDB local e no MySQL de produção.
 *
 * Idempotente: roda sobre as linhas que ainda citam a chave, e não faz nada
 * quando não há nenhuma.
 *
 * @see docs/PSP-BANCO-INTER-VIABILIDADE.md — o header `x-conta-corrente`
 */
class Migration_Psp_sem_conta_corrente_30_08_26 extends CI_Migration
{
  public function up()
  {
    $linhas = $this->db->query(
      "SELECT `id`, `extra` FROM `crm_psp_accounts` WHERE `extra` LIKE '%conta_corrente%'"
    );

    if ($linhas === FALSE) return;

    foreach ($linhas->result() as $linha) {
      $extra = json_decode((string) $linha->extra, TRUE);

      // JSON corrompido não é motivo para apagar o resto: só a chave sai, e o
      // que não pôde ser lido fica como está para alguém olhar.
      if (!is_array($extra)) continue;

      unset($extra['conta_corrente']);

      $this->db->query(
        'UPDATE `crm_psp_accounts` SET `extra` = ? WHERE `id` = ?',
        [empty($extra) ? NULL : json_encode($extra, JSON_UNESCAPED_UNICODE), (int) $linha->id]
      );
    }
  }

  /**
   * Sem volta: o valor não é reconstituível, e o código que o lia não existe
   * mais. Reverter a migration não pode inventar a conta corrente de ninguém.
   */
  public function down()
  {
  }
}
