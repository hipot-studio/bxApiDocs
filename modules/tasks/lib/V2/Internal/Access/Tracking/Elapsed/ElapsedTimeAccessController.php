<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Tracking\Elapsed;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\BaseAccessController;
use Bitrix\Tasks\Access\AccessErrorTrait;
use Bitrix\Tasks\Access\AccessUserTrait;

class ElapsedTimeAccessController extends BaseAccessController
{
	use AccessUserTrait;
	use AccessErrorTrait;

	private static array $cache = [];

	public static function can($userId, string|ElapsedTimeAction $action, $itemId = null, $params = null): bool
	{
		$action = is_string($action) ? $action : $action->value;
		return parent::can($userId, $action, $itemId, $params);
	}

	public function check(string|ElapsedTimeAction $action, AccessibleItem $item = null, $params = null): bool
	{
		$action = is_string($action) ? $action : $action->value;
		return parent::check($action, $item, $params);
	}

	public function checkByItemId(string|ElapsedTimeAction $action, int $itemId = null, $params = null): bool
	{
		$action = is_string($action) ? $action : $action->value;

		return parent::checkByItemId($action, $itemId, $params);
	}

	protected function loadItem(int $itemId = null): ?AccessibleItem
	{
		$itemId = (int)$itemId;
		if ($itemId === 0)
		{
			return new ElapsedTimeModel();
		}

		$key = 'ELAPSED_TIME' . $itemId;
		if (!isset(static::$cache[$key]))
		{
			static::$cache[$key] = ElapsedTimeModel::createFromId($itemId);
		}

		return static::$cache[$key];
	}
}
