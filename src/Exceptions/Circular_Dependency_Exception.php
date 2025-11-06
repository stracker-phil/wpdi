<?php
/**
 * Circular Dependency Exception
 *
 * Thrown when the container detects a circular dependency during autowiring.
 * This indicates a design flaw where ClassA depends on ClassB, which depends
 * back on ClassA (directly or through a chain).
 *
 * How to fix:
 * - Extract shared logic into a third service both classes can depend on
 * - Use WordPress hooks for event-driven communication
 * - Redesign class responsibilities to remove tight coupling
 *
 * Example message: "Circular dependency detected: ServiceA -> ServiceB -> ServiceA"
 */

declare( strict_types = 1 );

namespace WPDI\Exceptions;

class Circular_Dependency_Exception extends Container_Exception { }
