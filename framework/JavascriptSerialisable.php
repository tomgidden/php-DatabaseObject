<?php
  /* JavascriptSerialisable model
   * by Tom Gidden <tom@gidden.net>
   * Copyright (C) Tom Gidden, 2009
   */

interface JavascriptSerialisable {
  // Objects that implement this interface can be serialised as
  // Javascript.

  // Returns a Javascript representation of the object, given a separator
  // (eg. newline)
  public function js($sep="");
};
