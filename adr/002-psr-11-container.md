# ADR-002: PSR-11 Container Interface

**Status:** Accepted

## Context

DI containers benefit from a standard interface so consumers can type-hint against an abstraction rather than a concrete container class.

## Decision

The Container implements `Psr\Container\ContainerInterface` (psr/container ^1.1). `get()` throws `Not_Found_Exception` (implements `NotFoundExceptionInterface`) and `Container_Exception` (implements `ContainerExceptionInterface`).

## Consequences

- Interoperable with any PSR-11 consuming code
- Exception hierarchy must satisfy both WPDI's own base (`WPDI_Exception`) and PSR-11 interfaces
- Locked to psr/container 1.x (2.x requires PHP 8.0+)
