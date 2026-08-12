<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\AiAssistant\Service;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

class InvitationAvailabilityService
{
	public function isRegisterByLinkAllowed(): bool
	{
		return
			Loader::includeModule('socialservices')
			&& Option::get('socialservices', 'new_user_registration_network', 'Y') === 'Y'
		;
	}

	public function isPhoneInviteAllowed(): bool
	{
		return
			Loader::includeModule('bitrix24')
			&& Option::get('bitrix24', 'phone_invite_allowed', 'N') === 'Y'
		;
	}

	public function isLocalEmailProgram(): bool
	{
		return
			Loader::includeModule('bitrix24')
			&& Option::get('intranet', 'useInviteLocalEmailProgram', 'N') === 'Y'
		;
	}
}
