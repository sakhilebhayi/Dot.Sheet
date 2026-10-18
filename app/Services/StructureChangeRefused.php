<?php

namespace App\Services;

use RuntimeException;

/**
 * An insert that would push data off the edge of the sheet. The message is meant to be shown as is.
 */
class StructureChangeRefused extends RuntimeException {}
