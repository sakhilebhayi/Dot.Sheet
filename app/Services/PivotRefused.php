<?php

namespace App\Services;

use RuntimeException;

/** A pivot table that cannot be made, with the sentence that says why. */
class PivotRefused extends RuntimeException {}
