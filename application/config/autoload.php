<?php
defined('BASEPATH') or exit('No direct script access allowed');

$autoload['packages'] = array();
/*
| 'session' NÃO entra aqui de propósito. Autoloaded, ela era criada em toda
| requisição stateless — API Bearer, MCP, cron por URL, bot sem cookie —, e
| cada uma gravava uma linha em crm_sessions e tomava/soltava um GET_LOCK do
| MySQL sem necessidade. É carregada explicitamente em exatamente três lugares,
| que cobrem tudo que usa sessão:
|   1. application/core/MY_Controller.php  -> todo o painel (área logada)
|   2. application/controllers/Login.php   -> login/logout, cadastro, senha
|   3. application/controllers/Ajuda.php   -> flashdata da Central de Ajuda
| Ao criar um controller que use $this->session sem estender MY_Controller,
| carregue a library no construtor.
|
| 'cart' também saiu: não é usado em lugar nenhum do projeto (é a library de
| carrinho de compras do CI3) e o construtor dela faz load->driver('session')
| — ou seja, sozinha ela reintroduzia a sessão em TODA requisição, inclusive
| nas da API e do MCP, anulando o efeito de tirar 'session' daqui.
*/
$autoload['libraries'] = array('database', 'migration', 'form_validation', 'user_agent', 'pagination');
$autoload['drivers'] = array();
$autoload['helper'] = array('url', 'form', 'date', 'text', 'custom_helper', 'string');
$autoload['config'] = array();
$autoload['language'] = array();
$autoload['model'] = array('my_model', 'global_model', 'image_model', 'receitaws_model');
