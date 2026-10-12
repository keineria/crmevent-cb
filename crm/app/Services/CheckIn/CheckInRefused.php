<?php

namespace App\Services\CheckIn;

use RuntimeException;

/**
 * A check-in the desk must not record; the message is shown to staff as is.
 */
class CheckInRefused extends RuntimeException {}
