<?php

namespace App\Services;

use RuntimeException;

/** A file refused before it is loaded; the message is for the person importing it. */
class ImportRefused extends RuntimeException {}
