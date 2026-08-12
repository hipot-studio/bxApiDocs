<?php

namespace Bitrix\Superset\Public\Commands\Server;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\JwtKeyPushService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class PushServerJwtPublicKeyCommand extends AbstractServerCommand
{
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly string $publicKey,
	)
	{
	}

	protected function execute(): Result
	{
		return (new JwtKeyPushService($this->resolveServer($this->server)))->pushPublicKey($this->publicKey);
	}
}
