<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Site;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Web\Uri;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use Bitrix\Socialnetwork\Provider\GroupProvider;

class GroupUrl
{
	/**
	 * Returns a ready to use address: a scrum one already carries the `scrum=Y` query, so no segment
	 * may be appended to the result. Use {@see self::getCard()} for the group card.
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public static function get(int $groupId, null|string|Type $groupType = null, array $parameters = []): string
	{
		$groupUrl = self::getBase($groupId);

		if (self::resolveType($groupId, $groupType) !== Type::Scrum)
		{
			return $groupUrl;
		}

		return (new Uri($groupUrl . 'tasks/'))
			->addParams(['scrum' => 'Y'])
			->getUri()
		;
	}

	public static function getCard(int $groupId): string
	{
		return self::getBase($groupId) . 'card/';
	}

	private static function getBase(int $groupId): string
	{
		return Site::getInstance()->getDirectory() . 'workgroups/group/' . $groupId . '/';
	}

	/**
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private static function resolveType(int $groupId, null|string|Type $groupType): ?Type
	{
		if ($groupType instanceof Type)
		{
			return $groupType;
		}

		if (is_string($groupType) && $groupType !== '')
		{
			return Type::tryFrom($groupType);
		}

		if ($groupId <= 0)
		{
			return null;
		}

		// legacy scrums keep TYPE empty, the provider derives the type from SCRUM_MASTER_ID
		return GroupProvider::getInstance()->getGroupType($groupId);
	}
}
