<?php

namespace App\Services;

class CarmenPermission
{
    const CAN_MANAGE_USERS = 'can_manage_users';
    const CAN_MANAGE_MASTER_DATA = 'can_manage_master_data';
    const CAN_INPUT_OPERATIONAL = 'can_input_operational';
    const CAN_SUBMIT_APPROVAL = 'can_submit_approval';
    const CAN_REVIEW_APPROVAL = 'can_review_approval';
    const CAN_VIEW_ALL_APPROVALS = 'can_view_all_approvals';
    const CAN_VIEW_OWN_APPROVALS = 'can_view_own_approvals';
    const CAN_MANAGE_SMTP = 'can_manage_smtp';

    /**
     * Canonical capabilities per role matching backend/permissions.py
     */
    public static array $roleCapabilities = [
        'admin' => [
            self::CAN_MANAGE_USERS,
            self::CAN_MANAGE_MASTER_DATA,
            self::CAN_INPUT_OPERATIONAL,
            self::CAN_SUBMIT_APPROVAL,
            self::CAN_VIEW_ALL_APPROVALS,
            self::CAN_VIEW_OWN_APPROVALS,
            self::CAN_MANAGE_SMTP,
        ],
        'staff' => [
            self::CAN_MANAGE_MASTER_DATA,
            self::CAN_INPUT_OPERATIONAL,
            self::CAN_SUBMIT_APPROVAL,
            self::CAN_VIEW_OWN_APPROVALS,
            self::CAN_MANAGE_SMTP,
        ],
        'approver' => [
            self::CAN_REVIEW_APPROVAL,
        ],
        'vendor' => [],
        'user' => [],
    ];

    /**
     * Check if a given role has the capability.
     */
    public static function hasCapability(?string $role, string $capability): bool
    {
        $role = strtolower(trim((string)$role));
        $caps = self::$roleCapabilities[$role] ?? [];
        return in_array($capability, $caps, true);
    }
}
