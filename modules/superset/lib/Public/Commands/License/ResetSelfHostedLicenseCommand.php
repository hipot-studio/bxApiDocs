<?php

namespace Bitrix\Superset\Public\Commands\License;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\SelfHostedLicenseService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

/**
 * Wipes both license terms kept by the instance: of the extension and of the boxed portal.
 *
 * Carries no value of its own: the caller asks for the absence of the terms, not for a new one.
 */
final class ResetSelfHostedLicenseCommand extends AbstractServerCommand
{
	/**
	 * Timeouts are the business of the caller, the same as for both writes of a term: left out, they keep the
	 * defaults of the connector, shared by every consumer of the module.
	 */
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly ?int $socketTimeout = null,
		public readonly ?int $streamTimeout = null,
	)
	{
	}

	protected function execute(): Result
	{
		return (new SelfHostedLicenseService($this->resolveServer($this->server)))->resetLicense(
			$this->socketTimeout,
			$this->streamTimeout,
		);
	}
}
