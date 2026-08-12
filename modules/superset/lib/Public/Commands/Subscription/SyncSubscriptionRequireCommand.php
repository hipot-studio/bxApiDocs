<?php

namespace Bitrix\Superset\Public\Commands\Subscription;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\SubscriptionService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class SyncSubscriptionRequireCommand extends AbstractServerCommand
{
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly ?array $dashboards,
	)
	{
	}

	protected function execute(): Result
	{
		return (new SubscriptionService($this->resolveServer($this->server)))->syncRequire($this->dashboards);
	}
}
