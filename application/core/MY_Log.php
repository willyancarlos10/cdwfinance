<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Log com lista de exceções — impede que ruído de configuração do servidor
 * seja gravado em application/logs.
 *
 * Por que existe: erro de startup do PHP (extensão declarada no php.ini que não
 * carrega) é reemitido pelo CI3 em TODA requisição. O _shutdown_handler() de
 * system/core/Common.php lê error_get_last() no fim do request e inclui
 * E_CORE_WARNING na máscara — numa requisição sem erro nenhum, o "último erro"
 * ainda é o warning do startup, então a mesma linha é gravada de novo. O log do
 * dia vira milhares de linhas idênticas e o cron_notificaerrosagencia despeja
 * tudo por e-mail, escondendo o erro que importa.
 *
 * Caso concreto: 'Unable to load dynamic library i360.so' (extensão do
 * Imunify360 sem o libhs_runtime.so.5 no servidor). É problema de servidor, não
 * da aplicação — e se a extensão fizer falta de fato, a falha aparece por outro
 * caminho ("call to undefined function"), que continua sendo logado normal.
 *
 * Carregada sozinha por load_class() (config subclass_prefix = 'MY_'); não
 * precisa de registro em lugar nenhum.
 */
class MY_Log extends CI_Log
{
    /**
     * Trechos que, se aparecerem na mensagem, fazem a linha NÃO ser gravada.
     * Comparação por substring, sem diferenciar maiúsculas.
     *
     * Manter a lista curta e específica: cada item aqui é um erro que você deixa
     * de enxergar. Só entra o que for comprovadamente ruído de ambiente e
     * repetido a cada requisição.
     */
    const IGNORAR = array(
        // Extensão listada no php.ini do servidor que não carrega (i360.so do
        // Imunify360 e afins). Nunca é causado por código da aplicação.
        'Unable to load dynamic library',
    );

    public function write_log($level, $msg)
    {
        foreach (self::IGNORAR as $trecho) {
            if (stripos($msg, $trecho) !== FALSE) {
                // TRUE = "gravei", para quem chamou não tratar como falha de escrita.
                return TRUE;
            }
        }

        return parent::write_log($level, $msg);
    }
}
