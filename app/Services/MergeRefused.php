<?php

namespace App\Services;

use RuntimeException;

/** A merge that cannot be made, in words for the person who asked. Nothing has been written. */
class MergeRefused extends RuntimeException {}
