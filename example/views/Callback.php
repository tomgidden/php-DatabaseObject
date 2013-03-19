<?php

require_once('DatabaseObject/widget/Javascript.php');
require_once('DatabaseObject/widget/Page.php');


class Callback extends Widget {
  protected $js_widget;

  public function __construct($cb, $obj_js) {
	$this->js_widget = new Javascript_from_string(null, "var obj=$obj_js;if(parent!=null&&parent.$cb)parent.$cb(obj);else if(typeof($cb)!='undefined')$cb(obj);");
	parent::__construct();
  }

  public function html($sep="\n") {
	if($this->container)
	  return $this->js_widget->html($sep);
	else {
#	  	  $p = new Page(null, null, null, $content);
#	  	  $p->push($this->js_widget);
#	  	  return $p->html($sep);
	  return $this->js_widget->html($sep);
	}
  }

  public function js($sep="\n") {
	return $this->js_widget->js($sep);
  }
}
