<?php

namespace App\Services;

use RuntimeException;

/**
 * Thrown inside a write transaction when rows or columns moved (or the tab went away) after the
 * writer loaded its grid. The message is meant to be shown as is.
 */
class StaleStructure extends RuntimeException {}
