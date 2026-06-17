<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Integration\Rest;

use Bitrix\Main;
use Bitrix\Rest\Public\Command\Application\AccessPolicy\SetLocalApplicationCreationAccessCommand;
use Bitrix\Rest\Public\Command\Application\AccessPolicy\SetPersonalApplicationCreationAccessCommand;
use Bitrix\Rest\Public\Command\IncomingWebhook\AccessPolicy\SetOwnIncomingWebhookCreationAccessCommand;
use Bitrix\Rest\Public\Provider\Application;
use Bitrix\Rest\Public\Provider\IncomingWebhook;

class AccessPolicy
{
	private bool $included;
	private ?IncomingWebhook\AccessPolicyProvider $inHookAccessProvider = null;
	private ?Application\AccessPolicyProvider $applicationAccessProvider = null;

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
		if ($this->included && class_exists(Application\AccessPolicyProvider::class))
		{
			$this->applicationAccessProvider = new Application\AccessPolicyProvider();
		}
	}

	public function isIncomingWebhookAccessPolicyEnabled(): bool
	{
		return $this->inHookAccessProvider !== null;
	}

	public function getIncomingWebhookCreatorGroupList(): array
	{
		return $this->inHookAccessProvider?->getAccessCodesAllowedToCreateOwn() ?? [];
	}

	public function setIncomingWebhookCreatorGroupList(array $accessCodes): void
	{
		if ($this->included && class_exists(SetOwnIncomingWebhookCreationAccessCommand::class))
		{
			(new SetOwnIncomingWebhookCreationAccessCommand(
				$accessCodes
			))->run();
		}
	}

	public function isLocalAppAccessPolicyEnabled(): bool
	{
		return $this->applicationAccessProvider !== null;
	}

	public function getLocalAppCreatorGroupList(): array
	{
		return $this->applicationAccessProvider?->getAccessCodesAllowedToCreateLocalApp() ?? [];
	}

	public function setLocalAppCreatorGroupList(array $accessCodes): void
	{
		if ($this->included && class_exists(SetLocalApplicationCreationAccessCommand::class))
		{
			(new SetLocalApplicationCreationAccessCommand($accessCodes))->run();
		}
	}

	public function getPersonalAppCreatorGroupList(): array
	{
		return $this->applicationAccessProvider?->getAccessCodesAllowedToCreatePersonalApp() ?? [];
	}

	public function setPersonalAppCreatorGroupList(array $accessCodes): void
	{
		if ($this->included && class_exists(SetPersonalApplicationCreationAccessCommand::class))
		{
			(new SetPersonalApplicationCreationAccessCommand($accessCodes))->run();
		}
	}
}
