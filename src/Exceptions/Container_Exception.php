<?php
/**
 * PSR-11 Container Exception.
 *
 * Base exception for all container-related errors.
 * Extends WPDI_Exception and implements PSR-11 ContainerExceptionInterface.
 *
 * @package WPDI\Exceptions
 */

declare( strict_types = 1 );

namespace WPDI\Exceptions;

use Psr\Container\ContainerExceptionInterface;

/** PSR-11 container exception for all container-related errors. */
class Container_Exception extends WPDI_Exception implements ContainerExceptionInterface { }
