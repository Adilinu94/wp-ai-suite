<?php

declare(strict_types=1);

namespace WPAiSuite\Core\Container;

/**
 * Bewusst minimaler Service-Container: registrierte Factory-Closures werden lazy aufgeloest und
 * memoisiert. Kein Auto-Wiring/Reflection-Magie — das waere fuer Phase 1 mehr Architektur als
 * noetig (Regel 2: "einfachste Loesung, die den Contract erfuellt"). Existiert, weil Plugin.php
 * bereits seit M0 ankuendigt: "Ab M1 werden hier Provider-, Knowledge-, Tool- und
 * Security-Services im DI-Container (Core/Container) verdrahtet" und der Ordner
 * src/Core/Container/ bereits als M0-Platzhalter angelegt ist.
 */
final class Container
{
    /** @var array<string, callable(self): mixed> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @param callable(self): mixed $factory */
    public function set(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function get(string $id): mixed
    {
        if (!array_key_exists($id, $this->instances)) {
            if (!isset($this->factories[$id])) {
                throw new \RuntimeException(sprintf("Kein Service fuer '%s' registriert.", $id));
            }

            $this->instances[$id] = ($this->factories[$id])($this);
        }

        return $this->instances[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->instances);
    }
}
