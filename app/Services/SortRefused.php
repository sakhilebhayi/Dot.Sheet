<?php

namespace App\Services;

use RuntimeException;

/** A sort that could not be done cleanly, in words for the person who asked. Nothing has been written. */
class SortRefused extends RuntimeException {}
