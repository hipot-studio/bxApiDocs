<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook\AccessPolicy;

use Bitrix\Main;

class SetIncomingWebhookCreationAccessCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		public readonly array $accessCodes,
	)
	{
	}

	protected function execute(): Main\Result
	{
		$result = new Main\Result();
		try
		{
			(new SetIncomingWebhookCreationAccessCommandHandler())($this);
		}
		catch (\Throwable $e)
		{
			$result->addError(new Main\Error($e->getMessage(), $e->getCode()));
		}

		return $result;
	}

	public function toArray(): array
	{
		return [
			'accessCodes' => $this->accessCodes,
		];
	}
}
