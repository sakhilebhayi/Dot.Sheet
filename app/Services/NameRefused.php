<?php

namespace App\Services;

use RuntimeException;

/** A name that cannot be used, with the sentence that says why. */
class NameRefused extends RuntimeException {}
