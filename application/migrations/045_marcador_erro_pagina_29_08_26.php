<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Sexto marcador do monitoramento: `erro_pagina`.
 *
 * O caso que ele resolve não era coberto por nenhum dos cinco anteriores: a home
 * responde **HTTP 200**, com o título perfeitamente normal, e está exibindo a
 * saída de um erro de servidor no meio do conteúdo. Para o monitoramento, aquilo
 * era um site saudável.
 *
 * Medido na base antes de escrever: **seis sites em 395** estão assim agora — dois
 * com o `A PHP Error was encountered` do CodeIgniter (com backtrace expondo o
 * caminho absoluto no servidor, `/home/<conta>/public_html/...`) e três do mesmo
 * cliente com um `Warning: opendir(...): Permission denied` do WordPress.
 *
 * ESTA MIGRATION SÓ MEXE NO COMMENT. Não há coluna nova: `flag` é varchar(30) e
 * `erro_pagina` cabe. O que muda de verdade está no código — e o registro aqui
 * existe porque o COMMENT antigo dizia "Marcador de problema no titulo", e essa
 * frase deixou de ser verdade: este marcador procura no CORPO. Mesmo cuidado que
 * a 041 teve com os tipos de servidor e a 044 com os tipos de evento.
 *
 * A MUDANÇA DE VERDADE, para quem vier depois: a leitura da home parou de ser
 * abortada no `</head>` e agora vai até 256 KB. Erro de PHP mora no corpo — nos
 * dois sites do CodeIgniter ele aparecia nos bytes 35.132 e 42.344, com o
 * `</head>` em 6.282 e 9.411 —, então parar no head era o mesmo que não procurar.
 * Custo medido: de ~22 KB para ~92 KB por site (4,1x) e, no enquadramento
 * desfavorável do A/B, +0,33 s por site: ~2,5 min numa rodada de 454 domínios,
 * contra orçamento de 1.800 s.
 *
 * Efeito colateral verificado antes de subir: a âncora do `<h1>` do
 * `detectarMarcador()` era **código morto** — o buffer acabava antes do body —, e
 * passa a valer. Medido em 308 sites, 109 com `<h1>` legível: **zero** marcadores
 * novos. Não há enxurrada de eventos na primeira rodada.
 */
class Migration_Marcador_erro_pagina_29_08_26 extends CI_Migration
{
  public function up()
  {
    $this->comentarFlag(TRUE);
  }

  public function down()
  {
    $this->comentarFlag(FALSE);
  }

  /**
   * @param  bool $comErro TRUE inclui o marcador novo no COMMENT
   * @return void
   */
  private function comentarFlag($comErro)
  {
    $marcadores = 'suspenso | index_of | padrao_servidor | parking | '
      . ($comErro ? 'erro_pagina | ' : '') . 'sem_titulo';

    $texto = ($comErro ? 'Marcador de problema na home' : 'Marcador de problema no titulo') . ': ' . $marcadores;

    $this->db->query(
      "ALTER TABLE `crm_domains_monitor`
        MODIFY `flag` varchar(30) DEFAULT NULL COMMENT " . $this->db->escape($texto)
    );
  }
}
