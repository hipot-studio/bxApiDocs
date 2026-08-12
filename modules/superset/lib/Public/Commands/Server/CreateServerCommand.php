<?php

namespace Bitrix\Superset\Public\Commands\Server;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\ServerRuntimeService;
use Bitrix\Superset\Public\Commands\Support\ServerResultDecorator;

final class CreateServerCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $accountId,
		public readonly ?string $portalId = null,
		public readonly ?string $portalUrl = null,
	)
	{
	}

	protected function execute(): Result
	{
		return ServerResultDecorator::decorateServerContextResult(
			(new ServerRuntimeService())->create([
				'accountId' => $this->accountId,
				'portalId' => $this->portalId,
				'portalUrl' => $this->portalUrl,
			])
		);
	}
}
