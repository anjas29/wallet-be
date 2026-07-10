<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a refresh token is invalid, revoked, or expired. Rendered as a 401 envelope.
 */
class RefreshTokenException extends RuntimeException {}
