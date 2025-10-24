<?php
/**
 * PSR-11 Not Found Exception
 *
 * Thrown when a service is not found in the container.
 * Extends Container_Exception and implements PSR-11 NotFoundExceptionInterface.
 */

declare( strict_types = 1 );

namespace WPDI\Exceptions;

use Psr\Container\NotFoundExceptionInterface;

class Not_Found_Exception extends Container_Exception implements NotFoundExceptionInterface { }
