<?php

require_once('DatabaseObject/widget/Content.php');
require_once('DatabaseObject/example_model/Person.php');

class PersonView extends TitledContentBox {
  protected $person;

  public function __construct($id=null, Person $person) {
	$this->person = $person;
	parent::__construct($id, $person->name(), null);
  }

  public function html_of_contents($sep="\n") {
	$buf = array();
	$buf[] = '<table border="1">';

	$company = $this->person['employer'];
	$buf[] = '<tr><th>Company:</th><td><a href="?cid='.$company['company_id'].'">'.$company['name'].'</a></td></tr>';

	$details = $this->person['details'];
	foreach ($details as $key=>$detail) {
	  $buf[] = '<tr><th>'.$detail['type'].':</th><td>'.$detail['value'].'</td></tr>';
	}

	$buf[] = '</table>';

	return join($sep, $buf);
  }
};
