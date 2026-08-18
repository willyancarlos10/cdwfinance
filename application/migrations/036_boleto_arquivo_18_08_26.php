<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * `crm_invoices_boletos`: o PDF do boleto guardado no banco.
 *
 * O Banco Inter **não publica URL do boleto** — o PDF sai de endpoint
 * autenticado, em base64 dentro de um JSON. Então ou se guarda o arquivo, ou
 * cada abertura de tela vira uma chamada à API, contra um rate limit que
 * estoura em ~6 seguidas.
 *
 * POR QUE NO BANCO, e não em disco:
 *  - o alternativo natural seria `images/`, que o Apache serve e que **não tem
 *    `.htaccess`** — um boleto ali fica acessível por URL, e o PDF traz nome,
 *    documento, endereço e valor do cliente. Nome difícil de adivinhar não
 *    protege: basta o link vazar de um e-mail encaminhado;
 *  - guardar fora do webroot resolveria o vazamento, mas espalha o estado por
 *    dois lugares (linha + arquivo), que saem de sincronia em restore de dump,
 *    troca de servidor e rollback;
 *  - no banco, o backup leva o boleto junto e o acesso passa obrigatoriamente
 *    pelo controller, que confere o tenant.
 *
 * POR QUE TABELA À PARTE, e não uma coluna em `crm_invoices`: a fatura é lida
 * em listagem, contagem, paginação e pela `crm_invoices_v` — um LONGTEXT de
 * ~130 KB por linha ali seria carregado por consultas que nunca querem o PDF.
 * Aqui ele só é tocado por quem pede o boleto.
 *
 * `content` guarda **base64**, e não binário: é texto, atravessa driver,
 * charset e dump sem tratamento especial (este banco é `utf8` de 3 bytes e já
 * rejeitou emoji em silêncio na 028 — binário cru é a mesma classe de
 * problema). O preço é ~33% de espaço: um boleto de ~90 KB ocupa ~120 KB, e
 * 400 contratos mensais dariam da ordem de 600 MB por ano. Se incomodar, o
 * caminho é expurgar boleto de fatura paga há mais de N meses — o PDF é
 * reconstituível pela API enquanto a cobrança existir lá.
 *
 * UNIQUE em `id_invoice`: um boleto por fatura, e é a UNIQUE — não a checagem
 * em PHP — que garante isso entre duas requisições concorrentes (mesma regra
 * da `crm_invoices`).
 *
 * `psp_charge_id` é gravado junto para o cache saber se **envelheceu**: trocar
 * o provedor da fatura emite outra cobrança, e servir o PDF antigo entregaria
 * ao cliente um boleto cancelado. Comparar o id é a defesa em profundidade;
 * quem apaga de fato é o cancelamento.
 *
 * CASCADE a partir da fatura: o PDF não é registro financeiro próprio, é
 * retrato de uma cobrança. Sem a fatura, não descreve nada.
 *
 * @see docs/PLANO-PSP-COBRANCA.md
 */
class Migration_Boleto_arquivo_18_08_26 extends CI_Migration
{
  public function up()
  {
    if (!$this->db->table_exists('crm_invoices_boletos')) {
      $this->db->query($this->tabela());
    }
  }

  public function down()
  {
    $this->dbforge->drop_table('crm_invoices_boletos', TRUE);
  }

  /**
   * @return string
   */
  private function tabela()
  {
    return "
CREATE TABLE `crm_invoices_boletos` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `id_invoice` int(11) unsigned NOT NULL,
  `id_company` int(11) unsigned NOT NULL COMMENT 'Escopo, copiado da fatura para conferir o tenant sem JOIN.',
  `psp` varchar(20) NOT NULL COMMENT 'Provedor que emitiu este PDF.',
  `psp_charge_id` varchar(100) NOT NULL COMMENT 'Cobranca de origem; se mudar, o cache envelheceu.',
  `content` longtext NOT NULL COMMENT 'PDF em base64.',
  `bytes` int(11) unsigned NOT NULL DEFAULT 0 COMMENT 'Tamanho do PDF decodificado.',
  `created` datetime DEFAULT NULL,
  `created_by` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_invoices_boletos_invoice` (`id_invoice`),
  KEY `id_company` (`id_company`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `crm_invoices_boletos_ibfk_1` FOREIGN KEY (`id_invoice`) REFERENCES `crm_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `crm_invoices_boletos_ibfk_2` FOREIGN KEY (`id_company`) REFERENCES `crm_companies` (`id`),
  CONSTRAINT `crm_invoices_boletos_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `crm_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
";
  }
}
