<?php

declare( strict_types = 1 );

namespace WPDI\Exceptions;

use Psr\Container\NotFoundExceptionInterface;
use Exception;

class Not_Found_Exception extends Exception implements NotFoundExceptionInterface {

}