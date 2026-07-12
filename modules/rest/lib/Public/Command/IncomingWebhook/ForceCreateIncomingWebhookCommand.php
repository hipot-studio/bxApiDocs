<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Main;

class ForceCreateIncomingWebhookCommand extends AbstractCreateIncomingWebhookCommand
{
	protected function execute(): Main\Result
	{
		$data = (new ForceCreateIncomingWebhookCommandHandler())($this);

		return (new Main\Result())->setData([$data]);
	}
}
