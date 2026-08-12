<?php

namespace Bitrix\Superset\Public\Support;

use Bitrix\Superset\Internal\Entities\Server;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;
use Bitrix\Superset\Public\Dto\ServerRuntimeStateDto;

final class ServerDtoFactory
{
	public function createReference(Server $server): ServerReferenceDto
	{
		return new ServerReferenceDto(
			serverId: (int)$server->getId(),
		);
	}

	public function createRuntimeState(Server $server): ServerRuntimeStateDto
	{
		return new ServerRuntimeStateDto(
			serverId: (int)$server->getId(),
			accountId: (int)$server->getAccountId(),
			portalId: (string)($server->getPortalId() ?? ''),
			portalUrl: $server->getPortalUrl(),
			host: (string)($server->getHost() ?? ''),
			instanceKey: (string)($server->getInstanceKey() ?? ''),
			instanceUsername: (string)($server->getInstanceUsername() ?? ''),
			startJobId: (string)($server->getStartJobId() ?? ''),
			version: (int)($server->getVersion() ?? 0),
			isPortalIdVerified: $server->isPortalIdVerified(),
			accessPassword: (string)($server->getAccessPassword() ?? ''),
			token: (string)($server->getToken() ?? ''),
			refreshToken: (string)($server->getRefreshToken() ?? ''),
			dateStartAttempt: $server->getDateStartAttempt()?->format('Y-m-d H:i:s'),
		);
	}
}
