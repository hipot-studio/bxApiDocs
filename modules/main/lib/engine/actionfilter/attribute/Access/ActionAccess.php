<?php

declare(strict_types=1);

namespace Bitrix\Main\Engine\ActionFilter\Attribute\Access;

use Attribute;
use Bitrix\Main\Engine\ActionFilter\AccessCheck;
use Bitrix\Main\Engine\ActionFilter\Attribute\FilterAttributeInterface;
use Bitrix\Main\Engine\ActionFilter\FilterType;

#[Attribute(flags: Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ActionAccess implements FilterAttributeInterface
{
	/**
	 * @param string|\UnitEnum $action Action identifier from ActionDictionary
	 * @param class-string<\Bitrix\Main\Engine\ActionFilter\Access\AccessCheckStrategyInterface>|null $strategy
	 * @param array|null $strategyArgs Additional arguments passed to strategy
	 */
	public function __construct(
		public readonly string|\UnitEnum $action,
		public readonly ?string $strategy = null,
		public readonly ?array $strategyArgs = null,
	) {}

	public function getFilters(): array
	{
		return [
			new AccessCheck(
				accessAction: $this->action,
				strategyClass: $this->strategy,
				strategyArgs: $this->strategyArgs,
			),
		];
	}

	public function getType(): FilterType
	{
		return FilterType::EnablePrefilter;
	}
}
