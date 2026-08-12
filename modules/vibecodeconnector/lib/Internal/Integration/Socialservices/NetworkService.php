<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Socialservices;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Service\MicroService\Client;
use CSocServBitrix24Net;

class NetworkService
{
	public function getUserNetworkId(int $userId): ?string
	{
		if (!Loader::includeModule('socialservices'))
		{
			return null;
		}

		$networkProfile = \Bitrix\Socialservices\UserTable::getRow([
			'filter' => [
				'=USER_ID' => $userId,
				'=EXTERNAL_AUTH_ID' => CSocServBitrix24Net::ID,
			],
			'select' => [
				'XML_ID',
			],
		]);

		return !empty($networkProfile['XML_ID']) ? (string)$networkProfile['XML_ID'] : null;
	}

	public function getUserIdByNetworkId(string $networkUserId): ?int
	{
		if (!Loader::includeModule('socialservices'))
		{
			return null;
		}

		$networkProfile = \Bitrix\Socialservices\UserTable::getRow([
			'filter' => [
				'=XML_ID' => $networkUserId,
				'=EXTERNAL_AUTH_ID' => CSocServBitrix24Net::ID,
			],
			'select' => [
				'USER_ID',
			],
		]);

		return !empty($networkProfile['USER_ID']) ? (int)$networkProfile['USER_ID'] : null;
	}

	public function getPortalNetworkId(): ?string
	{
		$id = (string)Option::get('socialservices', 'bitrix24net_id', '');

		return $id !== '' ? $id : null;
	}

	public function isCloudPortal(): bool
	{
		return Client::getPortalType() === Client::TYPE_BITRIX24;
	}
}
