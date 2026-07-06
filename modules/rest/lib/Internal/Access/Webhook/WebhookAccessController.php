<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access\Webhook;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\BaseAccessController;
use Bitrix\Main\Access\User\AccessibleUser;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;
use Bitrix\Rest\Internal\Access\Webhook\Model\IncomingWebhookModel;

class WebhookAccessController extends BaseAccessController
{
	public static function can($userId, string|WebhookAction $action, $itemId = null, $params = null): bool
	{
		return parent::can($userId, self::normalizeAction($action), $itemId, $params);
	}

	public function checkByItemId(string|WebhookAction $action, ?int $itemId = null, $params = null): bool
	{
		return parent::checkByItemId(self::normalizeAction($action), $itemId, $params);
	}

	public function check(string|WebhookAction $action, ?AccessibleItem $item = null, $params = null): bool
	{
		return parent::check(self::normalizeAction($action), $item, $params);
	}

	public function getEntityFilter(string|WebhookAction $action, string $entityName, $params = null): ?array
	{
		return parent::getEntityFilter(self::normalizeAction($action), $entityName, $params);
	}

	protected function loadItem(?int $itemId = null): ?AccessibleItem
	{
		return IncomingWebhookModel::createFromId($itemId);
	}

	protected function loadUser(int $userId): AccessibleUser
	{
		return RestUserModel::createFromId($userId);
	}

	private static function normalizeAction(string|WebhookAction $action): string
	{
		return $action instanceof WebhookAction ? $action->value : $action;
	}
}
