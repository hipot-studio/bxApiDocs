<?php

namespace Bitrix\Superset\Internal\Repositories\Mapper;

use Bitrix\Superset\Internal\Entities\Server;
use Bitrix\Superset\Internal\Models\EO_Server;

final class ServerOrmMapper
{
	public function convertFromOrm(EO_Server $ormServer): Server
	{
		return (new Server())
			->setId($ormServer->getId())
			->setHost($ormServer->getHost())
			->setAccessPassword($ormServer->getAccessPassword())
			->setInstanceKey($ormServer->getInstanceKey())
			->setInstanceUsername($ormServer->getInstanceUsername())
			->setToken($ormServer->getToken())
			->setRefreshToken($ormServer->getRefreshToken())
			->setStartJobId($ormServer->getStartJobId())
			->setAccountId((int)$ormServer->getAccountId())
			->setVersion($ormServer->getVersion())
			->setIsPortalIdVerified($ormServer->getIsPortalIdVerified() === 'Y')
			->setPortalId($ormServer->getPortalId())
			->setPortalUrl($ormServer->getPortalUrl())
			->setJwtSecret($ormServer->getJwtSecret())
			->setDateStartAttempt($ormServer->getDateStartAttempt());
	}

	public function convertToOrm(Server $server): EO_Server
	{
		$ormServer = $server->getId()
			? EO_Server::wakeUp($server->getId())
			: new EO_Server();

		$ormServer->setAccountId($server->getAccountId());
		$ormServer->setIsPortalIdVerified($server->isPortalIdVerified() ? 'Y' : 'N');

		$this->applyNullableString($ormServer, 'Host', $server->getHost());
		$this->applyNullableString($ormServer, 'AccessPassword', $server->getAccessPassword());
		$this->applyNullableString($ormServer, 'InstanceKey', $server->getInstanceKey());
		$this->applyNullableString($ormServer, 'InstanceUsername', $server->getInstanceUsername());
		$this->applyNullableString($ormServer, 'Token', $server->getToken());
		$this->applyNullableString($ormServer, 'RefreshToken', $server->getRefreshToken());
		$this->applyNullableString($ormServer, 'StartJobId', $server->getStartJobId());
		$this->applyNullableString($ormServer, 'PortalId', $server->getPortalId());
		$this->applyNullableString($ormServer, 'PortalUrl', $server->getPortalUrl() !== '' ? $server->getPortalUrl() : null);
		$this->applyNullableString($ormServer, 'JwtSecret', $server->getJwtSecret());
		$this->applyNullableInteger($ormServer, 'Version', $server->getVersion());

		if ($server->getDateStartAttempt() === null)
		{
			$ormServer->unsetDateStartAttempt();
		}
		else
		{
			$ormServer->setDateStartAttempt($server->getDateStartAttempt());
		}

		return $ormServer;
	}

	private function applyNullableString(EO_Server $ormServer, string $fieldName, ?string $value): void
	{
		$setter = "set{$fieldName}";
		$unsetter = "unset{$fieldName}";

		if ($value === null)
		{
			$ormServer->{$unsetter}();

			return;
		}

		$ormServer->{$setter}($value);
	}

	private function applyNullableInteger(EO_Server $ormServer, string $fieldName, ?int $value): void
	{
		$setter = "set{$fieldName}";
		$unsetter = "unset{$fieldName}";

		if ($value === null)
		{
			$ormServer->{$unsetter}();

			return;
		}

		$ormServer->{$setter}($value);
	}
}
