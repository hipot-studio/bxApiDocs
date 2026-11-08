<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\Intranet\AnnualSummary;

use Bitrix\Main\Entity\EntityInterface;

/**
 * One number of the annual summary: a single metric of a single employee.
 *
 * Implements EntityInterface of main instead of the ready-made summary entity of intranet: the
 * latter lives in a private namespace of a foreign module. The receiver still accepts it -
 * EntityCollection type-checks items against EntityInterface, and AnnualSummaryRepository reads
 * a value by getUserId(), getId() and getTotal() alone.
 */
final class AnnualSummaryValue implements EntityInterface
{
	public function __construct(
		private readonly int $userId,
		private readonly string $name,
		private readonly int $total,
	)
	{
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	/**
	 * The metric name, not the provider id: this value lands in the NAME column of the summary row.
	 */
	public function getId(): string
	{
		return $this->name;
	}

	public function getTotal(): int
	{
		return $this->total;
	}
}
