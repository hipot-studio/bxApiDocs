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
		public readonly array $scopes,
	)
	{
	}

	protected function execute(): Main\Result
	{
		$result = new Main\Result();

		try
		{
			$data = (new UpdateIncomingWebhookCommandHandler())($this);
			$result->setData([$data]);
		}
		catch (Main\AccessDeniedException | Main\ObjectNotFoundException $e)
		{
			$result->addError(new Main\Error($e->getMessage(), $e->getCode()));
		}
		catch (Main\ArgumentException $e)
		{
			$result->addError(new Main\Error($e->getMessage(), 'INVALID_ARGUMENT'));
		}

		return $result;
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
