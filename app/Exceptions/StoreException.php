<?php

namespace App\Exceptions;

use RuntimeException;

/** Business rule violation that is safe to show to the customer. */
class StoreException extends RuntimeException {}
