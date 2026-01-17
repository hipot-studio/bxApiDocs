<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template\Action\Update;

use Bitrix\Tasks\Integration\Pull\PushCommand;
use Bitrix\Tasks\Integration\Pull\PushService;
use Bitrix\Tasks\Internals\Log\LogFacade;
use Bitrix\Tasks\V2\Internal\Service\Template\Action\Update\Config\UpdateConfig;
use Exception;

class SendPush
{
	public function __construct(
		private readonly UpdateConfig $config
	)
	{

	}

	public function __invoke(array $fields, array $template): void
	{
		$params = [
			'TEMPLATE_ID' => $fields['ID'],
			'TEMPLATE_TITLE' => $fields['TITLE'] ?? $template['TITLE'],
		];

		try
		{
			PushService::addEvent($this->config->getUserId(), [
				'module_id' => 'tasks',
				'command' => PushCommand::TEMPLATE_UPDATED,
				'params' => $params,
			]);
		}
		catch (Exception $exception)
		{
			LogFacade::logThrowable($exception);

			return;
		}
	}
}
