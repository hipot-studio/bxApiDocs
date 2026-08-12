<?php

namespace Bitrix\Superset\Public\Commands\Server;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\ServerRuntimeService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Commands\Support\ServerResultDecorator;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class UpdateServerIdentityCommand extends AbstractServerCommand
{
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly ?int $accountId = null,
		public readonly ?string $portalId = null,
		public readonly ?string $portalUrl = null,
		public readonly ?bool $isPortalIdVerified = null,
	)
	{
	}

	protected function execute(): Result
	{
		$fields = [];
		if ($this->accountId !== null)
		{
			$fields['accountId'] = $this->accountId;
		}
		if ($this->portalId !== null)
		{
			$fields['portalId'] = $this->portalId;
		}
		if ($this->portalUrl !== null)
		{
			$fields['portalUrl'] = $this->portalUrl;
		}
		if ($this->isPortalIdVerified !== null)
		{
			$fields['isPortalIdVerified'] = $this->isPortalIdVerified;
		}

		return ServerResultDecorator::decorateServerContextResult(
			(new ServerRuntimeService())->update(
				$this->resolveServer($this->server),
				$fields,
			)
		);
	}
}
