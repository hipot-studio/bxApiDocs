<?php

namespace Bitrix\Superset\Public\Commands\Subscription;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\SubscriptionService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class SetSubscriptionExpirationCommand extends AbstractServerCommand
{
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly ?int $timestamp,
	)
	{
	}

	protected function execute(): Result
	{
		return (new SubscriptionService($this->resolveServer($this->server)))->setExpiration($this->timestamp);
	}
}
