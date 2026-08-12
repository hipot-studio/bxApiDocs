<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Controller\Request\Report;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Timeman\V2\Infrastructure\Controller\Request\Common\ScalarCaster;

final class Filter
{
	public function __construct(
		#[NotEmpty]
		#[PositiveNumber]
		public readonly int $userId,
		public readonly int | array | null $recordId = null,
		public readonly ?bool $withAi = null,
	)
	{
	}

	public static function createFromArray(array $parameters): self
	{
		return new self(
			userId: ScalarCaster::toPositiveInt($parameters['userId'] ?? null),
			recordId: is_array($parameters['recordId'] ?? null)
				? ScalarCaster::toPositiveIntCollection($parameters['recordId'])
				: ScalarCaster::toPositiveInt($parameters['recordId'] ?? null),
			withAi: ScalarCaster::toBool($parameters['withAi'] ?? null),
		);
	}

	public function prepare(): \Bitrix\Timeman\V2\Public\Provider\Params\Report\Filter
	{
		return new \Bitrix\Timeman\V2\Public\Provider\Params\Report\Filter(
			recordId: $this->recordId,
			withAi: $this->withAi,
		);
	}
}
