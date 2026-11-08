<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Dto;

final readonly class ApplicationStatusData
{
	public function __construct(
		private ApplicationStatus $status,
		private bool $isPaymentAllowed,
		private bool $isPaymentExpired,
		private bool $isPaymentNotifyNeeded,
		private ?int $daysLeft,
		private bool $isHeld,
	)
	{
	}

	public function getStatus(): ApplicationStatus
	{
		return $this->status;
	}

	public function isPaymentAllowed(): bool
	{
		return $this->isPaymentAllowed;
	}

	public function isPaymentExpired(): bool
	{
		return $this->isPaymentExpired;
	}

	public function isPaymentNotifyNeeded(): bool
	{
		return $this->isPaymentNotifyNeeded;
	}

	public function getDaysLeft(): ?int
	{
		return $this->daysLeft;
	}

	public function isHeld(): bool
	{
		return $this->isHeld;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'status' => $this->status->name,
			'isPaymentAllowed' => $this->isPaymentAllowed,
			'isPaymentExpired' => $this->isPaymentExpired,
			'isPaymentNotifyNeeded' => $this->isPaymentNotifyNeeded,
			'daysLeft' => $this->daysLeft,
			'isHeld' => $this->isHeld,
		];
	}
}
