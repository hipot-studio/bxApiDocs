<?php

namespace Bitrix\Superset\Public\Commands\Subscription;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\SubscriptionService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class SetSubscriptionExpirationCommand extends AbstractServerCommand
{
	/**
	 * Timeouts are the business of the caller: left out, they keep the defaults of the connector.
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
		return (new SubscriptionService($this->resolveServer($this->server)))
			->setExpiration($this->timestamp, $this->socketTimeout, $this->streamTimeout)
		;
	}
}
