<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\UI\EntitySelector\Provider;

use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntity;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member\MemberEntityType;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;

class DepartmentSelectorItemProvider
{
	private const ENTITY_ID = 'department';

	public function getItems(?MemberEntityCollection $members): array
	{
		if ($members === null || $members->isEmpty())
		{
			return [];
		}

		$ids = [];
		$knownKeys = [];
		foreach ($members as $member)
		{
			if (
				!$member instanceof MemberEntity
				|| $member->type !== MemberEntityType::Department
				|| ($member->id ?? 0) <= 0
			)
			{
				continue;
			}

			$selectorId = $member->withChildNodes === false ? "{$member->id}:F" : $member->id;
			$key = self::getItemKey($selectorId);
			if (isset($knownKeys[$key]))
			{
				continue;
			}

			$knownKeys[$key] = true;
			$ids[] = [self::ENTITY_ID, $selectorId];
		}

		if ($ids === [])
		{
			return [];
		}

		try
		{
			if (!Loader::includeModule('ui'))
			{
				return [];
			}

			$items = $this->loadItems($ids);
		}
		catch (\Throwable)
		{
			return [];
		}

		$result = [];
		foreach ($items as $item)
		{
			if (!$item instanceof Item || $item->getEntityId() !== self::ENTITY_ID)
			{
				continue;
			}

			$result[self::getItemKey($item->getId())] = $item->toArray();
		}

		return $result;
	}

	public static function getItemKey(int|string $id): string
	{
		return self::ENTITY_ID . ':' . (string)$id;
	}

	protected function loadItems(array $ids): iterable
	{
		return Dialog::getPreselectedItems(
			$ids,
			[
				'entities' => [
					[
						'id' => self::ENTITY_ID,
						'options' => [
							'selectMode' => 'usersAndDepartments',
							'allowFlatDepartments' => true,
							'allowSelectRootDepartment' => true,
						],
					],
				],
			],
		);
	}
}
