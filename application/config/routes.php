<?php
defined('BASEPATH') or exit('No direct script access allowed');

# CUSTOM
$route['redefinir/(:any)'] = "login/redefinir/$1";
$route['sessao'] = 'painel/sessao';
$route['editor/upload'] = 'painel/editor_upload';
// Diagnóstico: o navegador devolve o corpo que não conseguiu interpretar.
$route['editor/upload-diag'] = 'painel/editor_upload_diag';
$route['empresas/chaves-api'] = 'empresas/api_chaves';
$route['empresas/chaves-api/criar'] = 'empresas/post_criar_chave_api';
$route['empresas/chaves-api/revogar'] = 'empresas/post_revogar_chave_api';

# CADASTRO PÚBLICO DE CLIENTES (wizard acessado pelo link da tela de login)
//
// json_getsefaz é alias do endpoint público que o cadastro de empresas já usa
// (Login::json_getsefaz) — zero lógica duplicada. json_getcep e
// json_get_cities_by_id ficam no próprio Cadastro_cliente porque precisam
// resolver id_state/id_city (a versão do Login não resolve).
//
// A rota com (:any) captura o TOKEN do tenant (crm_companies.token) e precisa
// ser a ÚLTIMA deste bloco: (:any) casa com barras, então as rotas de json_*
// têm que vir antes.
$route['cadastro-cliente'] = 'cadastro_cliente/index';
$route['cadastro-cliente/json_getsefaz/(:any)'] = 'login/json_getsefaz/$1';
$route['cadastro-cliente/json_getcep/(:any)'] = 'cadastro_cliente/json_getcep/$1';
$route['cadastro-cliente/json_get_cities_by_id/(:any)'] = 'cadastro_cliente/json_get_cities_by_id/$1';
$route['cadastro-cliente/(:any)'] = 'cadastro_cliente/index/$1';

# API PÚBLICA (v1)
//
// Documentação (Swagger UI). Fica fora de /api/v1 de propósito: aquele prefixo
// é todo capturado pelo catch-all abaixo.
$route['api/docs'] = 'api_docs/index';
$route['api/docs/openapi.yaml'] = 'api_docs/spec';
//
// Operações de CONSULTA (somente leitura), todas em Api_v1. O caminho usa
// kebab-case e o método, snake_case. Rota nova entra AQUI, acima do catch-all.
//
// Os dois recursos de domínio são nomeados por extenso de propósito: um
// `/domains` genérico não diria se a resposta é o cadastro comercial
// (crm_contracts_domains, com vencimento e registrador) ou o inventário de
// contas de hospedagem (crm_servers_domains, com disco e WHOIS). Quem consome
// isto são agentes de IA, que escolhem o endpoint lendo o nome.
$route['api/v1/companies'] = 'api_v1/companies';
$route['api/v1/companies/(:num)'] = 'api_v1/company/$1';
$route['api/v1/customers'] = 'api_v1/customers';
$route['api/v1/customers/(:num)'] = 'api_v1/customer/$1';
$route['api/v1/contacts'] = 'api_v1/contacts';
$route['api/v1/attachments'] = 'api_v1/attachments';
$route['api/v1/contracts'] = 'api_v1/contracts';
$route['api/v1/contracts/(:num)'] = 'api_v1/contract/$1';
$route['api/v1/contract-domains'] = 'api_v1/contract_domains';
$route['api/v1/servers'] = 'api_v1/servers';
$route['api/v1/servers/(:num)'] = 'api_v1/server/$1';
$route['api/v1/server-domains'] = 'api_v1/server_domains';
$route['api/v1/service-types'] = 'api_v1/service_types';
//
// Servidor MCP (JSON-RPC 2.0), para agentes de IA. Fica DENTRO de /api/v1 de
// propósito: o CSRF está ligado e `api/v1/.*` é a única exclusão versionada
// que o cobre — config.php está no .gitignore, então pôr o MCP em `/mcp`
// exigiria uma alteração de config que não sobe no deploy (verificado: POST
// /mcp responde 403). A documentação continua em /mcp/docs, que é GET e não
// passa por csrf_verify().
$route['api/v1/mcp'] = 'mcp/index';
//
// Fora de /api/v1 porque o catch-all daquele prefixo engoliria — e por ser
// GET não precisa da exclusão de CSRF. O .htaccess da raiz já tem a
// RewriteRule para este caminho.
$route['mcp/docs'] = 'mcp_docs/index';
//
// Precisa ser a ÚLTIMA rota de api/v1: o CI3 avalia na ordem e para no primeiro
// match, então qualquer rota específica nova vai ACIMA desta linha.
//
// `POST /api/v1` (o caminho exato, sem barra) é a única exceção: não está em
// csrf_exclude_uris — que cobre `api/v1/.*` — e por isso cai na recusa de CSRF
// em vez do 404. Ampliar a exclusão não compensa: config.php é versionado por
// ambiente (.gitignore), então a mudança não subiria no deploy.
$route['api/v1'] = 'api_404';
$route['api/v1/.*'] = 'api_404';

# DEFAULT
$route['default_controller'] = 'painel';
$route['404_override'] = 'painel/erro404';
$route['translate_uri_dashes'] = TRUE;
