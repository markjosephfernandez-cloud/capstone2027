<?php

namespace App\Libraries;

/** Holds the authenticated actor for one HTTP request. */
class AuthContext
{
    /** @var array<string, mixed>|null */
    private ?array $actor = null;

    /** @param array<string, mixed> $actor */
    public function setActor(array $actor): void
    {
        $this->actor = $actor;
    }

    /** @return array<string, mixed>|null */
    public function actor(): ?array
    {
        return $this->actor;
    }

    public function clear(): void
    {
        $this->actor = null;
    }
}
