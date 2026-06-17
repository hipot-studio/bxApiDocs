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
}
