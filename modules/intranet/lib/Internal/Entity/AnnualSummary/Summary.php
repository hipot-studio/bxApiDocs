<?php

namespace Bitrix\Intranet\Internal\Entity\AnnualSummary;

class Summary implements SummaryInterface
{
	public function __construct(
		private readonly int $userId,
		private readonly string $id,
		private readonly int $total,
	)
	{
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getTotal(): int
	{
		return $this->total;
	}
}