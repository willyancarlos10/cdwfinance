<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Regras da consulta de WHOIS dos domínios internacionais (API Ninjas).
 *
 * Model próprio, e não métodos no Server_model, porque é outra história: o
 * Server_model fala com painéis de hospedagem (o que acontece DENTRO do
 * servidor) e o WHOIS fala com registradores (vencimento e nameservers). São
 * credenciais, cadências e modos de falhar diferentes — e a poda autoritativa
 * do Server_model não pode nem chegar perto deste fluxo. Mesmo precedente do
 * Receitaws_model.
 *
 * Três decisões que explicam o resto do arquivo:
 *
 * 1. UMA chamada de API por domínio REGISTRÁVEL. O mesmo domínio pode estar em
 *    vários servidores, e um painel lista subdomínios como se fossem domínios —
 *    consultar linha a linha multiplicaria o consumo de quota sem ganho.
 * 2. A comparação de nameservers usa a lista COMPLETA e ORDENADA. Registrador
 *    devolvendo o mesmo conjunto embaralhado é comum; sem ordenar, a rotina
 *    gritaria "NS trocado" toda semana.
 * 3. Erro do domínio conta como tentativa; erro de infraestrutura não. Sem essa
 *    distinção, ou um TLD que o plano não cobre queima o teto de chamadas toda
 *    rodada, ou uma noite de rede ruim empurra a base inteira para a frente.
 */
class Whois_model extends CI_Model
{
    /** Intervalo padrão de reconsulta de um domínio já consultado com sucesso. */
    const INTERVALO_DIAS_PADRAO = 7;

    /**
     * TLD que o plano contratado não cobre não resolve sozinho em uma semana —
     * insistir só queima quota. Espera bem mais antes de tentar de novo.
     */
    const INTERVALO_DIAS_PREMIUM = 30;

    /** Teto de chamadas por execução do cron, no escopo internacional. */
    const LIMITE_POR_RODADA_PADRAO = 100;

    /**
     * Teto no escopo .br. Bem maior porque o RDAP do Registro.br é gratuito e
     * não tem quota mensal — o que limita aqui é a educação com o serviço, não
     * o custo.
     */
    const LIMITE_POR_RODADA_BR = 200;

    /** Pausa entre chamadas. A API não documenta rate limit; conservador. */
    const PAUSA_ENTRE_CHAMADAS_US = 250000;

    /**
     * Pausa maior no .br: serviço público, mantido por terceiro e com limite
     * por janela de tempo. Um segundo entre consultas mantém a rodada bem
     * abaixo de qualquer bloqueio.
     */
    const PAUSA_ENTRE_CHAMADAS_BR_US = 1000000;

    /** Guarda de sanidade na varredura de candidatos. */
    const LIMITE_LINHAS_VARREDURA = 20000;

    /** Escopos de consulta — cada um tem sua origem, seu switch e seu cron. */
    const ESCOPO_INTERNACIONAL = 'internacional';
    const ESCOPO_BR = 'br';

    /** Grupo de crm_general_settings de cada escopo. */
    const GRUPO_NINJAS = 'ninjas';
    const GRUPO_RDAP = 'rdap';

    /**
     * Sufixos públicos com mais de um rótulo, usados para achar o domínio
     * registrável (eTLD+1).
     *
     * A parte `.br` é a lista COMPLETA da Public Suffix List (173 entradas,
     * incluindo as municipais e as `*.gov.br` estaduais), porque `.br` é a
     * maioria esmagadora da base: errar aqui faria `site.exemplo.com.br` ser
     * consultado como `com.br`. A parte internacional segue curada, porque o
     * volume é pequeno e o custo de um sufixo faltando é baixo — o domínio é
     * consultado errado, a origem responde 400/404 e a mensagem aparece na
     * tela. Corrigir é acrescentar uma linha.
     *
     * `br` entra sozinho porque existem registros diretos sob o TLD
     * (registro.br, nic.br).
     */
    const SUFIXOS_PUBLICOS = [
        'br',
        // --- .br: Public Suffix List completa ---
        '9guacu.br', 'abc.br', 'ac.gov.br', 'adm.br', 'adv.br', 'agr.br', 'aju.br', 'al.gov.br',
        'am.br', 'am.gov.br', 'anani.br', 'ap.gov.br', 'aparecida.br', 'api.br', 'app.br', 'arq.br',
        'art.br', 'ato.br', 'b.br', 'ba.gov.br', 'barueri.br', 'belem.br', 'bet.br', 'bhz.br',
        'bib.br', 'bio.br', 'blog.br', 'bmd.br', 'boavista.br', 'bsb.br', 'campinagrande.br',
        'campinas.br', 'caxias.br', 'ce.gov.br', 'cim.br', 'cng.br', 'cnt.br', 'com.br',
        'contagem.br', 'coop.br', 'coz.br', 'cri.br', 'cuiaba.br', 'curitiba.br', 'def.br',
        'des.br', 'det.br', 'dev.br', 'df.gov.br', 'ecn.br', 'eco.br', 'edu.br', 'emp.br',
        'enf.br', 'eng.br', 'es.gov.br', 'esp.br', 'etc.br', 'eti.br', 'far.br', 'feira.br',
        'flog.br', 'floripa.br', 'fm.br', 'fnd.br', 'fortal.br', 'fot.br', 'foz.br', 'fst.br',
        'g12.br', 'geo.br', 'ggf.br', 'go.gov.br', 'goiania.br', 'gov.br', 'gru.br', 'ia.br',
        'imb.br', 'ind.br', 'inf.br', 'jab.br', 'jampa.br', 'jdf.br', 'joinville.br', 'jor.br',
        'jus.br', 'leg.br', 'leilao.br', 'lel.br', 'log.br', 'londrina.br', 'ma.gov.br',
        'macapa.br', 'maceio.br', 'manaus.br', 'maringa.br', 'mat.br', 'med.br', 'mg.gov.br',
        'mil.br', 'morena.br', 'mp.br', 'ms.gov.br', 'mt.gov.br', 'mus.br', 'natal.br', 'net.br',
        'niteroi.br', 'not.br', 'ntr.br', 'odo.br', 'ong.br', 'org.br', 'osasco.br', 'pa.gov.br',
        'palmas.br', 'pb.gov.br', 'pe.gov.br', 'pi.gov.br', 'poa.br', 'ppg.br', 'pr.gov.br',
        'pro.br', 'psc.br', 'psi.br', 'pvh.br', 'qsl.br', 'radio.br', 'rec.br', 'recife.br',
        'rep.br', 'ribeirao.br', 'rio.br', 'riobranco.br', 'riopreto.br', 'rj.gov.br', 'rn.gov.br',
        'ro.gov.br', 'rr.gov.br', 'rs.gov.br', 'salvador.br', 'sampa.br', 'santamaria.br',
        'santoandre.br', 'saobernardo.br', 'saogonca.br', 'sc.gov.br', 'se.gov.br', 'seg.br',
        'sjc.br', 'slg.br', 'slz.br', 'social.br', 'sorocaba.br', 'sp.gov.br', 'srv.br', 'taxi.br',
        'tc.br', 'tec.br', 'teo.br', 'the.br', 'tmp.br', 'to.gov.br', 'trd.br', 'tur.br', 'tv.br',
        'udi.br', 'vet.br', 'vix.br', 'vlog.br', 'wiki.br', 'xyz.br', 'zlg.br',
        // --- internacionais mais comuns ---
        'co.uk', 'org.uk', 'me.uk', 'ac.uk', 'gov.uk', 'net.uk',
        'com.au', 'net.au', 'org.au', 'edu.au',
        'com.ar', 'com.mx', 'com.co', 'com.pe', 'com.uy', 'com.py', 'com.bo', 'com.ve', 'com.ec',
        'com.do', 'com.gt', 'com.pa', 'com.sv', 'com.ni', 'com.hn',
        'co.jp', 'ne.jp', 'or.jp', 'ac.jp',
        'com.cn', 'net.cn', 'org.cn', 'com.hk', 'com.sg', 'com.tw', 'com.my', 'com.ph', 'com.vn',
        'co.kr', 'co.in', 'co.za', 'co.nz', 'co.il', 'co.th', 'co.id', 'co.ke',
        'com.tr', 'com.ua', 'com.pl', 'com.ru', 'com.pt', 'com.es', 'com.gr',
        'com.ng', 'com.eg', 'com.sa', 'com.qa', 'com.kw',
    ];

    /**
     * Sufixos curinga: qualquer rótulo sob eles também é sufixo público. Em
     * `*.nom.br`, o registrável é `nome.sobrenome.nom.br` — quatro rótulos.
     */
    const SUFIXOS_CURINGA = ['nom.br'];

    /** Maior quantidade de rótulos que um sufixo público tem nas listas acima. */
    const MAX_ROTULOS_SUFIXO = 3;

    /** Campos historiados em crm_domains_whois_history. */
    const CAMPOS_HISTORIADOS = ['nameservers', 'expiration_date', 'registrar'];

    /**
     * Nota do lookupParaCadastro() quando o dado saiu da rede agora. Existe
     * para o modal distinguir isto do retrato reaproveitado, que vem datado.
     */
    const MENSAGEM_CONSULTA_AGORA = 'Consulta feita agora.';

    // ------------------------------------------------------------------
    // Configuração
    // ------------------------------------------------------------------

    /**
     * Chave da API decifrada.
     *
     * Três estados distintos de propósito, para o chamador dar a instrução
     * certa ao operador: '' = nunca cadastrada (vá em Parâmetros gerais);
     * FALSE = cadastrada mas ilegível (a secret_crypto_key mudou — recadastre).
     *
     * O decrypt mora aqui, e não no General_settings_model, porque aquele model
     * é um chave/valor genérico: ensiná-lo a decifrar significaria um `if` por
     * chave (os campos de e-mail continuam em texto puro). Também não fica no
     * controller, porque o cron roda sem sessão e sem controller de painel.
     * Mesmo desenho do Server_model::getConnectionConfig().
     *
     * @return string|bool
     */
    public function getApiKey()
    {
        $this->load->model('general_settings_model');

        $cifrada = (string) $this->general_settings_model->getGroupValue(self::GRUPO_NINJAS, 'ninjas_api_key', '');
        if ($cifrada === '') {
            return '';
        }

        $this->load->library('secret_crypto');

        // === FALSE, nunca empty(): '' é retorno válido da Secret_crypto.
        $plana = $this->secret_crypto->decrypt($cifrada);
        if ($plana === FALSE) {
            return FALSE;
        }

        return trim($plana);
    }

    /**
     * Interruptor da integração de cada escopo, independente do kill-switch do
     * cron. São dois porque as origens são independentes: dá para desligar a
     * API paga sem parar o RDAP, que é gratuito.
     *
     * @param  string $escopo
     * @return bool
     */
    public function isActive($escopo = self::ESCOPO_INTERNACIONAL)
    {
        $this->load->model('general_settings_model');

        if ($escopo === self::ESCOPO_BR) {
            return (string) $this->general_settings_model->getGroupValue(self::GRUPO_RDAP, 'rdap_active', '0') === '1';
        }

        return (string) $this->general_settings_model->getGroupValue(self::GRUPO_NINJAS, 'ninjas_active', '0') === '1';
    }

    /**
     * Origem da consulta, escolhida pelo TLD — mesma ideia do
     * Server_model::getClient(), que escolhe a integração pelo tipo de painel.
     *
     * @param  string $domain
     * @return Whois_provider
     */
    public function getClient($domain)
    {
        if ($this->isBr($domain)) {
            $this->load->library('rdap_registrobr');
            return $this->rdap_registrobr;
        }

        $this->load->library('ninjas_whois');
        return $this->ninjas_whois;
    }

    // ------------------------------------------------------------------
    // Elegibilidade e normalização
    // ------------------------------------------------------------------

    /**
     * O domínio pode ser consultado por alguma das origens?
     *
     * @param  string $domain
     * @return bool
     */
    public function isEligible($domain)
    {
        $host = rtrim(mb_strtolower(trim((string) $domain)), '.');

        if ($host === '') return FALSE;
        // Sem ponto não é domínio; IP às vezes vira "domínio" em algum painel.
        if (strpos($host, '.') === FALSE) return FALSE;
        if (filter_var($host, FILTER_VALIDATE_IP) !== FALSE) return FALSE;

        return TRUE;
    }

    /**
     * Domínio .br? É o que decide a origem da consulta.
     *
     * @param  string $domain
     * @return bool
     */
    public function isBr($domain)
    {
        $host = rtrim(mb_strtolower(trim((string) $domain)), '.');

        // Pega .com.br, .net.br, .adv.br e o registro direto (registro.br).
        return $host === 'br' || substr($host, -3) === '.br';
    }

    /**
     * Escopo do domínio.
     *
     * @param  string $domain
     * @return string ESCOPO_BR | ESCOPO_INTERNACIONAL
     */
    public function escopoDoDominio($domain)
    {
        return $this->isBr($domain) ? self::ESCOPO_BR : self::ESCOPO_INTERNACIONAL;
    }

    /**
     * Limpa o host de sujeira que os painéis costumam devolver.
     *
     * @param  string $host
     * @return string '' quando não sobra um host válido
     */
    private function normalizarHost($host)
    {
        $host = mb_strtolower(trim((string) $host));

        $host = preg_replace('#^[a-z]+://#', '', $host);
        $host = explode('/', $host)[0];
        $host = explode(':', $host)[0];
        $host = preg_replace('/^\*\./', '', $host);
        $host = preg_replace('/^www\./', '', $host);
        $host = rtrim(trim($host), '.');

        if ($host === '' || !preg_match('/^[a-z0-9.-]+$/', $host)) {
            return '';
        }

        if (in_array('', explode('.', $host), TRUE)) {
            return '';
        }

        return $host;
    }

    /**
     * O host inteiro é um sufixo público (e portanto não é registrável)?
     *
     * @param  array $partes rótulos do host
     * @return bool
     */
    private function ehSufixoPublico(array $partes)
    {
        $host = implode('.', $partes);

        if (in_array($host, self::SUFIXOS_PUBLICOS, TRUE)) {
            return TRUE;
        }

        // O próprio pai do curinga também é sufixo. Pelo algoritmo estrito da
        // Public Suffix List, 'nom.br' cairia na regra 'br' e seria registrável;
        // na prática é uma categoria do Registro.br que ninguém registra, e
        // consultá-la renderia um 404 garantido.
        if (in_array($host, self::SUFIXOS_CURINGA, TRUE)) {
            return TRUE;
        }

        // Curinga: em '*.nom.br', 'joao.nom.br' também é sufixo.
        if (count($partes) >= 2) {
            $pai = implode('.', array_slice($partes, 1));
            if (in_array($pai, self::SUFIXOS_CURINGA, TRUE)) {
                return TRUE;
            }
        }

        return FALSE;
    }

    /**
     * Reduz um host ao domínio registrável (eTLD+1), casando o sufixo público
     * mais longo primeiro.
     *
     *   webmail.exemplo.com     -> exemplo.com
     *   site.exemplo.com.br     -> exemplo.com.br
     *   loja.exemplo.co.uk      -> exemplo.co.uk
     *   registro.br             -> registro.br      (registro direto sob o TLD)
     *   prefeitura.sp.gov.br    -> prefeitura.sp.gov.br
     *   joao.silva.nom.br       -> joao.silva.nom.br (curinga)
     *
     * @param  string $host
     * @return string|bool FALSE quando não dá para reduzir com segurança
     */
    public function registrableDomain($host)
    {
        $host = $this->normalizarHost($host);
        if ($host === '') {
            return FALSE;
        }

        $partes = explode('.', $host);
        $total = count($partes);

        if ($total < 2) {
            return FALSE;
        }

        // O host é só o sufixo (com.br, co.uk, br): não há domínio a consultar.
        if ($this->ehSufixoPublico($partes)) {
            return FALSE;
        }

        // Do sufixo mais longo para o mais curto: 'sp.gov.br' tem de vencer
        // 'gov.br', senão prefeitura.sp.gov.br viraria sp.gov.br.
        for ($tamanho = min(self::MAX_ROTULOS_SUFIXO, $total - 1); $tamanho >= 1; $tamanho--) {
            $sufixo = array_slice($partes, -$tamanho);

            if ($this->ehSufixoPublico($sufixo)) {
                return implode('.', array_slice($partes, -($tamanho + 1)));
            }
        }

        // Sufixo de um rótulo só (.com, .net, .io): registrável são 2 rótulos.
        return implode('.', array_slice($partes, -2));
    }

    // ------------------------------------------------------------------
    // Seleção dos pendentes
    // ------------------------------------------------------------------

    /**
     * Domínios registráveis a consultar nesta rodada, já agrupados.
     *
     * @param  int|null $dias    intervalo de reconsulta
     * @param  int|null $limite  teto de domínios (= teto de chamadas)
     * @return array    ['exemplo.com' => ['linhas' => object[], 'nameservers' => string|null]]
     */
    public function getPendingDomains($escopo = self::ESCOPO_INTERNACIONAL, $dias = NULL, $limite = NULL)
    {
        $dias = ($dias === NULL) ? self::INTERVALO_DIAS_PADRAO : (int) $dias;
        $limite = ($limite === NULL) ? self::LIMITE_POR_RODADA_PADRAO : (int) $limite;

        if ($limite <= 0) {
            return [];
        }

        // O filtro por TLD entra no SQL para não varrer a base inteira em cada
        // rodada; o escopoDoDominio() abaixo é a checagem definitiva.
        $comparador = ($escopo === self::ESCOPO_BR) ? 'LIKE' : 'NOT LIKE';

        // Sem LIMIT do lado do agrupamento: N linhas viram 1 chamada, então o
        // teto só pode ser aplicado depois de agrupar. O LIMIT do SQL é guarda.
        $sql = 'SELECT `id`, `id_company`, `domain`, `whois_last_check`, `whois_status`,'
            . ' `whois_expiration_date`, `whois_nameservers`, `whois_registrar`'
            . ' FROM `crm_servers_domains`'
            . ' WHERE `domain` ' . $comparador . ' ?'
            . ' ORDER BY (`whois_last_check` IS NULL) DESC, `whois_last_check` ASC, `domain` ASC'
            . ' LIMIT ' . (int) self::LIMITE_LINHAS_VARREDURA;

        $linhas = $this->db->query($sql, ['%.br'])->result();

        $grupos = [];
        foreach ($linhas as $linha) {
            if (!$this->isEligible($linha->domain) || $this->escopoDoDominio($linha->domain) !== $escopo) {
                continue;
            }

            $registravel = $this->registrableDomain($linha->domain);
            if ($registravel === FALSE) {
                continue;
            }

            if (!isset($grupos[$registravel])) {
                $grupos[$registravel] = ['linhas' => [], 'nameservers' => NULL];
            }

            $grupos[$registravel]['linhas'][] = $linha;

            // Baseline de nameservers do grupo: uma linha recém-criada (por
            // exemplo, o domínio migrou de servidor e a poda apagou a antiga)
            // nasce sem retrato. Herdar o da irmã evita tratar uma migração
            // interna como "primeira leitura" e perder a troca de NS real.
            if ($grupos[$registravel]['nameservers'] === NULL && !empty($linha->whois_nameservers)) {
                $grupos[$registravel]['nameservers'] = $linha->whois_nameservers;
            }
        }

        $pendentes = [];
        foreach ($grupos as $registravel => $grupo) {
            if ($this->estaNaHora($grupo['linhas'], $dias)) {
                $pendentes[$registravel] = $grupo;
            }
        }

        // Nunca consultados primeiro, depois os mais antigos: em poucas rodadas
        // a base inteira é coberta mesmo com o teto por execução.
        uasort($pendentes, function ($a, $b) {
            return $this->menorCheck($a['linhas']) <=> $this->menorCheck($b['linhas']);
        });

        return array_slice($pendentes, 0, $limite, TRUE);
    }

    /**
     * O grupo está vencido? Basta uma linha pedindo consulta.
     *
     * @param  array $linhas
     * @param  int   $dias
     * @return bool
     */
    private function estaNaHora(array $linhas, $dias)
    {
        $agora = time();

        foreach ($linhas as $linha) {
            if (empty($linha->whois_last_check)) {
                return TRUE;
            }

            $ultima = strtotime($linha->whois_last_check);
            if ($ultima === FALSE) {
                return TRUE;
            }

            $intervalo = ($linha->whois_status === 'premium')
                ? self::INTERVALO_DIAS_PREMIUM
                : $dias;

            if ($ultima < $agora - ($intervalo * 86400)) {
                return TRUE;
            }
        }

        return FALSE;
    }

    /**
     * Menor whois_last_check do grupo. Nunca consultado ordena primeiro.
     *
     * @param  array $linhas
     * @return int
     */
    private function menorCheck(array $linhas)
    {
        $menor = PHP_INT_MAX;

        foreach ($linhas as $linha) {
            if (empty($linha->whois_last_check)) {
                return 0;
            }

            $ts = strtotime($linha->whois_last_check);
            if ($ts !== FALSE && $ts < $menor) {
                $menor = $ts;
            }
        }

        return $menor === PHP_INT_MAX ? 0 : $menor;
    }

    // ------------------------------------------------------------------
    // Consulta e gravação
    // ------------------------------------------------------------------

    /**
     * Consulta um domínio registrável e aplica o resultado em todas as linhas.
     *
     * @param  string $registravel
     * @param  array  $grupo  ['linhas' => object[], 'nameservers' => string|null]
     * @param  string $apiKey
     * @param  int    $idUser
     * @return array  success, message, abort, ns_changed, changes, whois
     */
    public function syncDomain($registravel, array $grupo, $apiKey, $idUser)
    {
        $linhas = isset($grupo['linhas']) ? $grupo['linhas'] : [];
        if (empty($linhas)) {
            return $this->resultado(FALSE, 'Nenhuma linha para atualizar.');
        }

        $agora = date('Y-m-d H:i:s');
        $resposta = $this->consultar($registravel, $apiKey);

        if (empty($resposta['success'])) {
            // 'livre' antes de 'premium': é resposta da origem sobre o DOMÍNIO
            // (não existe), e não uma limitação nossa de plano ou de rede. Vira
            // o indicador "Domínios disponíveis" do Dashboard pela faixa da
            // crm_servers_domains_v (migration 016).
            if (!empty($resposta['livre'])) {
                $status = 'livre';
            } else {
                $status = !empty($resposta['premium']) ? 'premium' : 'erro';
            }

            // Falha de infraestrutura não conta como tentativa: marcar
            // whois_last_check aqui empurraria o domínio para o fim da fila sem
            // ele ter sido realmente consultado.
            $contaTentativa = empty($resposta['transient']);

            $this->registrarFalha($linhas, $status, $resposta['message'], $agora, $idUser, $contaTentativa);

            return $this->resultado(FALSE, $resposta['message'], !empty($resposta['abort']), FALSE, 0, NULL, $status === 'livre');
        }

        $dados = $resposta['data'];

        // 200 com corpo vazio acontece em domínio livre ou sem WHOIS público.
        if (empty($dados['expiration_date']) && empty($dados['name_servers']) && empty($dados['registrar'])) {
            $mensagem = 'O WHOIS não retornou dados para este domínio.';
            $this->registrarFalha($linhas, 'sem_dados', $mensagem, $agora, $idUser, TRUE);

            return $this->resultado(FALSE, $mensagem);
        }

        $raw = isset($resposta['raw']) && is_array($resposta['raw']) ? $resposta['raw'] : [];

        return $this->aplicarResultado($registravel, $grupo, $dados, $agora, $idUser, $raw);
    }

    /**
     * Consulta imediata de uma linha, ignorando o intervalo de reconsulta.
     * É o que o botão da tela de domínios chama.
     *
     * @param  int $idServerDomain
     * @param  int $idCompany      escopo do tenant
     * @param  int $idUser
     * @return array success, message, abort, ns_changed, changes
     */
    public function syncOne($idServerDomain, $idCompany, $idUser)
    {
        $linha = $this->global_model->getWhere_off('crm_servers_domains', [
            'id' => (int) $idServerDomain,
            'id_company' => (int) $idCompany,
        ], TRUE);

        if (empty($linha)) {
            return $this->resultado(FALSE, 'Domínio não encontrado.');
        }

        if (!$this->isEligible($linha->domain)) {
            return $this->resultado(FALSE, 'Este registro não é um domínio consultável.');
        }

        $registravel = $this->registrableDomain($linha->domain);
        if ($registravel === FALSE) {
            return $this->resultado(FALSE, 'Não foi possível identificar o domínio registrável de "' . $linha->domain . '".');
        }

        $apiKey = $this->credencialPara($registravel);
        if (is_array($apiKey)) {
            return $apiKey; // já é um resultado de erro
        }

        // Consulta só esta linha: o botão é pontual e não deve espalhar
        // gravação por outros servidores sem o usuário pedir.
        return $this->syncDomain($registravel, [
            'linhas' => [$linha],
            'nameservers' => $linha->whois_nameservers,
        ], $apiKey, $idUser);
    }

    /**
     * Consulta a partir de um domínio de CONTRATO, que é de onde as telas do
     * cliente e do contrato disparam.
     *
     * Com vínculo, delega para syncOne() e tudo acontece como sempre: grava o
     * retrato em crm_servers_domains, historia mudança e propaga o vencimento.
     *
     * Sem vínculo não existe linha em crm_servers_domains onde guardar o
     * retrato, então a consulta atualiza só o vencimento do próprio contrato e
     * devolve o registro para o modal. Sem linha de referência também não há
     * baseline de nameserver, logo não há como detectar troca — por isso esses
     * domínios não entram no histórico.
     *
     * @param  int $idContractDomain
     * @param  int $idCompany escopo do tenant
     * @param  int $idUser
     * @return array
     */
    public function syncContractDomain($idContractDomain, $idCompany, $idUser)
    {
        $linha = $this->global_model->getWhere_off('crm_contracts_domains', [
            'id' => (int) $idContractDomain,
            'id_company' => (int) $idCompany,
        ], TRUE);

        if (empty($linha)) {
            return $this->resultado(FALSE, 'Domínio não encontrado.');
        }

        if (!empty($linha->id_server_domain)) {
            return $this->syncOne((int) $linha->id_server_domain, $idCompany, $idUser);
        }

        if (!$this->isEligible($linha->domain)) {
            return $this->resultado(FALSE, 'Este registro não é um domínio consultável.');
        }

        $registravel = $this->registrableDomain($linha->domain);
        if ($registravel === FALSE) {
            return $this->resultado(FALSE, 'Não foi possível identificar o domínio registrável de "' . $linha->domain . '".');
        }

        $apiKey = $this->credencialPara($registravel);
        if (is_array($apiKey)) {
            return $apiKey; // já é um resultado de erro
        }

        $resposta = $this->consultar($registravel, $apiKey);

        if (empty($resposta['success'])) {
            return $this->resultado(FALSE, $resposta['message'], !empty($resposta['abort']));
        }

        $dados = $resposta['data'];

        if (empty($dados['expiration_date']) && empty($dados['name_servers']) && empty($dados['registrar'])) {
            return $this->resultado(FALSE, 'O WHOIS não retornou dados para este domínio.');
        }

        if (!empty($dados['expiration_date'])) {
            $this->global_model->edit('crm_contracts_domains', [
                'due_date' => $dados['expiration_date'],
                'modified' => date('Y-m-d H:i:s'),
                'modified_by' => (int) $idUser,
            ], 'id', (int) $linha->id);
        }

        return $this->resultado(TRUE, $this->resumo($dados), FALSE, FALSE, 0, [
            'domain' => $registravel,
            'normalizado' => $dados,
            'raw' => isset($resposta['raw']) && is_array($resposta['raw']) ? $resposta['raw'] : [],
            'sem_vinculo' => TRUE,
        ]);
    }

    /**
     * Consulta para PREENCHER o modal de cadastro de domínio do contrato — o
     * único ponto em que a consulta acontece ANTES de a linha existir, e por
     * isso o único que não recebe um id.
     *
     * A origem é escolhida pelo TLD, como em todo o resto: `.br` vai no RDAP do
     * Registro.br, o resto na API Ninjas (getClient()).
     *
     * Três caminhos, na ordem em que valem a pena:
     *
     * 1. Algum servidor do tenant hospeda o domínio e o retrato dele está
     *    recente (INTERVALO_DIAS_PADRAO, o mesmo intervalo do cron): devolve o
     *    que já está gravado, sem tocar na rede. Uma quota da API Ninjas não
     *    pode ser gasta para reler o que o cron leu anteontem, ainda mais num
     *    botão que o usuário clica quantas vezes quiser.
     * 2. Existe linha, mas o retrato está velho ou nunca foi feito: delega ao
     *    syncOne(), que consulta E grava. A chamada já foi paga, descartar o
     *    que ela trouxe seria desperdício — mesmo argumento do botão de WHOIS
     *    da tela de domínios.
     * 3. Nenhum servidor do tenant hospeda o domínio: consulta sem gravar,
     *    porque não há onde guardar o retrato. É o mesmo motivo pelo qual
     *    syncContractDomain() não historia domínio sem vínculo.
     *
     * Falhar aqui é normal e não é impeditivo: quem libera o SALVAR do modal é
     * a busca nos servidores, e uma consulta sem resposta só significa "digite
     * à mão". Por isso o retorno tem forma própria (e não a de resultado()):
     * quem chama precisa dos dois campos do formulário, não do balanço de
     * mudanças de uma sincronização.
     *
     * Não há checagem de isActive(): os interruptores governam a cadência do
     * cron, e a consulta pedida no clique é a exceção — mesmo precedente do
     * syncOne() chamado pelo botão da listagem de domínios.
     *
     * @param  string $domain    o que o usuário digitou (já normalizado ou não)
     * @param  int    $idCompany escopo do tenant
     * @param  int    $idUser
     * @return array  success, message, domain, origem, livre, data
     */
    public function lookupParaCadastro($domain, $idCompany, $idUser)
    {
        $domain = mb_strtolower(trim((string) $domain));

        if (!$this->isEligible($domain)) {
            return $this->respostaCadastro(FALSE, 'Este não é um domínio consultável.', '');
        }

        $registravel = $this->registrableDomain($domain);
        if ($registravel === FALSE) {
            return $this->respostaCadastro(FALSE, 'Não foi possível identificar o domínio registrável de "' . $domain . '".', '');
        }

        $linha = $this->linhaDeReferencia($domain, $registravel, $idCompany);

        // 1. Retrato recente: nada de rede.
        if (!empty($linha) && $this->retratoRecente($linha)) {
            return $this->respostaCadastro(
                TRUE,
                'Dados da consulta de ' . date('d/m/Y', strtotime($linha->whois_last_check)) . '.',
                $registravel,
                [
                    'expiration_date' => $linha->whois_expiration_date,
                    'registrar' => $linha->whois_registrar,
                ],
                'cache'
            );
        }

        // 2. Com linha: consulta e grava, como faria o cron.
        if (!empty($linha)) {
            $resultado = $this->syncOne((int) $linha->id, $idCompany, $idUser);

            if (!empty($resultado['success'])) {
                $normalizado = isset($resultado['whois']['normalizado']) ? (array) $resultado['whois']['normalizado'] : [];

                // Mensagem própria, e não o resumo() que syncOne devolve: aquele
                // texto traz os nameservers, que não têm campo neste modal. O
                // registro completo continua a um clique, na lupa da linha
                // depois de salva.
                return $this->respostaCadastro(TRUE, self::MENSAGEM_CONSULTA_AGORA, $registravel, $normalizado, 'consulta');
            }

            // A consulta falhou, mas a linha guarda o retrato da última que deu
            // certo — é o mesmo valor que a listagem de domínios mostra, e
            // preencher com ele economiza digitação. Só não vale quando a
            // origem disse que o domínio está LIVRE: aí a data velha descreve
            // um registro que não existe mais.
            if (empty($resultado['livre']) && (!empty($linha->whois_expiration_date) || !empty($linha->whois_registrar))) {
                $quando = !empty($linha->whois_last_check) ? date('d/m/Y', strtotime($linha->whois_last_check)) : NULL;

                return $this->respostaCadastro(
                    TRUE,
                    'A consulta falhou (' . $resultado['message'] . ') — foram usados os dados'
                        . ($quando !== NULL ? ' de ' . $quando : ' da última consulta') . '. Confira antes de salvar.',
                    $registravel,
                    [
                        'expiration_date' => $linha->whois_expiration_date,
                        'registrar' => $linha->whois_registrar,
                    ],
                    'cache'
                );
            }

            return $this->respostaCadastro(FALSE, $resultado['message'], $registravel, [], '', !empty($resultado['livre']));
        }

        // 3. Sem linha: consulta sem gravar.
        $apiKey = $this->credencialPara($registravel);
        if (is_array($apiKey)) {
            return $this->respostaCadastro(FALSE, $apiKey['message'], $registravel);
        }

        $resposta = $this->consultar($registravel, $apiKey);

        if (empty($resposta['success'])) {
            return $this->respostaCadastro(FALSE, $resposta['message'], $registravel, [], '', !empty($resposta['livre']));
        }

        $dados = is_array($resposta['data']) ? $resposta['data'] : [];

        if (empty($dados['expiration_date']) && empty($dados['registrar'])) {
            return $this->respostaCadastro(FALSE, 'A consulta não retornou vencimento nem local de registro para este domínio.', $registravel);
        }

        return $this->respostaCadastro(TRUE, self::MENSAGEM_CONSULTA_AGORA, $registravel, $dados, 'consulta');
    }

    /**
     * Linha de crm_servers_domains que serve de retrato para este domínio no
     * tenant.
     *
     * Ordena pela linha MELHOR INFORMADA, e não pela mais nova: com o mesmo
     * domínio em dois servidores (site num painel, e-mail em outro), uma linha
     * já consultada com sucesso poupa a chamada que a nunca consultada
     * obrigaria. O retrato é do domínio registrável, então qualquer uma serve.
     *
     * @param  string $domain      o que o usuário digitou
     * @param  string $registravel eTLD+1 correspondente
     * @param  int    $idCompany
     * @return object|null
     */
    private function linhaDeReferencia($domain, $registravel, $idCompany)
    {
        // O digitado pode ser um subdomínio (webmail.exemplo.com), que nenhum
        // servidor lista, enquanto o registrável está lá — e vice-versa. Os
        // dois entram, com a variante www. de cada, como na busca do contrato.
        $candidatos = [];
        foreach ([$domain, $registravel] as $nome) {
            $nome = rtrim(mb_strtolower(trim((string) $nome)), '.');
            if ($nome === '') continue;

            $candidatos[] = $nome;
            $candidatos[] = (strpos($nome, 'www.') === 0) ? substr($nome, 4) : 'www.' . $nome;
        }

        $candidatos = array_values(array_unique(array_filter($candidatos)));
        if (empty($candidatos)) {
            return NULL;
        }

        $marcadores = implode(', ', array_fill(0, count($candidatos), '?'));

        $linhas = $this->db->query(
            'SELECT `id`, `id_company`, `domain`, `whois_last_check`, `whois_status`,'
            . ' `whois_expiration_date`, `whois_registrar`'
            . ' FROM `crm_servers_domains`'
            . ' WHERE `id_company` = ? AND `domain` IN (' . $marcadores . ')'
            . " ORDER BY (`whois_status` = 'sucesso') DESC, `whois_last_check` DESC, `id` ASC"
            . ' LIMIT 1',
            array_merge([(int) $idCompany], $candidatos)
        )->result();

        return empty($linhas) ? NULL : $linhas[0];
    }

    /**
     * O retrato gravado ainda vale? Mesmo intervalo do cron: se ele não
     * reconsultaria este domínio hoje, o modal também não precisa.
     *
     * Exige conteúdo, e não só status: uma consulta que voltou vazia é
     * 'sucesso' sem nada para preencher, e reaproveitá-la deixaria o
     * formulário em branco sem nunca tentar a rede.
     *
     * @param  object $linha
     * @return bool
     */
    private function retratoRecente($linha)
    {
        if (empty($linha->whois_last_check) || $linha->whois_status !== 'sucesso') {
            return FALSE;
        }

        if (empty($linha->whois_expiration_date) && empty($linha->whois_registrar)) {
            return FALSE;
        }

        $ultima = strtotime($linha->whois_last_check);

        return $ultima !== FALSE && $ultima > time() - (self::INTERVALO_DIAS_PADRAO * 86400);
    }

    /**
     * Retorno do lookupParaCadastro(): só o que o formulário precisa.
     *
     * @param  bool   $success
     * @param  string $mensagem
     * @param  string $registravel domínio efetivamente consultado
     * @param  array  $dados       normalizado da library ou colunas da linha
     * @param  string $origem      'consulta' | 'cache' | ''
     * @param  bool   $livre       a origem respondeu que o domínio não existe
     * @return array
     */
    private function respostaCadastro($success, $mensagem, $registravel, array $dados = [], $origem = '', $livre = FALSE)
    {
        $vencimento = isset($dados['expiration_date']) ? $dados['expiration_date'] : NULL;
        $registrador = isset($dados['registrar']) ? $dados['registrar'] : NULL;
        $registrador = is_string($registrador) ? trim($registrador) : '';

        return [
            'success' => (bool) $success,
            'message' => (string) $mensagem,
            'domain' => (string) $registravel,
            'origem' => (string) $origem,
            'livre' => (bool) $livre,
            'data' => [
                'expiration_date' => !empty($vencimento) ? $vencimento : NULL,
                'registrar' => $registrador !== '' ? mb_substr($registrador, 0, 150) : NULL,
            ],
        ];
    }

    /**
     * Credencial necessária para consultar o domínio.
     *
     * @param  string $registravel
     * @return string|array chave, ou um resultado de erro pronto
     */
    private function credencialPara($registravel)
    {
        // O RDAP do Registro.br é público: só o escopo internacional exige chave.
        if ($this->isBr($registravel)) {
            return '';
        }

        $apiKey = $this->getApiKey();

        if ($apiKey === FALSE) {
            return $this->resultado(FALSE, 'A chave da API Ninjas está ilegível. Recadastre-a em Parâmetros gerais — a chave de criptografia pode ter sido trocada.');
        }
        if ($apiKey === '') {
            return $this->resultado(FALSE, 'A chave da API Ninjas não está cadastrada em Parâmetros gerais.');
        }

        return $apiKey;
    }

    /**
     * Chamada de rede isolada, com a sessão suspensa e exceção contida.
     *
     * @param  string $registravel
     * @param  string $apiKey
     * @return array resposta da library
     */
    private function consultar($registravel, $apiKey)
    {
        $cliente = $this->getClient($registravel);

        // No cron (CLI) não há sessão e isto é no-op; no botão do painel, é o que
        // solta o GET_LOCK do MySQL durante a chamada de rede.
        $sessao = sessao_suspender();
        try {
            return $cliente->lookup($registravel, $apiKey);
        } catch (Throwable $e) {
            return [
                'success' => FALSE,
                'message' => 'Falha inesperada na consulta: ' . $e->getMessage(),
                'data' => NULL,
                'raw' => [],
                'abort' => FALSE,
                'premium' => FALSE,
                'transient' => TRUE,
            ];
        } finally {
            sessao_retomar($sessao);
        }
    }

    /**
     * Resumo de uma consulta bem-sucedida, para o console do cron e o retorno
     * dos endpoints.
     *
     * @param  array $dados
     * @return string
     */
    private function resumo(array $dados)
    {
        $partes = [];

        if (!empty($dados['expiration_date'])) {
            $partes[] = 'vence em ' . date('d/m/Y', strtotime($dados['expiration_date']));
        }
        if (!empty($dados['name_servers'])) {
            $partes[] = 'NS: ' . implode(', ', $dados['name_servers']);
        }

        return empty($partes) ? 'Consulta concluída.' : implode(' | ', $partes);
    }

    /**
     * Grava o retrato do WHOIS e historia o que mudou.
     *
     * @param  string $registravel
     * @param  array  $grupo
     * @param  array  $dados  resposta normalizada da library
     * @param  string $agora
     * @param  int    $idUser
     * @param  array  $raw    resposta crua, devolvida ao chamador para exibição
     * @return array
     */
    private function aplicarResultado($registravel, array $grupo, array $dados, $agora, $idUser, array $raw = [])
    {
        $nameservers = isset($dados['name_servers']) ? $dados['name_servers'] : NULL;
        $listaNs = is_array($nameservers) ? mb_substr(implode(', ', $nameservers), 0, 500) : NULL;

        $mudancas = 0;
        $trocouNs = FALSE;

        foreach ($grupo['linhas'] as $linha) {
            $colunas = [
                'whois_last_check' => $agora,
                'whois_status' => 'sucesso',
                'whois_message' => NULL,
            ];

            // Chave AUSENTE = a origem não informou; não entra no UPDATE, para
            // não apagar dado bom com NULL. Mesma regra do upsertDomain.
            if (array_key_exists('expiration_date', $dados)) {
                $colunas['whois_expiration_date'] = $dados['expiration_date'];
            }
            if (array_key_exists('registrar', $dados)) {
                $colunas['whois_registrar'] = $dados['registrar'];
            }
            if ($nameservers !== NULL) {
                $colunas['whois_nameservers'] = $listaNs;
                $colunas['whois_ns1'] = isset($dados['ns1']) ? $dados['ns1'] : NULL;
                $colunas['whois_ns2'] = isset($dados['ns2']) ? $dados['ns2'] : NULL;
            }

            // Baseline: o da própria linha, ou o herdado do grupo quando esta
            // linha ainda não tem retrato (domínio recém-migrado de servidor).
            $nsAnterior = !empty($linha->whois_nameservers)
                ? $linha->whois_nameservers
                : $grupo['nameservers'];

            if ($nameservers !== NULL && $this->registrarHistorico($linha, $registravel, 'nameservers', $nsAnterior, $listaNs, $agora, $idUser)) {
                $colunas['whois_ns_changed'] = $agora;
                $trocouNs = TRUE;
                $mudancas++;
            }

            if (array_key_exists('expiration_date', $dados)
                && $this->registrarHistorico($linha, $registravel, 'expiration_date', $linha->whois_expiration_date, $dados['expiration_date'], $agora, $idUser)) {
                $mudancas++;
            }

            if (array_key_exists('registrar', $dados)
                && $this->registrarHistorico($linha, $registravel, 'registrar', $linha->whois_registrar, $dados['registrar'], $agora, $idUser)) {
                $mudancas++;
            }

            $colunas['modified'] = $agora;
            $colunas['modified_by'] = (int) $idUser;

            $this->global_model->edit('crm_servers_domains', $colunas, 'id', (int) $linha->id);

            if (array_key_exists('expiration_date', $dados) && !empty($dados['expiration_date'])) {
                $this->propagarVencimento((int) $linha->id, $dados['expiration_date'], $agora, $idUser);
            }
        }

        return $this->resultado(TRUE, $this->resumo($dados), FALSE, $trocouNs, $mudancas, [
            'domain' => $registravel,
            'normalizado' => $dados,
            'raw' => $raw,
        ]);
    }

    /**
     * Espelha o vencimento nos domínios de contrato vinculados a esta linha.
     *
     * Sobrescreve sempre, por decisão de negócio: o WHOIS é a fonte de verdade
     * do vencimento e o campo do modal de contrato passa a ser informativo
     * quando há vínculo (a tela avisa disso).
     *
     * @param  int    $idServerDomain
     * @param  string $vencimento Y-m-d
     * @param  string $agora
     * @param  int    $idUser
     * @return void
     */
    private function propagarVencimento($idServerDomain, $vencimento, $agora, $idUser)
    {
        $this->global_model->editwhere('crm_contracts_domains', [
            'due_date' => $vencimento,
            'modified' => $agora,
            'modified_by' => (int) $idUser,
        ], ['id_server_domain' => (int) $idServerDomain]);
    }

    /**
     * Registra uma mudança no histórico.
     *
     * Só mudança REAL entra: valor anterior vazio é primeira leitura, não troca.
     * Sem essa guarda, a primeira rodada encheria o histórico com uma linha por
     * campo de cada domínio e a linha do tempo perderia a serventia.
     *
     * @return bool TRUE quando houve mudança registrada
     */
    private function registrarHistorico($linha, $registravel, $campo, $antigo, $novo, $agora, $idUser)
    {
        $antigo = ($antigo === NULL) ? '' : trim((string) $antigo);
        $novo = ($novo === NULL) ? '' : trim((string) $novo);

        if ($antigo === '' || $antigo === $novo) {
            return FALSE;
        }

        $this->global_model->add('crm_domains_whois_history', [
            'id_server_domain' => (int) $linha->id,
            'id_company' => (int) $linha->id_company,
            'domain' => mb_substr($registravel, 0, 255),
            'field' => $campo,
            'old_value' => mb_substr($antigo, 0, 500),
            'new_value' => $novo === '' ? NULL : mb_substr($novo, 0, 500),
            'detected' => $agora,
            'created_by' => (int) $idUser,
        ]);

        return TRUE;
    }

    /**
     * Marca a falha nas linhas do grupo, sem encostar nos valores já gravados —
     * eles continuam sendo a última informação boa que temos.
     *
     * @param  array  $linhas
     * @param  string $status
     * @param  string $mensagem
     * @param  string $agora
     * @param  int    $idUser
     * @param  bool   $contaTentativa se FALSE, whois_last_check fica intocado
     * @return void
     */
    private function registrarFalha(array $linhas, $status, $mensagem, $agora, $idUser, $contaTentativa)
    {
        $colunas = [
            'whois_status' => $status,
            'whois_message' => mb_substr((string) $mensagem, 0, 500),
            'modified' => $agora,
            'modified_by' => (int) $idUser,
        ];

        if ($contaTentativa) {
            $colunas['whois_last_check'] = $agora;
        }

        foreach ($linhas as $linha) {
            $this->global_model->edit('crm_servers_domains', $colunas, 'id', (int) $linha->id);
        }
    }

    /**
     * Histórico de um domínio, para o modal da tela de domínios.
     *
     * Busca por `domain` + tenant (e não por id_server_domain) de propósito: a
     * poda da sincronização zera o vínculo, e o histórico precisa continuar
     * aparecendo para o mesmo domínio.
     *
     * @param  string $domain     valor de crm_servers_domains.domain
     * @param  int    $idCompany
     * @param  int    $limite
     * @return array
     */
    public function getHistory($domain, $idCompany, $limite = 50)
    {
        $registravel = $this->registrableDomain($domain);
        if ($registravel === FALSE) {
            return [];
        }

        return $this->global_model->getWhereOrderByLimit_off(
            'crm_domains_whois_history_v',
            ['domain' => $registravel, 'id_company' => (int) $idCompany],
            'detected',
            'desc',
            (int) $limite,
            FALSE
        );
    }

    /**
     * @param  array|null $whois registro consultado, para a tela exibir
     * @param  bool       $livre a origem respondeu que o domínio não existe
     * @return array
     */
    private function resultado($success, $mensagem, $abort = FALSE, $trocouNs = FALSE, $mudancas = 0, $whois = NULL, $livre = FALSE)
    {
        return [
            'success' => (bool) $success,
            'message' => (string) $mensagem,
            'abort' => (bool) $abort,
            'ns_changed' => (bool) $trocouNs,
            'changes' => (int) $mudancas,
            'whois' => $whois,
            // Sem consulta bem-sucedida, mas COM estado gravado: quem chama
            // precisa saber para atualizar a tela em vez de só mostrar o erro.
            'livre' => (bool) $livre,
        ];
    }
}
