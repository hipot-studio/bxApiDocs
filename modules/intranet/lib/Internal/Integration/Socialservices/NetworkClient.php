<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\Socialservices;

use Bitrix\Intranet\Entity\User;
use Bitrix\Main\Loader;

class NetworkClient
{
	private ?\CBitrix24NetTransport $transport;

	public function __construct()
	{
		if (Loader::includeModule('socialservices'))
		{
			$this->transport = \CBitrix24NetPortalTransport::init() ?: null;
		}
	}

	public function getProfileContacts(User $user): array
	{
		return $this->transport?->getProfileContacts($user->getId()) ?: [];
	}

	public function sendSms($phone, $text, $langId = LANGUAGE_ID): ?array
	{
		return $this->transport?->call('portal.2fa.sendConfirmationCode', [
			'PHONE' => $phone,
			'LANG' => $langId,
			'MESSAGE_TEXT' => $text,
		]) ?: null;
	}
}
