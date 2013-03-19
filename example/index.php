<?php

define('DEBUG', true);
define('TIMINGS', true);

require_once ('DatabaseObject/common/start.php');

require_once ('DatabaseObject/model/Person.php');

header('Content-type: text/plain');

# /index.php?newname=Tom
if(array_key_exists('newname', $_REQUEST) and $newname = $_REQUEST['newname']) {
  $person = DatabaseObject::get_one_by_criteria('Person', array('last_name=?'=>'Gidden'));
  $person['first_name'] = $newname;
  $person->save();
}


# /index.php?pid=9
if(array_key_exists('pid', $_REQUEST) and
   $person_id = $_REQUEST['pid'] and
   ($person = DatabaseObject::get_by_id('Person', $person_id)) ) {

   var_export($person);
}

# /index.php?cid=1
else if(array_key_exists('cid', $_REQUEST) and
        ($company_id = $_REQUEST['cid']) and
        ($company = DatabaseObject::get_by_id('Company', $company_id))) {

   var_export($person);
}

else {
   $people = DatabaseObject::get_by_criteria('Person', array());
   var_export($person);
}

require_once ('DatabaseObject/common/end.php');
