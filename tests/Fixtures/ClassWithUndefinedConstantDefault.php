<?php

namespace WPDI\Tests\Fixtures;

/**
 * Class whose constructor default references a class constant that is not
 * defined in the current scope. Used to verify that Class_Inspector gracefully
 * handles the Error thrown by ReflectionParameter::getDefaultValue() and
 * returns null (signalling "fall back to reflection at runtime").
 */
class ClassWithUndefinedConstantDefault {
	// Intentionally NOT defined so that PHP throws an Error when
	// ReflectionParameter::getDefaultValue() is called.
	// const SOME_VALUE = 1;

	public function __construct( int $value = self::SOME_VALUE ) {
	}
}
