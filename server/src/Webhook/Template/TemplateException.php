<?php

declare(strict_types=1);

namespace ShibbirWeb\PeepServer\Webhook\Template;

use RuntimeException;

/**
 * A template that cannot be read, e.g. "{{ mail.to " without its closing braces.
 */
final class TemplateException extends RuntimeException {}
