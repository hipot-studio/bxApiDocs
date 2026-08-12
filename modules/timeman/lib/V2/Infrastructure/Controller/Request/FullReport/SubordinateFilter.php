<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Controller\Request\FullReport;

use Bitrix\Timeman\V2\Infrastructure\Controller\Request\Common\ScalarCaster;

final class SubordinateFilter
{
	/**
	 * @param array<int>|null $userIds
	 */
	public function __construct(
		public readonly ?bool $activeOnly = null,
		public readonly ?int $dateFrom = null,
		public readonly ?int $dateTo = null,
		public readonly ?int $userId = null,
		public readonly ?array $userIds = null,
	)
	{
	}

	public static function createFromArray(array $parameters): self
	{
		return new self(
			activeOnly: ScalarCaster::toBool($parameters['activeOnly'] ?? null),
			dateFrom: ScalarCaster::toPositiveInt($parameters['dateFrom'] ?? null),
			dateTo: ScalarCaster::toPositiveInt($parameters['dateTo'] ?? null),
			userId: ScalarCaster::toPositiveInt($parameters['userId'] ?? null),
			userIds: ScalarCaster::toPositiveIntCollection($parameters['userIds'] ?? null),
		);
	}

	public function prepare(): \Bitrix\Timeman\V2\Public\Provider\Params\FullReport\Filter
	{
		return new \Bitrix\Timeman\V2\Public\Provider\Params\FullReport\Filter(
			activeOnly: $this->activeOnly,
			userId: $this->userId,
			userIds: $this->userIds,
			dateFrom: $this->dateFrom,
			dateTo: $this->dateTo,
		);
	}
}
