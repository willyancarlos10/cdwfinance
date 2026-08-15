<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * ViaCEP - Biblioteca para CodeIgniter
 *
 * Tem como objetivo criar um meio para consultar o WebService
 * do ViaCEP e ao mesmo tempo armazenar os dados consultados
 * em uma tabela com tempo para expirar.
 *
 * @license http://www.gnu.org/licenses/gpl-3.0.en.html
 * @author Pablo Alexander da Rocha Gonçalves
 * @link http://www.parg.com.br/
 * @link https://github.com/parg-programador
 */
class Viacep
{

    private $CI;
    private $tabela = 'crm_viacep'; // nome da tabela
    private $expira = 6; // quantidade de meses
    private $erros = array();
    // temporário
    private $registro; // quando for encontrado um CEP válido no banco de dados
    // campos
    private $cep;
    private $logradouro;
    private $complemento;
    private $bairro;
    private $localidade;
    private $uf;
    private $ibge;
    private $gia;

    /**
     * Constrói a classe
     *
     * @param string $cep Define o cep que vai ser consultado
     * @param bool $autoConsultar Define se é para consultar o CEP após a construção do objeto
     */
    public function __construct()
    {
        $this->CI = &get_instance();
        $this->cep = null;

        // verifica o a biblioteca database
        if (!$this->CI->load->is_loaded('database')) {
            $this->CI->load->database();
        }

        // verifica se a tabela existe
        if (!$this->CI->db->table_exists($this->tabela)) {
            $this->verificaCriaTabela();
        }
    }

    /**
     * Obtem o CEP
     * @return string
     */
    public function getCEP()
    {
        return $this->cep;
    }

    /**
     * Obtem o Logradouro
     * @return string
     */
    public function getLogradouro()
    {
        return $this->logradouro;
    }

    /**
     * Obtem o Complemento
     * @return string
     */
    public function getComplemento()
    {
        return $this->complemento;
    }

    /**
     * Obtem o Bairro
     * @return string
     */
    public function getBairro()
    {
        return $this->bairro;
    }

    /**
     * Obtem a Localidade
     * @return string
     */
    public function getLocalidade()
    {
        return mb_strtoupper($this->localidade);
    }

    /**
     * Obtem o UF
     * @return string
     */
    public function getUF()
    {
        return mb_strtoupper($this->uf);
    }

    /**
     * Obtem o IBGE
     * @return string
     */
    public function getIBGE()
    {
        return $this->ibge;
    }

    /**
     * Obtem a GIA
     * @return string
     */
    public function getGIA()
    {
        return $this->gia;
    }

    /**
     * Procedimento para consultar um CEP
     *
     * @param string $cep
     * @return bool retorna true caso encontre o CEP
     */
    public function consultar($cep = null)
    {
        // verifica o CEP
        if ($cep != null) {
            $this->cep = $cep;
        }

        // verifica
        if ($this->cep != null && is_string($this->cep)) {
            // caso seja um cep válido
            if (preg_match('/[0-9]{5}-[0-9]{3}/', $this->cep)) {
                // verifica no banco de dados interno
                if ($this->cepExiste()) {
                    $this->configuraRegistro();

                    return true;
                } elseif ($this->buscarCEP()) {
                    $this->configuraRegistro();

                    // verifica se o CEP existe e está expirado
                    if ($this->cepExiste(false)) {
                    } else {
                        $this->salvaRegistro();
                    }

                    return true;
                } else {
                    $this->erros[] = "Não foi possível obter o $cep!";
                }
            } else {
                $this->erros[] = "O CEP $cep não é válido!";
            }
        } else {
            $this->erros[] = "Favor informar um CEP!";
        }

        return false;
    }

    /**
     * Retorna um array contendo a lista de erros
     *
     * @return array
     */
    public function getErros()
    {
        return $this->erros;
    }

    /**
     * Retorna um erro especifico
     *
     * @param int $index
     * @return string
     */
    public function getErro($index)
    {
        if ((count($this->erros) - 1) <= $index) {
            return $this->erros[$index];
        }
    }

    /**
     * Retorna o ultimo erro ocorrido
     *
     * @return string
     */
    public function getUltimoErro()
    {
        if (count($this->erros) > 0) {
            return $this->erros[count($this->erros) - 1];
        }
    }

    /**
     * Verifica se o CEP está cadastrado no banco de dados
     * @return boolean
     */
    private function cepExiste($verificaValidade = true)
    {
        $pSQL = '';

        // verifica a validade
        if ($verificaValidade) {
            $pSQL = " AND DATE_ADD(`criado_em`, INTERVAL {$this->expira} MONTH) > NOW()";
        }

        $data = $this->CI->db->query("SELECT * FROM `crm_viacep` WHERE `cep` = '{$this->cep}'$pSQL;");

        // verifica
        if ($data) {
            $result = $data->result();

            if ($result && count($result) > 0) {
                $this->registro = $result[0];
                return true;
            }
        }

        return false;
    }

    /**
     * Obtem o registro do webservice do ViaCEP
     * @return boolean
     */
    private function buscarCEP()
    {
        $url = "https://viacep.com.br/ws/{$this->cep}/json/";

        // abre a conexão
        $ch = curl_init();

        // define url
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Sem timeout, o ViaCEP fora do ar segura a request até o teto do PHP e
        // ela morre no meio de um comando — que é o que dessincroniza a conexão
        // MySQL e derruba o RELEASE_LOCK da sessão no shutdown.
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        // executa o post
        $json = curl_exec($ch);

        // Fechado aqui, e não no fim: o "return true" do caminho de sucesso
        // pulava o curl_close() e vazava o handle.
        curl_close($ch);

        $resultado = json_decode($json);

        if (!isset($resultado->erro) && isset($resultado->cep)) {
            unset($resultado->unidade);
            unset($resultado->estado);
            unset($resultado->regiao);
            $this->registro = $resultado;
            return true;
        }

        return false;
    }

    /**
     * Procedimento para configurar o registro obtido
     */
    private function configuraRegistro()
    {
        $this->logradouro = $this->registro->logradouro;
        $this->complemento = $this->registro->complemento;
        $this->localidade = $this->registro->localidade;
        $this->bairro = $this->registro->bairro;
        $this->uf = $this->registro->uf;
        $this->ibge = $this->registro->ibge;
        $this->gia = $this->registro->gia;
    }

    /**
     * Procedimento para salvar o registro na base de dados
     */
    private function salvaRegistro()
    {
        $this->CI->db->insert($this->tabela, $this->registro);
    }

    /**
     * Procedimento para atualizar o registro na base de dados
     */
    private function atualizarRegistro()
    {
        $reg = $this->registro;

        // define a condição para atualizar
        $this->CI->db->where('cep', $reg->cep);

        // remove o cep de $reg
        unset($reg->cep);

        // atualiza
        $this->CI->db->update($this->tabela, $reg);
    }

    /**
     * Procedimento para verificar e criar a tabela CEP
     */
    private function verificaCriaTabela()
    {
        // carrega o db forge
        $this->CI->load->dbforge();

        // define os campos
        $campos = [
            'cep' => [
                'type' => 'VARCHAR',
                'constraint' => 9,
                'unique' => true
            ],
            'logradouro' => [
                'type' => 'VARCHAR',
                'constraint' => 250
            ],
            'complemento' => [
                'type' => 'VARCHAR',
                'constraint' => 50
            ],
            'bairro' => [
                'type' => 'VARCHAR',
                'constraint' => 150
            ],
            'localidade' => [
                'type' => 'VARCHAR',
                'constraint' => 100
            ],
            'uf' => [
                'type' => 'VARCHAR',
                'constraint' => 2
            ],
            'ibge' => [
                'type' => 'VARCHAR',
                'constraint' => 10
            ],
            'gia' => [
                'type' => 'VARCHAR',
                'constraint' => 10
            ],
            '`criado_em` TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
        ];

        // define o campo id
        $this->CI->dbforge->add_field('id');

        // gera os outros campos
        $this->CI->dbforge->add_field($campos);

        // cria a tabela se ela não existe
        $attributes = array('ENGINE' => 'InnoDB');
        $this->CI->dbforge->create_table($this->tabela, true, $attributes);
    }
}
