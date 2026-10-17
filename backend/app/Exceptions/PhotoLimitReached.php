<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The profile already holds the maximum number of photos (rule RG-06).
 */
class PhotoLimitReached extends RuntimeException {}
