<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Main;

class DeactivateIncomingWebhookCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		public readonly int $userId,
		public readonly int $passwordId,
	)
	{
	}

	protected function execute(): Main\Result
	{
		$data = (new DeactivateIncomingWebhookCommandHandler())($this);

		return (new Main\Result())->setData([$data]);
	}

	public function toArray(): array
	{
		return [
			'userId' => $this->userId,
			'passwordId' => $this->passwordId,
		];
	}
}
