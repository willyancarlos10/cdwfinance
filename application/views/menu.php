<div class="wrapper">
  <nav id="sidebar" class="sidebar <?php echo $this->session->userdata('preference_sidebar'); ?>">
    <div class="sidebar-content js-simplebar">
      <a class="sidebar-brand pb-1 pt-1" href="<?php echo base_url(); ?>">
        <img class="w-100 mt-1 mb-1 p-4 pb-1 pt-1" alt="<?php echo $this->config->item('company'); ?>" src="<?php echo base_url('theme/custom/images/logo_sidebar.png'); ?>" />
      </a>
      <ul class="sidebar-nav">
        <?php $is_master = !empty($is_master_company) ? $is_master_company : ((int) $this->session->userdata('company')->id === 1); ?>
        <?php if ($this->session->userdata('showChangeCompany')) { ?>
          <li class="sidebar-headerp-0">
            <hr style="margin-top: 6px; color: #fff;">
          </li>
          <li class="sidebar-header pt-1 pb-1">EMPRESA</li>
          <li class="sidebar-header pt-1">
            <form method="POST" id="form_companies" name="form" action="<?php echo base_url('painel/painel_filtrar'); ?>" enctype="multipart/form-data">
              <input type="hidden" name="url" value="<?php echo current_url(); ?>" />
              <select id="id_companies" name="f_company[id_company]" class="form-control select2_companies">
                <?php echo $this->session->userdata('f_company')['select2_companies']; ?>
              </select>
            </form>
          </li>
          <?php if ($is_master) { ?>
            <li class="sidebar-header pt-1 pb-0 text-truncate" title="Último acesso desta empresa">
              <i class="mdi mdi-clock-outline align-middle"></i>
              <span class="align-middle">Último acesso: <?php echo !empty($company_last_login) ? data($company_last_login) : 'Nunca acessou'; ?></span>
            </li>
          <?php } ?>
        <?php } ?>
        <li class="sidebar-header pt-3 pb-1">MENU PRINCIPAL</li>
        <li class="sidebar-item <?php if ($menu == 'dashboard') echo 'active'; ?>">
          <a class="sidebar-link" href="<?php echo base_url(); ?>">
            <i class="mdi mdi-monitor-dashboard"></i> <span class="align-middle">Dashboard</span>
          </a>
        </li>
        <?php $menu_reference = array('clientes'); ?>
        <li class="sidebar-item <?php if (in_array($menu, $menu_reference)) echo 'active'; ?>">
          <a class="sidebar-link" href="<?php echo base_url('clientes'); ?>">
            <i class="mdi mdi-account-tie"></i> <span class="align-middle">Clientes</span>
          </a>
        </li>
        <?php $menu_reference = array('servidores'); ?>
        <li class="sidebar-item <?php if (in_array($menu, $menu_reference)) echo 'active'; ?>">
          <a class="sidebar-link" href="<?php echo base_url('servidores'); ?>">
            <i class="mdi mdi-server"></i> <span class="align-middle">Servidores</span>
          </a>
        </li>
        <?php $menu_reference = array('servidores/dominios'); ?>
        <li class="sidebar-item <?php if (in_array($menu, $menu_reference)) echo 'active'; ?>">
          <a class="sidebar-link" href="<?php echo base_url('servidores/dominios'); ?>">
            <i class="mdi mdi-domain"></i> <span class="align-middle">Domínios</span>
          </a>
        </li>
        <li class="sidebar-header pt-1 pb-1">CONFIGURAÇÕES</li>
        <?php $menu_reference = array('usuarios'); ?>
        <li class="sidebar-item <?php if (in_array($menu, $menu_reference)) echo 'active'; ?>">
          <a class="sidebar-link destaque_pedidos" href="<?php echo base_url('usuarios'); ?>">
            <i class="mdi mdi-account-multiple"></i> <span class="align-middle">Usuários</span>
          </a>
        </li>
        <?php if ($this->session->userdata('company')->id == 1) { ?>
          <?php $menu_reference = array('permissoes'); ?>
          <li class="sidebar-item <?php if (in_array($menu, $menu_reference)) echo 'active'; ?>">
            <a class="sidebar-link" href="<?php echo base_url('permissoes'); ?>">
              <i class="mdi mdi-account-lock"></i> <span class="align-middle">Permissões</span>
            </a>
          </li>
        <?php }
        if ($this->session->userdata('company')->id == 1) { ?>
          <li class="sidebar-header pt-2 pb-1">GESTÃO</li>
          <?php $menu_reference = array('empresas'); ?>
          <li class="sidebar-item <?php if (in_array($menu, $menu_reference)) echo 'active'; ?>">
            <a class="sidebar-link destaque_pedidos" href="<?php echo base_url('empresas'); ?>">
              <i class="mdi mdi-office-building"></i> <span class="align-middle">Empresas</span>
            </a>
          </li>
          <?php $menu_reference = array('empresas/grupos'); ?>
          <li class="sidebar-item <?php if (in_array($menu, $menu_reference)) echo 'active'; ?>">
            <a class="sidebar-link destaque_pedidos" href="<?php echo base_url('empresas/grupos'); ?>">
              <i class="mdi mdi-account-group"></i> <span class="align-middle">Grupos de empresas</span>
            </a>
          </li>
          <?php $menu_reference = array('tipos-servicos'); ?>
          <li class="sidebar-item <?php if (in_array($menu, $menu_reference)) echo 'active'; ?>">
            <a class="sidebar-link destaque_pedidos" href="<?php echo base_url('tipos-servicos'); ?>">
              <i class="mdi mdi-tag-multiple"></i> <span class="align-middle">Tipos de serviços</span>
            </a>
          </li>
          <?php $menu_reference = array('parametros_gerais'); ?>
          <li class="sidebar-item <?php if (in_array($menu, $menu_reference)) echo 'active'; ?>">
            <a class="sidebar-link destaque_pedidos" href="<?php echo base_url('parametros_gerais'); ?>">
              <i class="mdi mdi-cog-outline"></i> <span class="align-middle">Parâmetros gerais</span>
            </a>
          </li>
          <li class="sidebar-item">
            <a class="sidebar-link" target="_blank" href="<?php echo base_url('api/docs'); ?>">
              <i class="mdi mdi-key"></i> <span class="align-middle">API Docs</span>
            </a>
          </li>
          <?php $menu_reference = array('painel/cron'); ?>
          <li class="sidebar-item <?php if (in_array($menu, $menu_reference)) echo 'active'; ?>">
            <a class="sidebar-link" href="<?php echo base_url('painel/cron'); ?>">
              <i class="mdi mdi-clock-outline"></i> <span class="align-middle">Cron</span>
            </a>
          </li>
        <?php } ?>
      </ul>
    </div>
  </nav>
  <div class="main">
    <nav class="navbar navbar-expand navbar-light navbar-bg">
      <a class="sidebar-toggle">
        <i class="hamburger align-self-center"></i>
      </a>
      <div class="d-sm-none">
        <a href="<?php echo base_url(); ?>"><img class="w-auto" alt="<?php echo $this->config->item('company'); ?>" style="height:50px;" src="<?php echo base_url('theme/custom/images/logo_sidebar.png'); ?>" /></a>
      </div>
      <div class="d-none d-sm-block"><span class="fs-5 badge ml-1" style="color: #333;"><?php echo $this->session->userdata('company')->name; ?></span></div>
      <?php if ($this->session->userdata('company')->id == 1) { ?>
        <ul class="navbar-nav">
          <li class="nav-item px-2 dropdown d-none d-sm-block">
            <a class="nav-link dropdown-toggle" href="#" id="servicesDropdown" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
              Acesso rápido
            </a>
            <div class="dropdown-menu dropdown-menu-start dropdown-mega" aria-labelledby="servicesDropdown">
              <div class="d-md-flex align-items-start justify-content-start">
                <div class="dropdown-mega-list">
                  <a class="dropdown-item" href="<?php echo base_url('usuarios'); ?>">Usuários</a>
                  <a class="dropdown-item" href="<?php echo base_url('empresas'); ?>">Empresas</a>
                </div>
              </div>
            </div>
          </li>
        </ul>
      <?php } ?>

      <div class="navbar-collapse collapse">
        <ul class="navbar-nav navbar-align">
          <li class="nav-item dropdown">
            <a class="nav-icon dropdown-toggle" href="#" id="alertsDropdown" data-bs-toggle="dropdown">
              <div class="position-relative">
                <?php if ($total_tasks == 0) { ?>
                  <i class="mdi mdi-bell-check"></i>
                <?php } else { ?>
                  <i class="mdi mdi-bell-ring"></i>
                  <span class="indicator" style="background: #e30e0e;"><?php echo $total_tasks; ?></span>
                <?php } ?>
              </div>
            </a>
            <div class="dropdown-menu dropdown-menu-lg dropdown-menu-end py-0" aria-labelledby="alertsDropdown">
              <div class="dropdown-menu-header">
                <?php echo $total_tasks; ?> Central de Notificações
              </div>
              <div class="list-group">
                <?php
                // Cada rotina do financeiro que precise notificar acrescenta o
                // próprio <a class="list-group-item"> aqui e soma o contador em
                // $this->data['total_tasks'] (application/core/MY_Controller.php).
                ?>
                <?php if ($pending_header > 0) { ?>
                  <a href="<?php echo base_url('painel/minha_conta#tabs3'); ?>" class="list-group-item">
                    <div class="row g-0 align-items-center">
                      <div class="col-2">
                        <i class="text-danger mdi mdi-account-credit-card fs-3"></i>
                      </div>
                      <div class="col-10">
                        <div class="text-dark">(<?php echo $pending_header; ?>) fatura em aberto.</div>
                        <div class="text-muted small mt-0">Você possui algumas pendências.</div>
                      </div>
                    </div>
                  </a>
                <?php } ?>
                <?php if ($total_tasks == 0) { ?>
                  <a href="#" class="list-group-item">
                    <div class="row g-0 align-items-center">
                      <div class="col-2">
                        <i class="text-success mdi mdi-check-circle fs-3"></i>
                      </div>
                      <div class="col-10">
                        <div class="text-dark">Tudo em dia!</div>
                        <div class="text-muted small mt-0">Você não possui notificações aqui.</div>
                      </div>
                    </div>
                  </a>
                <?php } ?>
              </div>
            </div>
          </li>
          <li class="nav-item dropdown">
            <a class="nav-icon dropdown-toggle d-inline-block d-sm-none" href="#" data-bs-toggle="dropdown">
              <i class="align-middle" data-feather="settings"></i>
            </a>
            <a class="nav-link dropdown-toggle d-none d-sm-inline-block" href="#" data-bs-toggle="dropdown">
              <img src="<?php echo $this->session->userdata('user_image'); ?>" class="avatar img-fluid rounded-circle me-1" alt="<?php echo $this->session->userdata('user_first_name'); ?>" />
              <span class="text-darks"><?php echo $this->session->userdata('user_first_name'); ?></span>
            </a>
            <div class="dropdown-menu dropdown-menu-end">
              <a class="dropdown-item" href="<?php echo base_url('painel/minha_conta'); ?>">Minha conta</a>
              <?php if ($this->session->userdata('company')->id == 1) { ?>
                <a class="dropdown-item" href="<?php echo base_url('painel/cron'); ?>">Indicador CRON</a>
              <?php } ?>
              <div class="dropdown-divider"></div>
              <a class="dropdown-item" href="<?php echo base_url('painel/sair'); ?>"><i class="align-middle me-1" data-feather="log-out"></i> Sair </a>
            </div>
          </li>
        </ul>
      </div>
    </nav>
    <main class="content p-4">
      <div class="container-fluid p-0">
