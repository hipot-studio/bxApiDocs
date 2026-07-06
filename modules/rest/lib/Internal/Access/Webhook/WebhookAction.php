<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Access\Webhook;

use Bitrix\Rest\Internal\Entity\Access\PermissionType;

enum WebhookAction: string
{
	case CreateIncomingWebhook = 'create_incoming_webhook';
	case CreateOwnIncomingWebhook = 'create_own_incoming_webhook';
	case ManageIncomingWebhook = 'manage_incoming_webhook';
	case ManageOwnIncomingWebhook = 'manage_own_incoming_webhook';

	public function getPermissionType(): ?PermissionType
	{
		return match ($this)
		{
			self::CreateIncomingWebhook => PermissionType::Create,
			self::CreateOwnIncomingWebhook => PermissionType::CreateOwn,
			self::ManageIncomingWebhook => PermissionType::Manage,
			self::ManageOwnIncomingWebhook => PermissionType::ManageOwn,
		};
	}
}
