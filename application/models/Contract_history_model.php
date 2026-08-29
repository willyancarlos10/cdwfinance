<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Histórico do contrato e aviso por e-mail dos eventos de estado.
 *
 * Ponto ÚNICO das duas coisas, e é de propósito que elas moram juntas: o aviso
 * nasce do registro. Se `registrar()` gravasse a linha e coubesse a cada
 * controller lembrar de chamar o envio, bastaria um esquecer para o sistema
 * passar a avisar de suspensão mas não de encerramento — e ninguém percebe um
 * e-mail que não chega. É a mesma razão pela qual `Psp_model::aplicarBaixa()` é
 * privado e serve as duas vias de descoberta do pagamento.
 *
 * O envio é ENFILEIRAMENTO (`Global_model::send_email()` grava em `crm_cron`),
 * nunca entrega: a suspensão de um contrato já espera a rede dos painéis, e
 * pendurar um SMTP no fim disso deixaria o operador olhando a ampulheta por
 * causa de um aviso interno. Quem entrega é o `cron_enviar_email`, que já roda.
 *
 * DUAS CADÊNCIAS, e a diferença é o volume:
 *
 *  - **Ato humano na tela → um e-mail por evento**, na hora. São poucos por
 *    dia e cada um merece atenção.
 *  - **Importação (`origin = 'importacao'`) → um resumo no fim da rodada.**
 *    Uma execução reescreve centenas de contratos; um e-mail por linha seria
 *    uma caixa de entrada inutilizável, e caixa inutilizável vira filtro de
 *    lixeira — o aviso morreria junto com o caso que ele existe para pegar. A
 *    decisão vive AQUI, olhando o `origin`, e não em quem chama: assim o
 *    importador não tem como errar.
 *
 * Os destinatários são INTERNOS (a equipe), configurados em Parâmetros gerais >
 * Contratos, com a mesma cascata do monitoramento — lista vazia cai no e-mail
 * da empresa. Não se confunde com `crm_contracts.notification_config` (033),
 * que é quem avisar o CLIENTE sobre boleto e nota fiscal: aqui ninguém de fora
 * pode ser avisado de que o próprio contrato foi suspenso.
 */
class Contract_history_model extends CI_Model
{
    /** Grupo em crm_general_settings. */
    const GRUPO = 'contratos';

    /** Quantos eventos o resumo lista antes de cortar com "e mais N". */
    const LIMITE_LINHAS_RESUMO = 40;

    /**
     * Catálogo dos eventos.
     *
     * `severidade` governa só a cor no e-mail e o selo na tela. `padrao` é o
     * que vem marcado para quem nunca configurou: os quatro que o pedido citou
     * (suspensão, reativação, encerramento, exclusão). `criado` e `reaberto`
     * ficam registrados no histórico mas fora do aviso por default — contrato
     * novo é rotina, e avisar de rotina é o caminho mais curto para o e-mail
     * ser ignorado.
     *
     * @return array slug => rótulo, severidade, padrao
     */
    public function eventos()
    {
        return [
            'criado' => ['rotulo' => 'Contrato criado', 'severidade' => 'info', 'padrao' => FALSE],
            'suspenso' => ['rotulo' => 'Contrato suspenso', 'severidade' => 'alerta', 'padrao' => TRUE],
            'reativado' => ['rotulo' => 'Contrato reativado', 'severidade' => 'info', 'padrao' => TRUE],
            'encerrado' => ['rotulo' => 'Contrato encerrado', 'severidade' => 'critico', 'padrao' => TRUE],
            'reaberto' => ['rotulo' => 'Contrato reaberto', 'severidade' => 'info', 'padrao' => FALSE],
            'excluido' => ['rotulo' => 'Contrato excluído', 'severidade' => 'critico', 'padrao' => TRUE],
        ];
    }

    /**
     * De onde partiu a mudança.
     *
     * É esta coluna que responde à pergunta que originou o módulo: "quem está
     * suspendendo contrato sozinho?". Com `modified_by` só dava para ver
     * PROCESSOS AUTOMÁTICOS, que é o mesmo usuário do cron e da importação.
     *
     * @return array slug => rótulo
     */
    public function origens()
    {
        return [
            'painel' => 'Painel',
            'importacao' => 'Importação do gestor-interno',
            'cron' => 'Rotina automática',
            'api' => 'API',
        ];
    }

    /**
     * Rótulo do evento, caindo no próprio slug quando desconhecido.
     *
     * Mesma regra dos motivos de cancelamento: evento gravado por uma versão
     * futura não pode sumir da tela por não ter tradução.
     *
     * @param  string $slug
     * @return string
     */
    public function rotuloEvento($slug)
    {
        $catalogo = $this->eventos();
        return isset($catalogo[$slug]['rotulo']) ? $catalogo[$slug]['rotulo'] : (string) $slug;
    }

    /**
     * @param  string $slug
     * @return string
     */
    public function rotuloOrigem($slug)
    {
        $catalogo = $this->origens();
        return isset($catalogo[$slug]) ? $catalogo[$slug] : (string) $slug;
    }

    // ------------------------------------------------------------------
    // Registro
    // ------------------------------------------------------------------

    /**
     * Grava um evento e, quando for o caso, enfileira o aviso.
     *
     * O contrato pode vir como objeto (o caminho normal — quem chama já o tem
     * em mãos) ou como id. Na EXCLUSÃO ele precisa vir como objeto e a chamada
     * precisa acontecer ANTES do DELETE: depois, não há de onde tirar o cliente
     * nem o rótulo, e a FK já teria zerado `id_contract`.
     *
     * Nunca lança exceção e nunca propaga falha: o histórico é trilha, não
     * pré-requisito. Uma falha ao gravar a linha não pode desfazer uma
     * suspensão que já aconteceu nos painéis — seria trocar um registro
     * perdido por um serviço no ar com o contrato suspenso.
     *
     * @param  object|int $contrato linha de crm_contracts (ou o id)
     * @param  string     $event    slug de eventos()
     * @param  int        $idUser
     * @param  array      $opcoes   status_from, status_to, origin, reason, comments, detail
     * @return int|bool   id da linha, ou FALSE
     */
    public function registrar($contrato, $event, $idUser, array $opcoes = [])
    {
        if (!is_object($contrato)) {
            $contrato = $this->global_model->getWhere_off('crm_contracts', ['id' => (int) $contrato], TRUE);
        }

        if (empty($contrato) || empty($contrato->id)) {
            log_message('error', '[HISTORICO] Evento "' . $event . '" sem contrato — nada gravado.');
            return FALSE;
        }

        $catalogo = $this->eventos();
        if (!isset($catalogo[$event])) {
            log_message('error', '[HISTORICO] Evento desconhecido "' . $event . '" — contrato ' . (int) $contrato->id . '.');
            return FALSE;
        }

        $origem = isset($opcoes['origin']) ? (string) $opcoes['origin'] : 'painel';
        if (!array_key_exists($origem, $this->origens())) {
            $origem = 'painel';
        }

        $linha = [
            'id_contract' => (int) $contrato->id,
            'id_company' => (int) $contrato->id_company,
            'id_customer' => !empty($contrato->id_customer) ? (int) $contrato->id_customer : NULL,
            'contract_label' => $this->rotuloContrato($contrato),
            'event' => $event,
            // O par de status é gravado mesmo quando o evento já o implica: é o
            // que descreve a transição observada pela importação, que não tem
            // "ação" nenhuma para nomear.
            'status_from' => isset($opcoes['status_from']) ? mb_substr((string) $opcoes['status_from'], 0, 20) : NULL,
            'status_to' => isset($opcoes['status_to']) ? mb_substr((string) $opcoes['status_to'], 0, 20) : NULL,
            'origin' => $origem,
            'reason' => !empty($opcoes['reason']) ? mb_substr((string) $opcoes['reason'], 0, 50) : NULL,
            'comments' => !empty($opcoes['comments']) ? mb_substr((string) $opcoes['comments'], 0, 500) : NULL,
            'detail' => !empty($opcoes['detail']) ? mb_substr((string) $opcoes['detail'], 0, 500) : NULL,
            'created' => date('Y-m-d H:i:s'),
            'created_by' => (int) $idUser,
        ];

        $id = $this->global_model->add('crm_contracts_history', $linha);

        if (empty($id)) {
            log_message('error', '[HISTORICO] Falha ao gravar o evento "' . $event . '" — tenant '
                . (int) $contrato->id_company . ', contrato ' . (int) $contrato->id . '.');
            return FALSE;
        }

        // A importação avisa em bloco no fim da rodada (ver notificarLote).
        if ($origem !== 'importacao') {
            $this->notificar((int) $id);
        }

        return (int) $id;
    }

    /**
     * "#1379 — RAZÃO SOCIAL", o identificador que sobrevive à exclusão.
     *
     * Sai do objeto quando ele já traz `customer_name` (a `crm_contracts_v`
     * traz); senão, uma leitura a mais. Não é JOIN na view justamente porque a
     * linha precisa continuar legível depois de o contrato e o cliente sumirem.
     *
     * @param  object $contrato
     * @return string
     */
    private function rotuloContrato($contrato)
    {
        $nome = '';

        if (!empty($contrato->customer_name)) {
            $nome = (string) $contrato->customer_name;
        } elseif (!empty($contrato->id_customer)) {
            $cliente = $this->global_model->getWhere_off('crm_customers', ['id' => (int) $contrato->id_customer], TRUE);
            if (!empty($cliente->name)) $nome = (string) $cliente->name;
        }

        $rotulo = '#' . (int) $contrato->id;
        if ($nome !== '') $rotulo .= ' — ' . $nome;

        return mb_substr($rotulo, 0, 255);
    }

    // ------------------------------------------------------------------
    // Leitura
    // ------------------------------------------------------------------

    /**
     * Eventos de um contrato, do mais recente para o mais antigo.
     *
     * Sem paginação: mesmo um contrato antigo acumula poucas dezenas de
     * mudanças de estado (bem diferente das faturas, que crescem uma por
     * competência para sempre e por isso paginam).
     *
     * **`id` desc é DESEMPATE, e é necessário.** `created` é datetime, com
     * precisão de segundo, e os eventos deste módulo nascem justamente em
     * rajada: suspender e encerrar em sequência caem no mesmo segundo, e a
     * importação grava dezenas. Só por `created`, os empates saem em ordem
     * arbitrária e a timeline mostraria "reativado" acima de "suspenso" — a
     * história contada ao contrário, no caso em que alguém está investigando.
     * É a mesma razão do `id desc` na ordenação da listagem de clientes.
     *
     * Query direta porque `getWhereOrderBy_off()` só aceita um campo de
     * ordenação.
     *
     * @param  int $idContract
     * @param  int $idCompany
     * @return array
     */
    public function listarPorContrato($idContract, $idCompany)
    {
        return $this->db->query(
            'SELECT * FROM `crm_contracts_history_v`
              WHERE `id_contract` = ? AND `id_company` = ?
              ORDER BY `created` DESC, `id` DESC',
            [(int) $idContract, (int) $idCompany]
        )->result();
    }

    // ------------------------------------------------------------------
    // Aviso por e-mail
    // ------------------------------------------------------------------

    /** @return bool */
    public function avisoAtivo()
    {
        return (string) $this->general_settings_model->getGroupValue(self::GRUPO, 'contratos_aviso_ativo', '1') === '1';
    }

    /**
     * Quais eventos disparam e-mail.
     *
     * Guardado como CSV de slugs, e não uma coluna por evento: evento novo no
     * catálogo não pede migration nem mexe na tela de parâmetros. Sem a chave
     * gravada (ninguém configurou ainda), valem os `padrao` do catálogo.
     *
     * @return array slugs
     */
    public function eventosAvisados()
    {
        $bruto = $this->general_settings_model->getGroupValue(self::GRUPO, 'contratos_aviso_eventos', NULL);

        if ($bruto === NULL) {
            $padrao = [];
            foreach ($this->eventos() as $slug => $meta) {
                if (!empty($meta['padrao'])) $padrao[] = $slug;
            }
            return $padrao;
        }

        $lista = [];
        foreach (explode(',', (string) $bruto) as $slug) {
            $slug = trim($slug);
            if ($slug !== '' && array_key_exists($slug, $this->eventos())) $lista[] = $slug;
        }

        return $lista;
    }

    /**
     * Usuários que podem ser escolhidos como destinatários.
     *
     * Só ATIVOS e só com e-mail preenchido: oferecer na tela alguém que a
     * rotina depois descartaria é prometer um aviso que não sai. Isso deixa de
     * fora o PROCESSOS AUTOMÁTICOS (inativo, e `noreply@`), que é o desejado.
     *
     * @return array linhas de crm_users_v
     */
    public function usuariosDisponiveis()
    {
        $linhas = $this->db->query(
            "SELECT `id`, `id_company`, `name`, `email`, `company_byname`
               FROM `crm_users_v`
              WHERE `id_status` = 1 AND `email` IS NOT NULL AND TRIM(`email`) <> ''
              ORDER BY `company_byname` ASC, `name` ASC"
        )->result();

        // `filter_var` não tem equivalente em SQL, e endereço malformado no
        // cadastro faria a fila inteira falhar no cron_enviar_email.
        return array_values(array_filter($linhas, function ($u) {
            return filter_var((string) $u->email, FILTER_VALIDATE_EMAIL) !== FALSE;
        }));
    }

    /**
     * Ids dos usuários marcados para receber o aviso.
     *
     * Guarda o **id**, nunca o e-mail: o endereço já existe no cadastro do
     * usuário, e copiá-lo para cá criaria uma segunda verdade que envelhece em
     * silêncio — quem trocasse o próprio e-mail continuaria recebendo no
     * antigo. Com o id, a troca no cadastro vale no aviso seguinte, e usuário
     * inativado ou excluído sai da lista sozinho (ver `destinatarios()`).
     *
     * Sem a chave gravada a lista é VAZIA, e isso é diferente de "não
     * configurado" no sentido do catálogo de eventos: aqui o vazio tem um
     * destino natural, que é o e-mail da empresa.
     *
     * @return int[]
     */
    public function usuariosAvisados()
    {
        $bruto = (string) $this->general_settings_model->getGroupValue(self::GRUPO, 'contratos_aviso_usuarios', '');

        $ids = [];
        foreach (explode(',', $bruto) as $id) {
            $id = (int) trim($id);
            if ($id > 0) $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Destinatários do aviso, resolvidos AGORA a partir do cadastro.
     *
     * A resolução refaz a checagem de ativo e de e-mail válido em vez de
     * confiar no que foi marcado um dia: id de usuário inativado, excluído ou
     * que ficou sem e-mail simplesmente não resolve, e sai da lista sem que
     * ninguém precise revisitar os Parâmetros gerais.
     *
     * Cascata para o e-mail da empresa quando NINGUÉM foi marcado — a mesma do
     * `Site_monitor_model::destinatariosResumo()`: num sistema recém-instalado,
     * lista vazia não pode significar "não avisar ninguém".
     *
     * **O fallback só vale para a lista não configurada.** Se havia usuários
     * marcados e a exclusão do autor esvaziou a lista, cair no e-mail da
     * empresa entregaria ao autor, por um endereço genérico, exatamente o aviso
     * que a regra mandou não lhe enviar. Por isso a exclusão acontece DEPOIS do
     * fallback e o resultado vazio é respeitado — inclusive quando o e-mail da
     * empresa é o do próprio autor.
     *
     * @param  int   $idCompany
     * @param  array $emailsExcluir endereços a remover (o autor da ação)
     * @return array
     */
    public function destinatarios($idCompany, array $emailsExcluir = [])
    {
        $ids = $this->usuariosAvisados();
        $lista = [];

        if (!empty($ids)) {
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $linhas = $this->db->query(
                "SELECT `email` FROM `crm_users`
                  WHERE `id_status` = 1 AND `id` IN (" . $placeholders . ")",
                $ids
            )->result();

            foreach ($linhas as $linha) {
                $email = trim((string) $linha->email);
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $lista[] = $email;
            }
        }

        if (empty($lista)) {
            $empresa = $this->global_model->getWhere_off('crm_companies', ['id' => (int) $idCompany], TRUE);
            if (!empty($empresa->email) && filter_var($empresa->email, FILTER_VALIDATE_EMAIL)) {
                $lista[] = (string) $empresa->email;
            }
        }

        // Comparação insensível a caixa: o cadastro do usuário e o da empresa
        // são digitados por pessoas diferentes, e "Fulano@x.com" no perfil
        // contra "fulano@x.com" na empresa burlaria a regra por causa da
        // maiúscula.
        $excluir = [];
        foreach ($emailsExcluir as $email) {
            $email = mb_strtolower(trim((string) $email));
            if ($email !== '') $excluir[$email] = TRUE;
        }

        $lista = array_filter($lista, function ($email) use ($excluir) {
            return !isset($excluir[mb_strtolower(trim($email))]);
        });

        return array_values(array_unique($lista));
    }

    /**
     * E-mail a excluir do envio: o de quem executou a ação.
     *
     * Quem acabou de suspender o contrato não precisa ser avisado de que o
     * contrato foi suspenso — ele viu a confirmação na tela.
     *
     * **Só exclui quem é autor de TODOS os eventos do e-mail**, e isso é o que
     * torna a regra segura no resumo da importação: quem executou 1 evento de
     * 50 precisa ver os outros 49, e removê-lo esconderia 49 mudanças que ele
     * não fez. Com um evento só — o caso da tela — a condição é a trivial.
     *
     * @param  array $eventos linhas de crm_contracts_history_v
     * @return array e-mails
     */
    private function autorUnico(array $eventos)
    {
        $autores = array_unique(array_map(function ($e) {
            return (int) $e->created_by;
        }, $eventos));

        if (count($autores) !== 1) return [];

        $usuario = $this->global_model->getWhere_off('crm_users', ['id' => (int) reset($autores)], TRUE);

        return (!empty($usuario->email)) ? [(string) $usuario->email] : [];
    }

    /**
     * Enfileira o aviso de UM evento.
     *
     * @param  int $idHistory
     * @return bool
     */
    public function notificar($idHistory)
    {
        $linha = $this->global_model->getWhere_off('crm_contracts_history_v', ['id' => (int) $idHistory], TRUE);

        if (empty($linha)) return FALSE;

        if (!$this->avisoAtivo() || !in_array((string) $linha->event, $this->eventosAvisados(), TRUE)) {
            return FALSE;
        }

        $assunto = $this->rotuloEvento((string) $linha->event) . ': ' . (string) $linha->contract_label;

        return $this->enviar([$linha], (int) $linha->id_company, $assunto);
    }

    /**
     * Enfileira UM resumo com vários eventos — o caminho da importação.
     *
     * Recebe os ids gravados na rodada em vez de reconsultar por "não
     * notificados": o importador roda em CLI e pode conviver com alguém
     * mexendo na tela ao mesmo tempo, e uma releitura por flag varreria para
     * dentro do resumo um evento de painel que já foi avisado sozinho. É o
     * mesmo cuidado do `Site_monitor_model` ao marcar pela lista exata de ids
     * que entrou no corpo.
     *
     * @param  array $ids
     * @param  int   $idCompany
     * @return bool
     */
    public function notificarLote(array $ids, $idCompany)
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));

        if (empty($ids) || !$this->avisoAtivo()) return FALSE;

        $avisados = $this->eventosAvisados();
        if (empty($avisados)) return FALSE;

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $linhas = $this->db->query(
            'SELECT * FROM `crm_contracts_history_v`
              WHERE `id_company` = ? AND `id` IN (' . $placeholders . ')
              ORDER BY `created` ASC, `id` ASC',
            array_merge([(int) $idCompany], $ids)
        )->result();

        $eventos = [];
        foreach ($linhas as $linha) {
            if (in_array((string) $linha->event, $avisados, TRUE)) $eventos[] = $linha;
        }

        if (empty($eventos)) return FALSE;

        $assunto = count($eventos) . ' contrato(s) tiveram o estado alterado pela importação';

        return $this->enviar($eventos, (int) $idCompany, $assunto);
    }

    /**
     * Monta o corpo, enfileira e carimba `notified` nas linhas que entraram.
     *
     * O carimbo só acontece DEPOIS de o enfileiramento ser confirmado, e pela
     * lista exata de ids que foi para o corpo: marcar antes faria o evento
     * constar avisado num e-mail que nunca existiu.
     *
     * @param  array  $eventos linhas de crm_contracts_history_v
     * @param  int    $idCompany
     * @param  string $assunto
     * @return bool
     */
    private function enviar(array $eventos, $idCompany, $assunto)
    {
        $autor = $this->autorUnico($eventos);
        $destinatarios = $this->destinatarios($idCompany, $autor);

        if (empty($destinatarios)) {
            // Os dois casos precisam ser distinguíveis no log: "ninguém
            // configurado" pede uma ação (marcar alguém em Parâmetros gerais);
            // "só sobrou o autor" é a regra funcionando, e tratar os dois com a
            // mesma linha mandaria alguém procurar um defeito que não existe.
            if (!empty($autor) && !empty($this->destinatarios($idCompany))) {
                log_message('info', '[HISTORICO] ' . count($eventos) . ' evento(s) sem e-mail a enviar — tenant '
                    . (int) $idCompany . ': o único destinatário é quem executou a ação.');
            } else {
                log_message('error', '[HISTORICO] ' . count($eventos) . ' evento(s) sem destinatário — tenant '
                    . (int) $idCompany . '. Marque os usuários em Parâmetros gerais > Contratos.');
            }

            return FALSE;
        }

        $empresa = $this->global_model->getWhere_off('crm_companies', ['id' => (int) $idCompany], TRUE);

        $corpo = $this->global_model->body_email('emails/contracts/events', [
            'title' => $assunto,
            'empresa' => !empty($empresa->byname) ? (string) $empresa->byname : '',
            'eventos' => $eventos,
            'rotulos' => $this->eventos(),
            'origens' => $this->origens(),
            'limite' => self::LIMITE_LINHAS_RESUMO,
            'url_painel' => base_url('contratos/info?id='),
        ]);

        if (!$this->global_model->send_email($assunto, $corpo, $destinatarios, [], [], NULL)) {
            log_message('error', '[HISTORICO] Falha ao enfileirar o aviso — tenant ' . (int) $idCompany
                . ', ' . count($eventos) . ' evento(s).');
            return FALSE;
        }

        $ids = array_map(function ($e) {
            return (int) $e->id;
        }, $eventos);

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $this->db->query(
            'UPDATE `crm_contracts_history` SET `notified` = ? WHERE `id` IN (' . $placeholders . ')',
            array_merge([date('Y-m-d H:i:s')], $ids)
        );

        return TRUE;
    }
}
