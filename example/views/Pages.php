<?php


require_once('DatabaseObject/widget/Content.php');
require_once('DatabaseObject/widget/Css.php');
require_once('DatabaseObject/widget/Javascript.php');
require_once('DatabaseObject/widget/Page.php');
require_once('DatabaseObject/widget/Widget.php');

class Page_normal extends Page {

  public function __construct($id=null, $title, $content=null) {

	parent::__construct($id, $title, null);

	$this->push(new CssStylesheet_from_file(null, 'styles/main.css'), 'head');

	//	$this->push(new Javascript_from_file(null, 'dhtml/sheets.js'), 'head');

	if($content)
	  $this->push($content, 'content');
  }

  public function html($sep="\n") {

	$buf = array();
	$buf[] = '<html>';

	$hdone = array();			// Hash recording for uniqueness of <head> items

	$buf[] = '<head>';
	$buf[] = '<title>'.$this->title.'</title>';

	foreach ($this->zones['head'] as $widget)
	  if(!($hdone[$fingerprint = $widget->fingerprint()])) {
		$hdone[$fingerprint] = true;
		$buf[] = $widget->html($sep);
	  }

	if($this->zones['onload']) {
	  $onload = $this->onload_js();
	  if($onload) $buf[] = $onload->html($sep);
	}

	$buf[] = '</head>';

	$buf[] = '<body>';

	$buf[] = '<div id="content">';
	$buf[] = $this->html_of_zone('content', $sep);
	$buf[] = '<div class="last">&nbsp;</div>';
	$buf[] = '</div>';

	$buf[] = '</body>';
	$buf[] = '</html>';

	if(defined('DEBUG') and DEBUG)
	  $buf[] .= "<!-- ".DatabaseHandle::get_instance()->text("\n\n").' -->';

	return join($sep, $buf);
  }
};
