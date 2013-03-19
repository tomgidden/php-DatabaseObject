<?php

require_once('DatabaseObject/widget/Content.php');
require_once('DatabaseObject/example_model/Company.php');

class CompanyView extends TitledContentBox {
  protected $company;

  public function __construct($id=null, Company $company) {
	$this->company = $company;
	parent::__construct($id, $company['name'], null);
  }

  public function html_of_contents($sep="\n") {
	$buf = array();
	$buf[] = '<table border="1">';

	$buf[] = '<tr><th>Name:</th><td>'.$this->company['name'].'</td></tr>';
	$buf[] = '<tr><th>Employees:</th><td>';

	$people = $this->company['employees'];
	foreach ($people as $key=>$person) {
	  $buf[] = '<a href="?pid='.$person['person_id'].'">'.$person['last_name'].', '.$person['first_name'].'</a><br/>';
	}

	$buf[] = '</td></tr>';

	$buf[] = '</table>';

	return join($sep, $buf);
  }
};
