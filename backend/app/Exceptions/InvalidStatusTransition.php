<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The requested status change is not allowed from the current status.
 */
class InvalidStatusTransition extends RuntimeException {}
