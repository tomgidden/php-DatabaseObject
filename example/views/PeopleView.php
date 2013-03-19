<?php

require_once('DatabaseObject/widget/Content.php');
require_once('DatabaseObject/example_model/Person.php');

class PeopleView extends TitledContentBox {
  protected $people;

  public function __construct($id=null, $people) {
	$this->people = $people;
	parent::__construct($id, "Address Book", null);
  }

  public function html_of_contents($sep="\n") {
	$buf = array();
	$buf[] = '<table border="1">';

	$buf[] = '<tr><th align="center">Name</th><th align="center">Company</th></tr>';

	foreach ($this->people as $key=>$person) {
	  $buf[] = '<tr><td>';
	  $buf[] = '<a href="?pid='.$person['person_id'].'">'.$person->name(false).'</a>';
	  $buf[] = '</td><td>';
	  $buf[] = '<a href="?cid='.$person['company_id'].'">'.$person['employer']['name'].'</a>';
	  $buf[] = '</td></tr>';
	}

	$buf[] = '</table>';

	return join($sep, $buf);
  }
};
