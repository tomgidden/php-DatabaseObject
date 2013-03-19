<?php

require_once('framework/DatabaseObject.php');
require_once('model/Company.php');
require_once('model/ContactDetail.php');

class Person_defn extends DatabaseObjectDefinition {
  public $tablename = 'person';
  public $classname = 'Person';
  public $idcolumns = 'person_id';
  public $autoincrement = 'person_id';

  public $links =
    array('details'=>array('nullable'=>true,
                           'classname'=>'ContactDetail',
                           'foreign_key'=>'person_id',
                           'one_to_many'=>true,
                           'limits'=>array('Person_details'=>true,
                                           'ContactDetail_person_details'=>false,
                                           'Company_people_details'=>false)),

          'employer'=>array('nullable'=>true,
                            'classname'=>'Company',
                            'foreign_key'=>'company_id',
                            'one_to_many'=>false,
                            'limits'=>array('Person_employer'=>true,
                                            'Company_employees_employer'=>false,
                                            'ContactDetail_person_employer'=>true)));

  public $columns =
    array('person_id'=>true,
          'first_name'=>true,
          'last_name'=>true,
          'company_id'=>true);
};

class Person extends DatabaseObject {
  public function name($reverse=false) {
    if($reverse)
      return $this->offsetGet('last_name').', '.$this->offsetGet('first_name');
    else
      return $this->offsetGet('first_name').' '.$this->offsetGet('last_name');
  }
};
