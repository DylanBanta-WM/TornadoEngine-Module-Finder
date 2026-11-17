<?php

namespace TornadoEngine\ModuleFinder;

/**
 * Buttons other modules add to each module on /modules (for example GitHub's Publish).
 * Each view gets $listing and decides itself whether to show anything.
 */
class ModuleActions
{
    /** @var list<string> */
    private array $views = [];

    public function add(string $view): static
    {
        $this->views[] = $view;

        return $this;
    }

    /** @return list<string> */
    public function views(): array
    {
        return $this->views;
    }
}
