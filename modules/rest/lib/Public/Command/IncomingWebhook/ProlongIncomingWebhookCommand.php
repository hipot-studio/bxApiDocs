<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Main;
use Bitrix\Rest\Internal\Validation\Rule\DateGreaterThanOrEqual;

class ProlongIncomingWebhookCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		public readonly int $userId,
		public readonly string $webHookPassword,
		#[DateGreaterThanOrEqual]
		public readonly Main\Type\Date $dateExpire,
	)
	{
	}

	protected function execute(): Main\Result
	{
		$data = (new ProlongIncomingWebhookCommandHandler())($this);

		return (new Main\Result())->setData([$data]);
	}

	public function toArray(): array
	{
		return [
			'userId' => $this->userId,
			'webHookPassword' => $this->webHookPassword,
			'dateExpire' => $this->dateExpire,
		];
	}
}
