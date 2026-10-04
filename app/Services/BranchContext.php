<?php

namespace App\Services;

class BranchContext
{
    protected ?int $branchId = null;

    public function set(?int $branchId): void
    {
        $this->branchId = $branchId;
    }

    public function id(): ?int
    {
        return $this->branchId;
    }

    public function hasBranch(): bool
    {
        return $this->branchId !== null;
    }

    public function isAllBranches(): bool
    {
        return $this->branchId === null;
    }
}
