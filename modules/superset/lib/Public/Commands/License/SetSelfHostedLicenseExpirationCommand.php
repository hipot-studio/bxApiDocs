<?php

namespace Bitrix\Superset\Public\Commands\License;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\SelfHostedLicenseService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class SetSelfHostedLicenseExpirationCommand extends AbstractServerCommand
{
	/**
	 * Timeouts are the business of the caller: this term is a short write, and a caller that sends it from a
	 * background pass has reason not to wait for it as long as a request for data waits. Left out, they keep the
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
		return (new SelfHostedLicenseService($this->resolveServer($this->server)))->setExpiration(
			$this->timestamp,
			$this->socketTimeout,
			$this->streamTimeout,
		);
	}
}
