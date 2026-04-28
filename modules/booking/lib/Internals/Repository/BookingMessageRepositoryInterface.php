<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Repository;

use Bitrix\Booking\Internals\Service\Notifications\Entity\BookingMessage;
use Bitrix\Booking\Internals\Service\Notifications\Entity\BookingMessageCollection;

interface BookingMessageRepositoryInterface
{
	public function save(BookingMessage $bookingMessage): int;
	public function getByExternalId(string $senderCode, string $externalId): BookingMessage|null;
	public function getLastByBookingId(int $bookingId): BookingMessage|null;
	public function getByBookingIds(array $bookingIds): BookingMessageCollection;
}
