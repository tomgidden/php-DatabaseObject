<?php

require_once('framework/DatabaseObject.php');
require_once('model/Person.php');

class Company_defn extends DatabaseObjectDefinition {
  public $tablename = 'company';
  public $classname = 'Company';
  public $idcolumns = 'company_id';
  public $links =
    array('employees'=>array('nullable'=>true,
                             'classname'=>'Person',
                             'foreign_key'=>'company_id',
                             'one_to_many'=>true,
                             'limits'=>array('Person_company_employees'=>false,
                                             'Company_employees'=>true)));

  public $columns =
    array('company_id'=>true,
          'company_name'=>'name');
};

class Company extends DatabaseObject {
};
