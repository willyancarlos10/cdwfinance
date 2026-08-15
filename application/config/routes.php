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
// A API v1 não expõe nenhuma operação no momento: os endpoints de conteúdo do
// CMS (artigos, vagas, leads, mailboxes) foram removidos junto com os módulos.
// A infraestrutura continua de pé — Api_Controller (core), chaves Bearer por
// empresa (crm_api_keys) e o rate limiter — então basta criar o controller do
// recurso e registrar a rota AQUI, ACIMA do catch-all abaixo.
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
