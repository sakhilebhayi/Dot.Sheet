<?php

namespace App\Services;

use RuntimeException;

/** A chart that cannot be made, with the sentence that says why. */
class ChartRefused extends RuntimeException {}
