<?php
  /* HTMLSerialisable model
   * by Tom Gidden <tom@gidden.net>
   * Copyright (C) Tom Gidden, 2009
   */

interface HTMLSerialisable {
  // Objects that implement this interface can be serialised as
  // HTML

  // Returns a HTML representation of the object, given a separator
  // (eg. newline)
  public function html($sep="");
};
