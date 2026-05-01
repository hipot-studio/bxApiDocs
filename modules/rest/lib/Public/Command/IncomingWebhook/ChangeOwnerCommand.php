<?php

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;

class ChangeOwnerCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $userId,
		public readonly ?int $newUserId = null,
		public readonly ?array $webhookIds = null
	)
	{
	}

	protected function execute(): Result
	{
		return (new ChangeOwnerCommandHandler())($this);
	}
}