<?php

namespace App\Services\Suppliers\Exceptions;

/** Order request result is unknown (timeout / server error). Must be reviewed manually before retrying elsewhere. */
class SupplierUnknownResultException extends SupplierException {}
