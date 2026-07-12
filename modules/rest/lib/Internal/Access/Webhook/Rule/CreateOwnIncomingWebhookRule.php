<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access\Webhook\Rule;

use Bitrix\Main\Access\AccessibleItem;
use Bitrix\Main\Access\Rule\AbstractRule;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;
use Bitrix\Rest\Internal\Access\Webhook\WebhookAction;
use Bitrix\Rest\Internal\Entity\Access\EntityType;
use Bitrix\Rest\Internal\Entity\Access\PermissionType;
use Bitrix\Rest\Internal\Service\AccessCodesService;

class CreateOwnIncomingWebhookRule extends AbstractRule
{
	public function execute(?AccessibleItem $item = null, $params = null): bool
	{
		if ($this->user->isAdmin())
		{
			return true;
		}

		$permissionType = WebhookAction::CreateOwnIncomingWebhook->getPermissionType();
		if ($permissionType === null)
		{
			return false;
		}

		/** @var RestUserModel $user */
		$user = $this->user;

		$service = new AccessCodesService();

		$createOwnAccessCodes = $service->getAccessCodes(EntityType::IncomingWebhook, $permissionType);
		if (!empty($createOwnAccessCodes) && $user->canAccess($createOwnAccessCodes))
		{
			return true;
		}

		$createAccessCodes = $service->getAccessCodes(EntityType::IncomingWebhook, PermissionType::Create);
		if (!empty($createAccessCodes) && $user->canAccess($createAccessCodes))
		{
			return true;
		}

		return false;
	}
}
