<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The text reads as a personal attack. The author has to confirm before it posts.
 */
class NudgeRequired extends RuntimeException {}
