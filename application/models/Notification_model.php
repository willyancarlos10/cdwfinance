<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Resolve PARA QUEM vai cada aviso ao cliente.
 *
 * Existiam duas respostas para a mesma pergunta, convivendo sem regra desde a
 * migration 033:
 *
 *  - `crm_contracts.notification_config` — por CONTRATO, com tipo por e-mail
 *    (destinatário / cópia / cópia oculta). Cadastrável na tela, mas inerte:
 *    nada lia o campo.
 *  - `Adjustment_model::destinatario()` — por CLIENTE, em cascata: contato
 *    `financeiro` → qualquer contato com e-mail → `crm_customers.email`.
 *
 * O CLAUDE.md deixou explícito que a escolha entre as duas ficaria para quando
 * o envio existisse. É agora.
 *
 * A REGRA: **o contrato vence quando tem ao menos um `destinatario`**; sem
 * isso, cai na cascata do cliente.
 *
 * Por quê:
 *  - a lista do contrato é a resposta MAIS ESPECÍFICA, e foi preenchida de
 *    propósito — o contrato de hospedagem avisa o TI, o de licença avisa o
 *    financeiro;
 *  - lista vazia significa "não configurado", **não** "não avisar". Cair na
 *    cascata é o que mantém os contratos existentes funcionando sem ninguém
 *    preencher nada;
 *  - exigir ao menos um `destinatario` (e não só "ter algum e-mail") é a mesma
 *    guarda do formulário: uma lista só de cópias não tem para quem enviar, e o
 *    servidor de e-mail recusaria.
 *
 * Um resolvedor SÓ, usado pelo aviso de faturamento e pelo de reajuste — senão
 * as duas regras voltam a existir, agora escondidas em models diferentes.
 */
class Notification_model extends CI_Model
{
    /** Tipos aceitos em `notification_config`, no mesmo idioma da tela. */
    const TIPO_DESTINATARIO = 'destinatario';
    const TIPO_COPIA = 'copia';
    const TIPO_COPIA_OCULTA = 'copia_oculta';

    public function __construct()
    {
        parent::__construct();
        $this->load->model('global_model');
    }

    /**
     * Destinatários de um aviso sobre determinado contrato.
     *
     * @param  object $contrato linha de crm_contracts (precisa de notification_config)
     * @param  object $cliente  linha de crm_customers
     * @return array ['to' => [], 'cc' => [], 'cco' => [], 'origem' => 'contrato'|'cliente'|'']
     */
    public function destinatarios($contrato, $cliente)
    {
        $doContrato = $this->doContrato($contrato);

        // Só assume o contrato quando ele tem PARA QUEM enviar. Uma lista só
        // com cópias cairia na cascata, e é o comportamento certo: sem
        // destinatário não há e-mail.
        if (!empty($doContrato['to'])) {
            $doContrato['origem'] = 'contrato';
            return $doContrato;
        }

        $doCliente = $this->doCliente($cliente);

        return [
            'to' => $doCliente,
            // As cópias do contrato são preservadas mesmo quando o "para" veio
            // da cascata: quem cadastrou uma cópia quer receber, e descartá-la
            // por falta de destinatário seria perder cadastro sem avisar.
            'cc' => $doContrato['cc'],
            'cco' => $doContrato['cco'],
            'origem' => empty($doCliente) ? '' : 'cliente',
        ];
    }

    /**
     * Lê `notification_config` e separa por tipo.
     *
     * JSON inválido ou formato inesperado devolve listas vazias — o aviso cai
     * na cascata em vez de falhar. Um campo de configuração corrompido não pode
     * impedir a cobrança de chegar ao cliente.
     *
     * @param  object $contrato
     * @return array to, cc, cco
     */
    public function doContrato($contrato)
    {
        $vazio = ['to' => [], 'cc' => [], 'cco' => []];

        if (empty($contrato) || empty($contrato->notification_config)) {
            return $vazio;
        }

        $config = json_decode((string) $contrato->notification_config, TRUE);
        if (!is_array($config) || empty($config['emails']) || !is_array($config['emails'])) {
            return $vazio;
        }

        $mapa = [
            self::TIPO_DESTINATARIO => 'to',
            self::TIPO_COPIA => 'cc',
            self::TIPO_COPIA_OCULTA => 'cco',
        ];

        $saida = $vazio;
        $vistos = [];

        foreach ($config['emails'] as $linha) {
            if (!is_array($linha)) continue;

            $email = mb_strtolower(trim((string) ($linha['email'] ?? '')));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

            // O mesmo endereço em dois campos receberia duas cópias.
            if (isset($vistos[$email])) continue;
            $vistos[$email] = TRUE;

            $tipo = (string) ($linha['type'] ?? '');
            $destino = isset($mapa[$tipo]) ? $mapa[$tipo] : 'to';

            $saida[$destino][] = $email;
        }

        return $saida;
    }

    /**
     * A cascata por cliente: contato `financeiro` → qualquer contato com
     * e-mail → `crm_customers.email`.
     *
     * É a mesma regra que o Adjustment_model já usava; mora aqui para os dois
     * avisos compartilharem uma implementação só.
     *
     * @param  object $cliente
     * @return array lista com zero ou um e-mail
     */
    public function doCliente($cliente)
    {
        if (empty($cliente)) return [];

        $contatos = $this->global_model->getWhere_off(
            'crm_customers_contacts',
            ['id_customer' => (int) $cliente->id],
            FALSE
        );

        $qualquer = '';

        foreach ((array) $contatos as $contato) {
            $email = mb_strtolower(trim((string) $contato->email));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

            if ((string) $contato->type === 'financeiro') {
                return [$email];
            }

            if ($qualquer === '') $qualquer = $email;
        }

        if ($qualquer !== '') return [$qualquer];

        $doCadastro = mb_strtolower(trim((string) $cliente->email));

        return ($doCadastro !== '' && filter_var($doCadastro, FILTER_VALIDATE_EMAIL))
            ? [$doCadastro]
            : [];
    }

    /**
     * Telefones de WhatsApp do contrato (etapa I).
     *
     * Devolve a lista já normalizada, para o envio futuro não precisar reler o
     * JSON. Sem `type`: no WhatsApp cada número recebe a própria mensagem, e
     * cópia/cópia oculta não significam nada ali.
     *
     * @param  object $contrato
     * @return array lista de telefones (só dígitos)
     */
    public function whatsappsDoContrato($contrato)
    {
        if (empty($contrato) || empty($contrato->notification_config)) return [];

        $config = json_decode((string) $contrato->notification_config, TRUE);
        if (!is_array($config) || empty($config['whatsapps']) || !is_array($config['whatsapps'])) {
            return [];
        }

        $saida = [];
        foreach ($config['whatsapps'] as $linha) {
            if (!is_array($linha)) continue;
            $fone = preg_replace('/\D/', '', (string) ($linha['phone'] ?? ''));
            if ($fone !== '' && !in_array($fone, $saida, TRUE)) $saida[] = $fone;
        }

        return $saida;
    }
}
