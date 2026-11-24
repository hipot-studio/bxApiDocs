<?php

namespace Bitrix\AI\Limiter;

final class SharedMonthlyReservation
{
	private function __construct(
		private readonly ?string $id,
		private readonly int $userId,
		private readonly ?string $period,
		private readonly int $cost,
	)
	{
		if ($userId <= 0 || $cost <= 0)
		{
			throw new \InvalidArgumentException('Reservation user ID and cost must be positive.');
		}
	}

	public static function tracked(string $id, int $userId, string $period, int $cost): self
	{
		if (!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $id))
		{
			throw new \InvalidArgumentException('Reservation ID must be a UUID v4.');
		}
		if (trim($period) === '' || strlen($period) > 50)
		{
			throw new \InvalidArgumentException('Reservation period must contain between 1 and 50 bytes.');
		}

		return new self($id, $userId, $period, $cost);
	}

	public static function legacy(int $userId, int $cost): self
	{
		return new self(null, $userId, null, $cost);
	}

	public function isTracked(): bool
	{
		return $this->id !== null;
	}

	public function getConsumptionId(): string
	{
		return $this->isTracked() ? 'ai:shared_monthly_pool:v1:' . $this->id : 'ai:shared_monthly_pool';
	}

	public function getId(): ?string
	{
		return $this->id;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getPeriod(): ?string
	{
		return $this->period;
	}

	public function getCost(): int
	{
		return $this->cost;
	}
}
