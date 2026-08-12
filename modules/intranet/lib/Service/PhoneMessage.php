<?php

namespace Bitrix\Intranet\Service;

use Bitrix\Bitrix24\Integration\Network\ProfileService;
use Bitrix\Intranet\Contract\SendableContract;
use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Exception\InvitationFailedException;
use Bitrix\Main\Loader;

class PhoneMessage implements SendableContract
{
	private bool $canSendInvite;

	public function __construct(
		private User $user,
		private bool $checkPolicies
	)
	{
		$this->canSendInvite = !(
			Loader::includeModule('bitrix24')
			&& !\CBitrix24::IsNfrLicense()
			&& (
				!\CBitrix24::IsLicensePaid()
				|| \CBitrix24::IsDemoLicense()
			)
		);
	}

	public function send(): void
	{
		if (
			Loader::includeModule('bitrix24')
			&& (
				!$this->checkPolicies
				|| $this->canSendInvite
			)
		)
		{
			$result = ProfileService::getInstance()
				->reInviteUserByPhone($this->user->getId())
			;
			if (!$result->isSuccess())
			{
				throw new InvitationFailedException($result->getErrorCollection());
			}
		}
	}

	public function sendImmediately(): void
	{
		$this->send();
	}
}
