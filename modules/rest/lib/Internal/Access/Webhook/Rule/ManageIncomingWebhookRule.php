<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access\Webhook\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;
use Bitrix\Rest\Internal\Access\Webhook\WebhookAction;
use Bitrix\Rest\Internal\Access\Webhook\Model\IncomingWebhookModel;
use Bitrix\Rest\Internal\Entity\Access\EntityType;
use Bitrix\Rest\Internal\Service\AccessCodesService;

class ManageIncomingWebhookRule extends AbstractRule
{
	public function execute(?AccessibleItem $item = null, $params = null): bool
	{
		if ($this->user->isAdmin())
		{
			return true;
		}

		/** @var IncomingWebhookModel $item */
		if ($item !== null && $item->getUserId() === $this->user->getUserId())
		{
			return true;
		}

		$permissionType = WebhookAction::ManageIncomingWebhook->getPermissionType();
		if ($permissionType === null)
		{
			return false;
		}

		$service = new AccessCodesService();
		$allowedCodes = $service->getAccessCodes(EntityType::IncomingWebhook, $permissionType);

		if (empty($allowedCodes))
		{
			return false;
		}

		/** @var RestUserModel $user */
		$user = $this->user;

		return $user->canAccess($allowedCodes);
	}
}
