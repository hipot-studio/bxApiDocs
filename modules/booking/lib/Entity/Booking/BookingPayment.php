<?php

declare(strict_types=1);

namespace Bitrix\Booking\Entity\Booking;

class BookingPayment
{
	private int|null $id = null;
	private bool $isPaid = false;
	private bool $isPaidManually = false;

	public function getId(): int
	{
		return $this->id;
	}

	public function setId(int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function isPaid(): bool
	{
		return $this->isPaid;
	}

	public function setIsPaid(bool $isPaid): self
	{
		$this->isPaid = $isPaid;

		return $this;
	}

	public function isPaidManually(): bool
	{
		return $this->isPaidManually;
	}

	public function setIsPaidManually(bool $isPaidManually): self
	{
		$this->isPaidManually = $isPaidManually;

		return $this;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->getId(),
			'isPaid' => $this->isPaid,
			'isPaidManually' => $this->isPaidManually,
		];
	}
}
