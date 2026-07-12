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
		(new DeleteIncomingWebhookCommandHandler())($this);

		return new Main\Result();
	}

	public function toArray(): array
	{
		return [
			'userId' => $this->userId,
			'webHookPassword' => $this->webHookPassword,
		];
	}
}
