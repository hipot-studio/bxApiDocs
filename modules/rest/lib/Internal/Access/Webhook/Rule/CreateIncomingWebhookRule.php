<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access\Webhook\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;
use Bitrix\Rest\Internal\Access\Webhook\WebhookAction;
use Bitrix\Rest\Internal\Entity\Access\EntityType;
use Bitrix\Rest\Internal\Service\AccessCodesService;

class CreateIncomingWebhookRule extends AbstractRule
{
	public function execute(?AccessibleItem $item = null, $params = null): bool
	{
		if ($this->user->isAdmin())
		{
			return true;
		}

		$permissionType = WebhookAction::CreateIncomingWebhook->getPermissionType();
		if ($permissionType === null)
		{
			return false;
		}

		$service = new AccessCodesService();
		$allowedCodes = $service->getAccessCodes(EntityType::IncomingWebhook, $permissionType);

		if (empty($allowedCodes))
		{
			return true;
		}

		/** @var RestUserModel $user */
		$user = $this->user;

		return $user->canAccess($allowedCodes);
	}
}
