<?php

namespace Bitrix\StaffTrack\Access;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\BaseAccessController;
use Bitrix\Main\Access\User\AccessibleUser;
use Bitrix\StaffTrack\Access\Model\OfficeModel;
use Bitrix\StaffTrack\Access\Model\UserModel;

class OfficeAccessController extends BaseAccessController
{
	public static array $cache = [];

	private const ITEM_TYPE = 'OFFICE';
	private const USER_TYPE = 'USER';

	protected function loadItem(?int $itemId = null): ?AccessibleItem
	{
		$key = self::ITEM_TYPE . '_' . $itemId;
		if (!isset(static::$cache[$key]))
		{
			static::$cache[$key] = OfficeModel::createFromId((int)$itemId);
		}

		return static::$cache[$key];
	}

	protected function loadUser(int $userId): AccessibleUser
	{
		$key = self::USER_TYPE . '_' . $userId;
		if (!isset(static::$cache[$key]))
		{
			static::$cache[$key] = UserModel::createFromId($userId);
		}

		return static::$cache[$key];
	}
}
