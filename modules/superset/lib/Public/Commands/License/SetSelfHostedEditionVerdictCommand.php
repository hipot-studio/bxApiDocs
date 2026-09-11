<?php

namespace Bitrix\Superset\Public\Commands\License;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\SelfHostedLicenseService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

/**
 * Tells the instance whether the edition of this box allows the local mode at all.
 */
final class SetSelfHostedEditionVerdictCommand extends AbstractServerCommand
{
	/**
	 * Timeouts are the business of the caller, the same as for the terms: left out, they keep the defaults of the
	 * connector, shared by every consumer of the module.
	 */
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly bool $isAllowed,
		public readonly ?int $socketTimeout = null,
		public readonly ?int $streamTimeout = null,
	)
	{
	}

	protected function execute(): Result
	{
		return (new SelfHostedLicenseService($this->resolveServer($this->server)))->setEditionVerdict(
			$this->isAllowed,
			$this->socketTimeout,
			$this->streamTimeout,
		);
	}
}
