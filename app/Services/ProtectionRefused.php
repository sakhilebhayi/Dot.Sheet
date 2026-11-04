<?php

namespace App\Services;

use RuntimeException;

/** A change that protection stops. The message says why, in words for the person. */
class ProtectionRefused extends RuntimeException {}
