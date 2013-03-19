<?php

require_once('framework/DatabaseObject.php');
require_once('model/Person.php');

class ContactDetail_defn extends DatabaseObjectDefinition {
  public $tablename = 'contact_detail';
  public $classname = 'ContactDetail';
  public $idcolumns = array('person_id', 'type');

  public $links =
    array('person'=>array('nullable'=>false,
                          'classname'=>'Person',
                          'foreign_key'=>'person_id',
                          'one_to_many'=>false,
                          'limits'=>array('ContactDetail_person'=>true,
                                          'Person_details_person'=>false,
                                          'Company_people_details_person'=>false)));

  public $columns =
    array('person_id'=>true,
          'contact_type'=>'type',
          'value'=>true);
};

class ContactDetail extends DatabaseObject {
};
