<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Main;

class CreateSystemIncomingWebhookCommand extends AbstractCreateIncomingWebhookCommand
{
	protected function execute(): Main\Result
	{
		$data = (new CreateSystemIncomingWebhookCommandHandler())($this);

		return (new Main\Result())->setData([$data]);
	}
}
