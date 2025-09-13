<?php

namespace Bitrix\IntranetMobile\Provider;

use Bitrix\Intranet\Invitation;
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;

class InviteProvider
{
	public function getInviteSettings(): array
	{
		$canCurrentUserInvite = Invitation::canCurrentUserInvite();
		$isBitrix24Included = Loader::includeModule('bitrix24');
		$creatorEmailConfirmed = !$isBitrix24Included
			|| !\Bitrix\Bitrix24\Service\PortalSettings::getInstance()
				->getEmailConfirmationRequirements()
				->isRequiredByType(\Bitrix\Bitrix24\Portal\Settings\EmailConfirmationRequirements\Type::INVITE_USERS);
		$isInviteWithLocalEmailAppEnabled = Option::get('intranetmobile', 'invite_with_local_email_app_enabled', 'Y') === 'Y';

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
		];
	}
}