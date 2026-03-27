<?php

namespace Bitrix\IntranetMobile\Provider;

use Bitrix\Bitrix24\Portal\Settings\EmailConfirmationRequirements\Type;
use Bitrix\Bitrix24\Service\PortalSettings;
use Bitrix\Intranet\Invitation;
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Intranet\Integration\HumanResources\PermissionInvitation;

class InviteProvider
{
	public function getInviteSettings(): array
	{
		$canCurrentUserInvite = Invitation::canCurrentUserInvite();
		$isBitrix24Included = Loader::includeModule('bitrix24');
		$creatorEmailConfirmed = $this->isCreatorEmailConfirmed();
		$isInviteWithLocalEmailAppEnabled = (
			Option::get('intranetmobile', 'invite_with_local_email_app_enabled', 'Y') === 'Y'
		);
		$availableRootDepartment = $canCurrentUserInvite ? $this->getAvailableRootDepartment() : null;

		return [
			'adminConfirm' => $canCurrentUserInvite ? Invitation::getRegisterAdminConfirm() : null,
			'canInviteBySMS' => Invitation::canCurrentUserInviteByPhone(),
			'canInviteByLink' => Invitation::canCurrentUserInviteByLink(),
			'canInviteByEmail' => $canCurrentUserInvite,
			'canCurrentUserInvite' => $canCurrentUserInvite,
			'creatorEmailConfirmed' => $creatorEmailConfirmed,
			'isBitrix24Included' => $isBitrix24Included,
			'adminInBoxRedirectLink' => '/company/',
			'isInviteWithLocalEmailAppEnabled' => $isInviteWithLocalEmailAppEnabled,
			'availableRootDepartment' => $availableRootDepartment,
		];
	}

	public function getAvailableRootDepartment(): ?array
	{
		if (!Loader::includeModule('intranet'))
		{
			return null;
		}

		$department = PermissionInvitation::createByCurrentUser()->findFirstPossibleAvailableDepartment();
		if ($department === null)
		{
			return null;
		}

		return [
			'id' => $department->getId(),
			'title' => $department->getName(),
		];
	}

	public function isCreatorEmailConfirmed(): bool
	{
		return (
			!Loader::includeModule('bitrix24')
			|| !PortalSettings::getInstance()->getEmailConfirmationRequirements()->isRequiredByType(Type::INVITE_USERS)
		);
	}
}
