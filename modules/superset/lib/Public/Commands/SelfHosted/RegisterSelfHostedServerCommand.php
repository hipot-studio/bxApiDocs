<?php

namespace Bitrix\Superset\Public\Commands\SelfHosted;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\SelfHostedServerRuntimeService;
use Bitrix\Superset\Internal\Services\ServerRuntimeService;
use Bitrix\Superset\Public\Commands\Support\ServerResultDecorator;

final class RegisterSelfHostedServerCommand extends AbstractCommand
{
	protected function execute(): Result
	{
		$runtimeService = new SelfHostedServerRuntimeService();
		$server = $runtimeService->find();
		if ($server !== null)
		{
			return ServerResultDecorator::createServerContextResult($server);
		}

		$server = $runtimeService->getOrCreate();

		return ServerResultDecorator::decorateServerContextResult(
			(new ServerRuntimeService())->create([
				'accountId' => (int)$server->getAccountId(),
			])
		);
	}
}
