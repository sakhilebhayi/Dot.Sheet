<?php

namespace App\Services;

use RuntimeException;

/** A duplicate that cannot be made. The message says why, in words for the person. */
class DuplicateRefused extends RuntimeException {}
