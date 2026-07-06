<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Main;

class DeleteIncomingWebhookCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		public readonly int $userId,
		public readonly string $webHookPassword,
	)
	{
	}

	protected function execute(): Main\Result
	{
		$result = new Main\Result();

		try
		{
			(new DeleteIncomingWebhookCommandHandler())($this);
		}
		catch (Main\AccessDeniedException | Main\ObjectNotFoundException $e)
		{
			$result->addError(new Main\Error($e->getMessage(), $e->getCode()));
		}

		return $result;
	}

	public function toArray(): array
	{
		return [
			'userId' => $this->userId,
			'webHookPassword' => $this->webHookPassword,
		];
	}
}
