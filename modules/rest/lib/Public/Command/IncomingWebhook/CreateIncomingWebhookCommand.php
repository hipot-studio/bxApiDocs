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
		public readonly ?int $ownerUserId = null,
	)
	{
		parent::__construct($userId, $title, $scopes, $attributes);
	}

	protected function execute(): Main\Result
	{
		$result = new Main\Result();

		try
		{
			$data = (new CreateIncomingWebhookCommandHandler())($this);
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
			...parent::toArray(),
			'ownerUserId' => $this->ownerUserId,
		];
	}
}
