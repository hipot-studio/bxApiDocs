<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Integration\Rest;

use Bitrix\Main;
use Bitrix\Rest\Public\Command\IncomingWebhook\Access\SetCreatorAccessCommand;
use Bitrix\Rest\Public\Provider\IncomingWebhook;

class AccessPolicy
{
	private bool $included;
	private ?IncomingWebhook\AccessPolicyProvider $inHookAccessProvider = null;

	public function __construct()
	{
		if (Main\Config\Option::get('intranet', 'rest_access_policy_is_enabled', 'N') !== 'Y')
		{
			$this->included = false;
		}
		else
		{
			$this->included = Main\Loader::includeModule('rest');
		}
		if ($this->included && class_exists(IncomingWebhook\AccessPolicyProvider::class))
		{
			$this->inHookAccessProvider = new IncomingWebhook\AccessPolicyProvider();
		}
	}

	public function isIncomingWebhookAccessPolicyEnabled(): bool
	{
		return $this->inHookAccessProvider !== null;
	}

	public function getIncomingWebhookCreatorGroupList(): array
	{
		return $this->inHookAccessProvider->getAccessCodesAllowedToCreateOwn();
	}

	public function setIncomingWebhookCreatorGroupList(array $accessCodes): void
	{
		if ($this->included && class_exists(SetCreatorAccessCommand::class))
		{
			(new SetCreatorAccessCommand(
				$accessCodes
			))->run();
		}
	}
}
