<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\ExternalLink\CreateMailAttachmentLink;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Result;

/**
 * Tells whether the tariff allows creating the service link for mail attachments.
 *
 * The answer is a hint for the sender before the attachments are uploaded: the creation command checks
 * the tariff on its own anyway. Result data: ['available' => bool]; the command returns no errors.
 *
 * The module has to be loaded before the call (`Loader::includeModule('disk')`): the handler takes the
 * service of the module from its service registration, and without it the call throws.
 *
 * @see CreateMailAttachmentLinkCommand
 */
final class CanCreateMailAttachmentLinkCommand extends AbstractCommand
{
	protected function execute(): Result
	{
		return ServiceLocator::getInstance()->get(CanCreateMailAttachmentLinkCommandHandler::class)($this);
	}
}
