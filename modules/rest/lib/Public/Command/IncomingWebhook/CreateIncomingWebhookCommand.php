<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Main;

class CreateIncomingWebhookCommand extends AbstractCreateIncomingWebhookCommand
{
	public function __construct(
		int $userId,
		?string $title,
		array $scopes,
		array $attributes = [],
		?string $comment = null,
		public readonly ?int $ownerUserId = null,
	)
	{
		parent::__construct($userId, $title, $scopes, $attributes, $comment);
	}

	protected function execute(): Main\Result
	{
		$data = (new CreateIncomingWebhookCommandHandler())($this);

		return (new Main\Result())->setData([$data]);
	}

	public function toArray(): array
	{
		return [
			...parent::toArray(),
			'ownerUserId' => $this->ownerUserId,
		];
	}
}
