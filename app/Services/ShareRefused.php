<?php

namespace App\Services;

use RuntimeException;

/** A sharing change that cannot be made, with the sentence that says why. */
class ShareRefused extends RuntimeException {}
