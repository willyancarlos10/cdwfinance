<?php
$tabs_default = 'tabs1';
if (!empty($_GET['page_default'])) $tabs_default = $_GET['page_default'];
?>
<div class="row mb-2 mb-xl-2">
  <div class="col-auto d-none d-sm-block">
    <h1 class="h3 mb-3">Minha Conta</h1>
  </div>
</div>
<div class="row">
  <div class="col-md-4 col-xl-3">
    <div class="card mb-3">
      <div class="card-body text-center">
        <img src="<?php echo $this->session->userdata('user_image'); ?>" alt="<?php echo $this->session->userdata('user_first_name'); ?>" class="img-fluid rounded-circle mb-2" width="128" height="128" />
        <h5 class="card-title mb-0"><?php echo $this->session->userdata('user_first_name'); ?></h5>
        <div class="text-muted mb-2"><?php echo $this->session->userdata('company')->name; ?></div>
      </div>
      <hr class="my-0" />
    </div>
  </div>
  <div class="col-md-8 col-xl-9">
    <div class="card">
      <div class="card-body pt-2">
        <ul class="nav nav-pills mt-2">
          <li class="nav-item"><a class="nav-link tabs1 <?php if ($tabs_default == 'tabs1') echo 'active'; ?>" href="#tabs1" data-bs-toggle="tab" role="tab" aria-selected="true">Minha conta</a></li>
          <li class="nav-item"><a class="nav-link tabs2 <?php if ($tabs_default == 'tabs2') echo 'active'; ?>" href="#tabs2" data-bs-toggle="tab" role="tab" aria-selected="true">Dados cadastrais</a></li>
        </ul>
        <div class="tab-content" style="padding: 20px 0 0 0; box-shadow: none;">
          <div class="tab-pane <?php if ($tabs_default == 'tabs1') echo 'active'; ?>" id="tabs1" role="tabpanel">
            <form method="POST" action="<?php echo current_url(); ?>" name="form" enctype="multipart/form-data">
              <input type="hidden" name="id" value="<?php echo $result->id; ?>" />
              <div class="row">
                <div class="col-md-4">
                  <div class="form-floating mb-3">
                    <input type="text" class="form-control" name="name" value="<?php echo $result->name; ?>" required="">
                    <label class="form-label">* Nome</label>
                  </div>
                </div>
                <div class="col-md-4">
                  <div class="form-floating mb-3">
                    <input type="email" class="form-control" name="email" value="<?php echo $result->email; ?>" required="">
                    <label class="form-label">* E-mail</label>
                  </div>
                </div>
                <div class="col-md-4">
                  <div class="form-floating mb-3">
                    <input type="text" class="form-control" name="cellphone" data-mask="(00) 00000-0000" minlength="11" value="<?php echo $result->cellphone; ?>">
                    <label class="form-label">Telefone celular</label>
                  </div>
                </div>
                <div class="col-md-12"></div>
                <div class="col-md-8">
                  <div class="form-group mb-3">
                    <label for="formFile" class="form-label">Foto</label>
                    <input class="form-control" type="file" name="foto" accept=".jpg,.png,.jpeg,.jfif"><br />
                  </div>
                </div>
                <div class="col-md-12"></div>
                <div class="col-md-4">
                  <div class="form-floating">
                    <input type="password" class="form-control" name="passw1" placeholder="Nova senha">
                    <label>Nova senha</label>
                  </div>
                </div>
                <div class="col-md-4">
                  <div class="form-floating">
                    <input type="password" class="form-control" name="passw2" placeholder="Confirmar nova senha">
                    <label>Confirmar nova senha</label>
                  </div>
                </div>
                <div class="mb-3 col-md-12">
                  <span class="font-13 text-danger"><small>A nova senha deverá possuir 8 dígitos com letras e números</small></span>
                </div>
                <div class="col-md-12">
                  <hr />
                </div>
                <div class="row">
                  <div class="col-12 col-sm-4">
                    <button type="submit" class="btn w-100 btn-primary"><i class="mdi mdi-content-save"></i> SALVAR</button>
                  </div>
                  <div class="col-12 col-sm-8 mb-2 mb-sm-0"></div>
                  <div class="col-12 col-sm-2">
                  </div>
                </div>
              </div>
            </form>
          </div>
          <div class="tab-pane <?php if ($tabs_default == 'tabs2') echo 'active'; ?>" id="tabs2" role="tabpanel">
            <div class="row">
              <div class="col-md-12">
                <h5 class="card-title mb-2">Dados cadastrais</h5>
              </div>
              <div class="col-md-4">
                <div class="form-floating mb-3">
                  <input type="text" class="form-control" name="cnpj" value="<?php echo $company->cnpj; ?>" readonly="" required="">
                  <label class="form-label">* CNPJ</label>
                </div>
              </div>
              <div class="mb-3 col-md-4">
                <div class="form-floating">
                  <input type="text" class="form-control" name="name" value="<?php echo $company->name; ?>" readonly="" required="" maxlength="100">
                  <label class="form-label">* Razão Social</label>
                </div>
              </div>
              <div class="mb-3 col-md-4">
                <div class="form-floating">
                  <input type="text" class="form-control" name="byname" value="<?php echo $company->byname; ?>" readonly="" required="" maxlength="50">
                  <label class="form-label">* Nome Fantasia</label>
                </div>
              </div>
            </div>
            <div class="row">
              <div class="col-md-12">
                <h5 class="card-title mb-2">Endereço</h5>
              </div>
              <div class="mb-3 col-md-3">
                <div class="form-floating">
                  <input type="text" class="form-control" name="address_zip" id="address_zip" data-mask="00.000-000" value="<?php echo $company->address_zip; ?>" required="" readonly="">
                  <label class="form-label">* CEP</label>
                </div>
              </div>
              <div class="mb-3 col-md-6">
                <div class="form-floating">
                  <input type="text" class="form-control" name="address" id="address" value="<?php echo $company->address; ?>" required="" readonly="">
                  <label class="form-label">* Endereço</label>
                </div>
              </div>
              <div class="mb-3 col-md-3">
                <div class="form-floating">
                  <input type="text" class="form-control" name="address_number" id="address_number" value="<?php echo $company->address_number; ?>" required="" readonly="">
                  <label class="form-label">* Número</label>
                </div>
              </div>
              <div class="mb-3 col-md-6">
                <div class="form-floating">
                  <input type="text" class="form-control" name="address_district" id="address_district" value="<?php echo $company->address_district; ?>" required="" readonly="">
                  <label class="form-label">* Bairro</label>
                </div>
              </div>
              <div class="mb-3 col-md-6">
                <div class="form-floating">
                  <input type="text" class="form-control" name="address_complement" id="address_complement" value="<?php echo $company->address_complement; ?>" readonly="">
                  <label class="form-label">Complemento</label>
                </div>
              </div>
              <div class="mb-3 col-md-6">
                <div class="form-floating">
                  <select disabled class="form-select">
                    <?php foreach ($states as $c) { ?>
                      <option value="<?php echo $c->id; ?>" <?php if ($c->id == $company->id_state) echo 'selected=""'; ?>><?php echo $c->name; ?></option>
                    <?php } ?>
                  </select>
                  <label class="form-label">Estado</label>
                </div>
              </div>
              <div class="mb-3 col-md-6">
                <div class="form-floating">
                  <select disabled class="form-select">
                    <?php foreach ($cities as $c) { ?>
                      <option value="<?php echo $c->id; ?>" <?php if ($c->id == $company->id_city) echo 'selected=""'; ?>><?php echo $c->name; ?></option>
                    <?php } ?>
                  </select>
                  <label class="form-label">Cidade</label>
                </div>
              </div>
            </div>
            <div class="row">
              <div class="col-md-12">
                <div class="alert alert-info alert-dismissible" role="alert">
                  <button type="button" class="btn-close d-none" data-bs-dismiss="alert" aria-label="Close"></button>
                  <div class="alert-message">
                    Prezado, caso seu cadastro possua alguma irregularidade entre em contato conosco.
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>