<?php

namespace App\Services;

use RuntimeException;

/** An approval step that cannot be taken. The message says why, in words for the person. */
class ApprovalRefused extends RuntimeException {}
