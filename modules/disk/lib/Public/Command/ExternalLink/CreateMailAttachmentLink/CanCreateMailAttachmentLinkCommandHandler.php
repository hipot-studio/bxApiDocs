<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\ExternalLink\CreateMailAttachmentLink;

use Bitrix\Disk\Internal\Service\ExternalLink\MailAttachmentExternalLinkService;
use Bitrix\Main\Result;

/**
 * No registration in `disk/.settings.php`: the ServiceLocator autowires the handler by the class name
 * and keeps the built one for the rest of the process. Nothing here may depend on the current user or on
 * anything else of a single call; such a dependency needs an explicit registration with
 * `'singleton' => false`, the way {@see CreateMailAttachmentLinkCommandHandler} has one.
 */
final readonly class CanCreateMailAttachmentLinkCommandHandler
{
	public function __construct(
		private MailAttachmentExternalLinkService $service,
	)
	{
	}

	public function __invoke(CanCreateMailAttachmentLinkCommand $command): Result
	{
		return (new Result())->setData([
			'available' => $this->service->isCreationAvailable(),
		]);
	}
}
