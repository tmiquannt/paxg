<?php
declare(strict_types=1);

namespace MiniERP\Domain;

use DomainException;
use MiniERP\Security\Actor;

/** Pure purchase-order transitions. Receipt and inventory effects are persisted atomically by a service. */
final class PurchaseOrderStateMachine
{
    public const DRAFT = 'DRAFT';
    public const PENDING_APPROVAL = 'PENDING_APPROVAL';
    public const APPROVED = 'APPROVED';
    public const REJECTED = 'REJECTED';
    public const RECEIVED = 'RECEIVED';
    public const PARTIALLY_RECEIVED = 'PARTIALLY_RECEIVED';
    public const CANCELLED = 'CANCELLED';
    public const CLOSED = 'CLOSED';

    public function transition(Actor $actor, string $status, string $action, int $creatorId, int $warehouseId, bool $allLinesReceived = true): string
    {
        return match ($action) {
            'submit' => $this->submit($actor, $status),
            'approve' => $this->approve($actor, $status, $creatorId),
            'reject' => $this->reject($actor, $status, $creatorId),
            'revise' => $this->revise($actor, $status, $creatorId),
            'receive' => $this->receive($actor, $status, $warehouseId, $allLinesReceived),
            'cancel' => $this->cancel($actor, $status),
            default => throw new DomainException('Hành động đơn mua không hợp lệ.'),
        };
    }

    private function submit(Actor $actor, string $status): string
    {
        $this->expect($status === self::DRAFT && $actor->can('erp_purchases_create'), 'Bạn không thể gửi đơn mua này.');
        return self::PENDING_APPROVAL;
    }

    private function approve(Actor $actor, string $status, int $creatorId): string
    {
        $this->expect($status === self::PENDING_APPROVAL, 'Đơn mua không ở trạng thái chờ duyệt.');
        $this->expect($actor->can('erp_purchases_approve') && $creatorId !== $actor->id, 'Manager duyệt phải khác người tạo đơn.');
        return self::APPROVED;
    }

    private function reject(Actor $actor, string $status, int $creatorId): string
    {
        $this->expect($status === self::PENDING_APPROVAL, 'Đơn mua không ở trạng thái chờ duyệt.');
        $this->expect($actor->can('erp_purchases_approve') && $creatorId !== $actor->id, 'Manager trả lại phải khác người tạo đơn.');
        return self::REJECTED;
    }

    private function revise(Actor $actor, string $status, int $creatorId): string
    {
        $this->expect($status === self::REJECTED && $actor->id === $creatorId && $actor->can('erp_purchases_create'), 'Chỉ người tạo được sửa lại đơn mua bị trả về.');
        return self::DRAFT;
    }

    private function receive(Actor $actor, string $status, int $warehouseId, bool $allLinesReceived): string
    {
        $this->expect(in_array($status, [self::APPROVED, self::PARTIALLY_RECEIVED], true), 'Đơn mua không còn hàng chờ nhập.');
        $this->expect($actor->can('erp_stock_receive') && in_array($warehouseId, $actor->warehouseIds, true), 'Bạn không được nhận hàng tại kho này.');
        return $allLinesReceived ? self::RECEIVED : self::PARTIALLY_RECEIVED;
    }

    private function cancel(Actor $actor, string $status): string
    {
        $this->expect($actor->can('erp_purchases_cancel'), 'Bạn không có quyền hủy đơn mua.');
        $this->expect(in_array($status, [self::DRAFT, self::PENDING_APPROVAL, self::APPROVED, self::PARTIALLY_RECEIVED], true), 'Không thể đóng đơn mua ở trạng thái này.');
        return $status === self::PARTIALLY_RECEIVED ? self::CLOSED : self::CANCELLED;
    }

    private function expect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new DomainException($message);
        }
    }
}
