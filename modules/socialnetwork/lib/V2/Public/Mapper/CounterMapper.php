<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Mapper;

use Bitrix\Socialnetwork\V2\Public\Dto;
use Bitrix\Socialnetwork\V2\Internal\Entity;

class CounterMapper
{
	public function mapToDtoCollection(Entity\CounterCollection $counterEntityCollection): Dto\CounterCollection
	{
		$colorMap = [
			Entity\CounterColor::Gray->value => Dto\CounterColor::Gray,
			Entity\CounterColor::Danger->value => Dto\CounterColor::Danger,
			Entity\CounterColor::Success->value => Dto\CounterColor::Success,
		];

		$dtoCollection = new Dto\CounterCollection();
		foreach ($counterEntityCollection as $counterEntity)
		{
			$colorValue = $counterEntity->color?->value ?? '';

			$dtoCollection->add(new Dto\Counter(
				groupId: $counterEntity->groupId,
				value: $counterEntity->value,
				color: $colorMap[$colorValue] ?? null,
			));
		}

		return $dtoCollection;
	}
}
