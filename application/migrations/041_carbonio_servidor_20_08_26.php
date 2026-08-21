<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Carbonio como quarto tipo de servidor.
 *
 * NENHUMA coluna muda de tipo: `crm_servers.type` e `crm_servers_domains.source`
 * já são varchar(20) e 'carbonio' cabe; as views `_v` não têm lógica por tipo e
 * por isso não precisam ser recriadas. Esta migration existe para os COMMENTs
 * de schema não mentirem — é como este projeto documenta o banco, e um comentário
 * que lista três painéis num sistema de quatro engana quem for ler a tabela
 * antes do código.
 *
 * O `port` também muda de sentido: deixa de ser exclusivo do SSH do CloudPanel
 * e passa a valer para a API administrativa do Carbonio (6071 por padrão).
 */
class Migration_Carbonio_servidor_20_08_26 extends CI_Migration
{
    public function up()
    {
        if ($this->db->field_exists('type', 'crm_servers')) {
            $this->db->query("
ALTER TABLE `crm_servers`
  MODIFY `type` varchar(20) NOT NULL COMMENT 'whm | directadmin | cloudpanel | carbonio'
");
        }

        if ($this->db->field_exists('port', 'crm_servers')) {
            $this->db->query("
ALTER TABLE `crm_servers`
  MODIFY `port` smallint(5) unsigned DEFAULT NULL COMMENT 'Porta SSH do CloudPanel ou da API do Carbonio (padrao 6071). WHM usa 2087 e DirectAdmin 2222.'
");
        }

        if ($this->db->field_exists('source', 'crm_servers_domains')) {
            $this->db->query("
ALTER TABLE `crm_servers_domains`
  MODIFY `source` varchar(20) NOT NULL COMMENT 'whm | directadmin | cloudpanel | carbonio | manual'
");
        }
    }

    public function down()
    {
        // Servidor Carbonio cadastrado deixaria de ser sincronizável (o
        // Server_model::TYPES do codigo antigo nao o conhece), mas as linhas
        // ficam: apagar cadastro e dominios levaria junto o vinculo dos
        // contratos, que e o dado caro aqui.
        if ($this->db->field_exists('type', 'crm_servers')) {
            $this->db->query("
ALTER TABLE `crm_servers`
  MODIFY `type` varchar(20) NOT NULL COMMENT 'whm | directadmin | cloudpanel'
");
        }

        if ($this->db->field_exists('port', 'crm_servers')) {
            $this->db->query("
ALTER TABLE `crm_servers`
  MODIFY `port` smallint(5) unsigned DEFAULT NULL COMMENT 'Porta SSH do CloudPanel. WHM usa 2087 e DirectAdmin 2222.'
");
        }

        if ($this->db->field_exists('source', 'crm_servers_domains')) {
            $this->db->query("
ALTER TABLE `crm_servers_domains`
  MODIFY `source` varchar(20) NOT NULL COMMENT 'whm | directadmin | cloudpanel | manual'
");
        }
    }
}
