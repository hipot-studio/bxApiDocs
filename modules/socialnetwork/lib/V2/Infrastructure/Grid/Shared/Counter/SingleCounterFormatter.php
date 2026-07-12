<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Counter;

use Bitrix\Main\Grid\Counter\Type;
use Bitrix\Socialnetwork\V2\Public\Dto\CounterCollection;
use Bitrix\Socialnetwork\V2\Public\Dto\CounterColor;

class SingleCounterFormatter
{
	private const COUNTER_UI_CLASS = 'sonet-ui-grid-counter';

	public function format(CounterCollection $counterCollection): array
	{
		$result = [];

		foreach ($counterCollection as $counter)
		{
			$counterData = [
				'type' => Type::LEFT_ALIGNED,
				'color' => $counter->color?->value ?? CounterColor::Gray->value,
				'value' => $counter->value,
				'class' => self::COUNTER_UI_CLASS,
			];

			$result[$counter->groupId] = $counterData;
		}

		return $result;
	}
}
