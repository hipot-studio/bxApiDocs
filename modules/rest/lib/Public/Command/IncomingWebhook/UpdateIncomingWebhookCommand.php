<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Main;

class UpdateIncomingWebhookCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		public readonly int $userId,
		public readonly string $webHookPassword,
		public readonly ?string $title,
		public readonly ?array $scopes,
	)
	{
	}

	protected function execute(): Main\Result
	{
		$data = (new UpdateIncomingWebhookCommandHandler())($this);

		return (new Main\Result())->setData([$data]);
	}

	public function toArray(): array
	{
		return [
			'userId' => $this->userId,
			'webHookPassword' => $this->webHookPassword,
			'title' => $this->title,
			'scopes' => $this->scopes,
		];
	}
}
