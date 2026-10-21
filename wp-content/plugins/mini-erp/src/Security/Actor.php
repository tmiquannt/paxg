<?php
declare(strict_types=1);

namespace MiniERP\Security;

/** Immutable identity and ERP-role capability snapshot for one request. */
final class Actor
{
    /** @param list<string> $capabilities @param list<int> $warehouseIds @param list<int> $cashAccountIds */
    public function __construct(
        public readonly int $id,
        public readonly string $role,
        private readonly array $capabilities,
        public readonly array $warehouseIds = [],
        public readonly array $cashAccountIds = []
    ) {
        $definitions = RolesInstaller::definitions();
        if ($id < 1 || !array_key_exists($role, $definitions) || !array_is_list($capabilities)) {
            throw new \InvalidArgumentException('Invalid ERP actor identity.');
        }
        $allowed = $definitions[$role]['capabilities'];
        foreach ($capabilities as $capability) {
            if (!is_string($capability) || !in_array($capability, $allowed, true)) {
                throw new \InvalidArgumentException('ERP actor capabilities do not match the assigned role.');
            }
        }
        foreach ($warehouseIds as $warehouseId) {
            if (!is_int($warehouseId) || $warehouseId < 1) {
                throw new \InvalidArgumentException('ERP actor warehouse scope is invalid.');
            }
        }
        foreach ($cashAccountIds as $cashAccountId) {
            if (!is_int($cashAccountId) || $cashAccountId < 1) {
                throw new \InvalidArgumentException('ERP actor cash-account scope is invalid.');
            }
        }
    }

    public function can(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }
}
