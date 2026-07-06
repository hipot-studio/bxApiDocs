<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access\User;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\BaseAccessController;
use Bitrix\Main\Access\User\AccessibleUser;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;

class UserAccessController extends BaseAccessController
{
	public static function can($userId, string|UserAction $action, $itemId = null, $params = null): bool
	{
		return parent::can($userId, self::normalizeAction($action), $itemId, $params);
	}

	public function checkByItemId(string|UserAction $action, ?int $itemId = null, $params = null): bool
	{
		return parent::checkByItemId(self::normalizeAction($action), $itemId, $params);
	}

	public function check(string|UserAction $action, ?AccessibleItem $item = null, $params = null): bool
	{
		return parent::check(self::normalizeAction($action), $item, $params);
	}

	public function getEntityFilter(string|UserAction $action, string $entityName, $params = null): ?array
	{
		return parent::getEntityFilter(self::normalizeAction($action), $entityName, $params);
	}

	protected function loadItem(?int $itemId = null): ?AccessibleItem
	{
		return null;
	}

	protected function loadUser(int $userId): AccessibleUser
	{
		return RestUserModel::createFromId($userId);
	}

	private static function normalizeAction(string|UserAction $action): string
	{
		return $action instanceof UserAction ? $action->value : $action;
	}
}
