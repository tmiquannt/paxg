<?php
declare(strict_types=1);

namespace MiniERP\Domain;

use DomainException;
use MiniERP\Security\Actor;

/** Pure sales-order transitions. Persistence and the final locked recheck belong to the service. */
final class SalesOrderStateMachine
{
    public const DRAFT = 'DRAFT';
    public const PENDING_APPROVAL = 'PENDING_APPROVAL';
    public const CONFIRMED = 'CONFIRMED';
    public const SHIPPED = 'SHIPPED';
    public const PARTIALLY_SHIPPED = 'PARTIALLY_SHIPPED';
    public const CANCELLED = 'CANCELLED';
    public const CLOSED = 'CLOSED';

    public function transition(
        Actor $actor,
        string $status,
        string $action,
        int $creatorId,
        int $warehouseId,
        string $discountBps,
        string $approvalThresholdBps,
        bool $allLinesFulfilled = true
    ): string {
        $discount = $this->basisPoints($discountBps);
        $threshold = $this->basisPoints($approvalThresholdBps);

        return match ($action) {
            'submit' => $this->submit($actor, $status, $creatorId, $discount, $threshold),
            'approve' => $this->approve($actor, $status, $creatorId),
            'reject' => $this->reject($actor, $status, $creatorId),
            'ship' => $this->ship($actor, $status, $warehouseId, $allLinesFulfilled),
            'cancel' => $this->cancel($actor, $status, $creatorId),
            default => throw new DomainException('Hành động đơn bán không hợp lệ.'),
        };
    }

    private function submit(Actor $actor, string $status, int $creatorId, int $discount, int $threshold): string
    {
        $this->expect($status === self::DRAFT, 'Chỉ đơn nháp mới được gửi.');
        $this->expect($creatorId === $actor->id || $actor->can('erp_sales_view_all'), 'Bạn không thể gửi đơn này.');

        if ($discount > $threshold) {
            $this->expect($creatorId === $actor->id && $actor->can('erp_sales_create'), 'Đơn vượt ngưỡng phải được người tạo gửi duyệt.');
            return self::PENDING_APPROVAL;
        }

        $this->expect($actor->can('erp_sales_confirm'), 'Bạn không có quyền xác nhận đơn bán.');
        return self::CONFIRMED;
    }

    private function approve(Actor $actor, string $status, int $creatorId): string
    {
        $this->expect($status === self::PENDING_APPROVAL, 'Đơn không ở trạng thái chờ duyệt.');
        $this->expect($actor->can('erp_sales_approve') && $creatorId !== $actor->id, 'Người duyệt phải là Manager khác người tạo.');
        return self::CONFIRMED;
    }

    private function reject(Actor $actor, string $status, int $creatorId): string
    {
        $this->expect($status === self::PENDING_APPROVAL, 'Đơn không ở trạng thái chờ duyệt.');
        $this->expect($actor->can('erp_sales_approve') && $creatorId !== $actor->id, 'Người trả lại phải là Manager khác người tạo.');
        return self::DRAFT;
    }

    private function ship(Actor $actor, string $status, int $warehouseId, bool $allLinesFulfilled): string
    {
        $this->expect(in_array($status, [self::CONFIRMED, self::PARTIALLY_SHIPPED], true), 'Đơn không còn hàng chờ xuất kho.');
        $this->expect($actor->can('erp_stock_ship') && in_array($warehouseId, $actor->warehouseIds, true), 'Bạn không được xuất hàng tại kho này.');
        return $allLinesFulfilled ? self::SHIPPED : self::PARTIALLY_SHIPPED;
    }

    private function cancel(Actor $actor, string $status, int $creatorId): string
    {
        $this->expect(in_array($status, [self::DRAFT, self::PENDING_APPROVAL, self::CONFIRMED, self::PARTIALLY_SHIPPED], true), 'Không thể đóng đơn ở trạng thái này.');
        $this->expect($actor->can('erp_sales_cancel'), 'Bạn không có quyền hủy đơn bán.');
        if ($status === self::PARTIALLY_SHIPPED) {
            $this->expect($actor->can('erp_sales_view_all'), 'Manager phải đóng phần hàng còn lại sau khi đã giao một phần.');
        }
        $this->expect($creatorId === $actor->id || $actor->can('erp_sales_view_all'), 'Bạn không thể hủy đơn này.');
        return $status === self::PARTIALLY_SHIPPED ? self::CLOSED : self::CANCELLED;
    }

    private function basisPoints(string $value): int
    {
        if (!preg_match('/\A(?:0|[1-9][0-9]{0,4})\z/D', $value)) {
            throw new DomainException('Tỷ lệ chiết khấu không hợp lệ.');
        }
        $basisPoints = (int) $value;
        $this->expect($basisPoints <= 10000, 'Tỷ lệ chiết khấu phải nằm trong khoảng 0..10000 bps.');
        return $basisPoints;
    }

    private function expect(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new DomainException($message);
        }
    }
}
