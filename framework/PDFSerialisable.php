<?php
  /* PDFSerialisable model
   * by Tom Gidden <tom@gidden.net>
   * Copyright (C) Tom Gidden, 2009
   */

interface PDFSerialisable {
  // Objects that implement this interface can be serialised as
  // PDF

  // Adds a PDF representation of the object to the given PDF handle.
  public function pdf($pdf);
};
