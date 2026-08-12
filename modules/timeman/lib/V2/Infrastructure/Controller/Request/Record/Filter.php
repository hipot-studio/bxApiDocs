<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Controller\Request\Record;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Timeman\V2\Infrastructure\Controller\Request\Common\ScalarCaster;

final class Filter
{
	public function __construct(
		#[NotEmpty]
		#[PositiveNumber]
		public readonly int $userId,
		public readonly ?int $dateFrom = null,
		public readonly ?int $dateTo = null,
	)
	{
	}

	public static function createFromArray(array $parameters): self
	{
		return new self(
			userId: ScalarCaster::toPositiveInt($parameters['userId'] ?? null),
			dateFrom: ScalarCaster::toPositiveInt($parameters['dateFrom'] ?? null),
			dateTo: ScalarCaster::toPositiveInt($parameters['dateTo'] ?? null),
		);
	}

	public function prepare(): \Bitrix\Timeman\V2\Public\Provider\Params\Record\Filter
	{
		return new \Bitrix\Timeman\V2\Public\Provider\Params\Record\Filter(
			userId: $this->userId,
			dateFrom: $this->dateFrom,
			dateTo: $this->dateTo,
		);
	}
}
