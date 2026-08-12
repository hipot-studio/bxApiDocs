<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook;
use Bitrix\Rest\Internal\Service\IncomingWebhook\SystemIncomingWebhookCreator;

final class CreateSystemIncomingWebhookCommandHandler
{
	public function __construct(
		private readonly SystemIncomingWebhookCreator $creator = new SystemIncomingWebhookCreator(),
	)
	{
	}

	public function __invoke(CreateSystemIncomingWebhookCommand $command): IncomingWebhook
	{
		return $this->creator->create(
			userId: $command->userId,
			title: $command->title,
			scopes: $command->scopes,
			attributes: $command->attributes,
			comment: $command->comment,
		);
	}
}
