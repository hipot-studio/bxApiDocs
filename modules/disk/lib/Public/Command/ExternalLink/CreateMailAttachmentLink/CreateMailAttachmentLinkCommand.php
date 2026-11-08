<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\ExternalLink\CreateMailAttachmentLink;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Creates the service external link of a mail attachment object, or returns the already created one.
 *
 * The only cross-module entry point of the mail attachments scenario.
 * Result data on success: ['externalLinkId' => int, 'url' => string].
 *
 * The module has to be loaded before the call (`Loader::includeModule('disk')`): the handler is built by
 * the service registrations of the module, and without them the call throws instead of answering with an
 * error code.
 *
 * @see CreateMailAttachmentLinkError for the error codes
 */
final class CreateMailAttachmentLinkCommand extends AbstractCommand
{
	/**
	 * @param int $userId user the link is created on behalf of; their storage defines the scope. A server
	 *   side value which must never be taken from the request: the rights of the caller on the object are
	 *   not asked. Only the current user is accepted, unless the caller declares the system context.
	 * @param int $objectId file or batch folder inside the mail attachments folder tree
	 * @param DateTime|null $deathTime link never expires unless it is passed
	 * @param bool $bySystem the caller acts on behalf of the portal itself: a queue, an agent or a CLI
	 *   script, where there is no current user to compare the userId with. A caller serving a request must
	 *   never pass it, an unauthorized visitor looks the same way.
	 */
	public function __construct(
		public readonly int $userId,
		public readonly int $objectId,
		public readonly ?DateTime $deathTime = null,
		public readonly bool $bySystem = false,
	)
	{
	}

	protected function execute(): Result
	{
		return ServiceLocator::getInstance()->get(CreateMailAttachmentLinkCommandHandler::class)($this);
	}
}
