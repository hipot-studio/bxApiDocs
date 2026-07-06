<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Main;

class CreateSystemIncomingWebhookCommand extends AbstractCreateIncomingWebhookCommand
{
	protected function execute(): Main\Result
	{
		$result = new Main\Result();

		try
		{
			$data = (new CreateSystemIncomingWebhookCommandHandler())($this);
			$result->setData([$data]);
		}
		catch (Main\ObjectNotFoundException $e)
		{
			$result->addError(new Main\Error($e->getMessage(), $e->getCode()));
		}
		catch (Main\ArgumentException $e)
		{
			$result->addError(new Main\Error($e->getMessage(), 'INVALID_ARGUMENT'));
		}
		catch (Main\SystemException $e)
		{
			$result->addError(new Main\Error($e->getMessage(), $e->getCode()));
		}

		return $result;
	}
}
