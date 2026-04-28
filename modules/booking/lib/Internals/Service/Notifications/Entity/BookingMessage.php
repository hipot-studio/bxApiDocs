<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\Notifications\Entity;

use Bitrix\Booking\Entity\EntityInterface;
use Bitrix\Booking\Internals\Service\Notifications\NotificationType;

class BookingMessage implements EntityInterface
{
	private int|null $id = null;
	private int|null $bookingId = null;
	private NotificationType|null $notificationType = null;
	private string|null $senderCode = null;
	private string|null $externalMessageId = null;

	public function getId(): int|null
	{
		return $this->id;
	}

	public function setId(int|null $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getBookingId(): int|null
	{
		return $this->bookingId;
	}

	public function setBookingId(int|null $bookingId): self
	{
		$this->bookingId = $bookingId;

		return $this;
	}

	//@todo NotificationType should be moved to public namespace i.e. \Bitrix\Booking\Entity
	public function getNotificationType(): NotificationType|null
	{
		return $this->notificationType;
	}

	public function setNotificationType(NotificationType|null $notificationType): self
	{
		$this->notificationType = $notificationType;

		return $this;
	}

	public function getSenderCode(): string|null
	{
		return $this->senderCode;
	}

	public function setSenderCode(string|null $senderCode): self
	{
		$this->senderCode = $senderCode;

		return $this;
	}

	public function getExternalMessageId(): string|null
	{
		return $this->externalMessageId;
	}

	public function setExternalMessageId(string|null $externalMessageId): self
	{
		$this->externalMessageId = $externalMessageId;

		return $this;
	}

	public function toArray()
	{
		return [
			'id' => $this->id,
			'bookingId' => $this->bookingId,
			'notificationType' => $this->notificationType->value,
			'senderCode' => $this->senderCode,
			'externalMessageId' => $this->externalMessageId,
		];
	}

	public static function mapFromArray(array $props): EntityInterface
	{
		return (new self())
			->setId(isset($props['id']) ? (int)$props['id'] : null)
			->setBookingId(isset($props['booking_id']) ? (int)$props['booking_id'] : null)
			->setNotificationType(
				isset($props['notification_type'])
					? NotificationType::tryFrom((string)$props['notification_type'])
					: null
			)
			->setSenderCode($props['sender_code'] ?? null)
			->setExternalMessageId(isset($props['external_message_id']) ? (string)$props['external_message_id'] : null)
		;
	}
}
