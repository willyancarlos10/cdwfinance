 <div class="row mb-2 mb-xl-2">
     <div class="col text-start">
         <h3><?php echo $result->name; ?></h3>
         <small>EMPRESA: <?php echo $crm_companies->byname; ?></small>
     </div>
     <div class="col-auto ms-auto text-end mt-n1">
         <a class="btn btn-outline-secondary" href="<?php echo base_url('empresas/info?id=' . $result->id_company); ?>"><i class="fa fa-arrow-left"></i> VOLTAR</a>
     </div>
 </div>
 <div class="tab">
     <div class="card flex-fill">
         <div class="card-body">
             <form method="POST" action="<?php echo current_url(); ?>" name="form" enctype="multipart/form-data">
                 <input type="hidden" name="id" value="<?php echo $result->id; ?>" />
                 <input type="hidden" name="url" value="<?php echo $url ?>">
                 <div class="row">
                     <div class="col-md-3">
                         <div class="form-group mb-3">
                             <label class="form-label">* Nome completo</label>
                             <input type="text" class="form-control" name="user[name]" maxlength="150" value="<?php echo $result->name; ?>" required="">
                         </div>
                     </div>
                     <div class="col-md-3">
                         <div class="form-group mb-3">
                             <label class="form-label">* E-mail</label>
                             <input type="email" class="form-control" name="user[email]" value="<?php echo $result->email; ?>">
                         </div>
                     </div>
                     <div class="col-md-3">
                         <div class="form-group mb-3">
                             <label class="form-label">Celular</label>
                             <input type="text" class="form-control phonemask" name="user[cellphone]" value="<?php echo $result->cellphone; ?>">
                         </div>
                     </div>
                     <div class="col-md-3">
                         <div class="form-group mb-3">
                             <label class="form-label">Grupo de empresas</label>
                             <select class="form-control select2" name="user[id_group]" style="width: 100%;">
                                 <option <?php if (0 == $result->id_group) echo 'selected=""'; ?> value="0">-- NÃO SE APLICA --</option>
                                 <?php foreach ($crm_user_groups as $c) { ?>
                                     <option <?php if ($c->id == $result->id_group) echo 'selected=""'; ?> value="<?php echo $c->id; ?>"><?php echo $c->name; ?></option>
                                 <?php } ?>
                             </select>
                         </div>
                     </div>
                     <div class="col-md-3">
                         <div class="form-group mb-3">
                             <label class="form-label">Permissão de acesso</label>
                             <select class="form-control select2" name="user[id_permission]" style="width: 100%;">
                                 <?php foreach ($crm_user_permissions as $c) { ?>
                                     <option <?php if ($c->id == $result->id_permission) echo 'selected=""'; ?> value="<?php echo $c->id; ?>"><?php echo $c->name; ?></option>
                                 <?php } ?>
                             </select>
                         </div>
                     </div>
                     <div class="col-12">
                         <hr>
                     </div>
                     <div class="col-md-3">
                         <div class="form-group mb-3">
                             <label class="form-label">Nova senha</label>
                             <input minlength="8" type="password" class="form-control" name="passdf">
                             <small class="text-muted">(mínimo 8 caracteres com letras e números)</small>
                         </div>
                     </div>
                     <div class="col-md-3">
                         <div class="form-group mb-3">
                             <label class="form-label">Confirmar nova senha</label>
                             <input minlength="8" type="password" class="form-control" name="passdf2">
                             <small class="text-muted">(mínimo 8 caracteres com letras e números)</small>
                         </div>
                     </div>
                     <div class="col-12">
                         <hr>
                     </div>
                 </div>
                 <div class="row">
                     <div class="col">
                         <button type="submit" class="btn w-100 btn-primary"><i class="fa fa-save"></i> SALVAR</button>
                     </div>
                     <div class="col"></div>
                     <div class="col"></div>
                 </div>
             </form>
         </div>
     </div>
 </div>