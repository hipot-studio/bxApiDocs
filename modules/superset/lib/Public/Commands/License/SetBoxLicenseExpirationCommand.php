<?php

namespace Bitrix\Superset\Public\Commands\License;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\SelfHostedLicenseService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

/**
 * Hands the term of the boxed portal license over to the instance.
 */
final class SetBoxLicenseExpirationCommand extends AbstractServerCommand
{
	/**
	 * Timeouts are the business of the caller, the same as for the term of the extension: left out, they keep the
	 * defaults of the connector, shared by every consumer of the module.
	 */
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly ?int $timestamp,
		public readonly ?int $socketTimeout = null,
		public readonly ?int $streamTimeout = null,
	)
	{
	}

	protected function execute(): Result
	{
		return (new SelfHostedLicenseService($this->resolveServer($this->server)))->setBoxExpiration(
			$this->timestamp,
			$this->socketTimeout,
			$this->streamTimeout,
		);
	}
}
