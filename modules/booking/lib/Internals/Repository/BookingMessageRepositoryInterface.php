<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Repository;

use Bitrix\Booking\Internals\Service\Notifications\Entity\BookingMessage;
use Bitrix\Booking\Internals\Service\Notifications\Entity\BookingMessageCollection;

interface BookingMessageRepositoryInterface
{
	public function save(BookingMessage $bookingMessage): void;
	public function delete(int $id): void;
	public function getById(int $id): BookingMessage|null;
	public function getByExternalId(string $senderCode, string $externalId): BookingMessage|null;
	public function getLastByBookingId(int $bookingId): BookingMessage|null;
	public function getBookingIdsWithSentConfirmation(array $bookingIds): array;
	public function getFailedForRetry(int $maxRetries, int $limit): BookingMessageCollection;
}
