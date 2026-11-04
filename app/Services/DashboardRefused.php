<?php

namespace App\Services;

use RuntimeException;

/** A dashboard that cannot be made from this sheet. The message says why, in words for the person. */
class DashboardRefused extends RuntimeException {}
