<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Disk;

use Bitrix\Main\Result;

/**
 * Consumer contract of the mail module towards the mail attachment link commands of Disk.
 *
 * The gateway is the only place the commands of the disk module are called from, which keeps the storage
 * adapter substitutable in tests. Neither the signatures nor the results reference types of the disk
 * module: the mail module has to compile and to be testable without disk. A refusal of the disk side
 * arrives already translated into an error code of the mail module.
 */
interface MailAttachmentLinkGateway
{
	/**
	 * Tells whether creating the service link is allowed, which is asked before anything is copied to Disk.
	 *
	 * On success the result data holds the answer under the "available" key (bool).
	 */
	public function canCreate(): Result;

	/**
	 * Creates the service link of the given Disk object, or returns the already created one.
	 *
	 * On success the result data holds the link id under the "externalLinkId" key (int) and the public
	 * address of the link under the "url" key (string).
	 *
	 * A failure may carry the same "externalLinkId" key as well: the link was created, but the answer of
	 * the command could not be used. The caller owns the cleanup and must delete such a link.
	 *
	 * @param int $userId sender the link is created on behalf of; a server side value, never from a request
	 * @param int $objectId file or batch folder inside the mail attachments folder tree of the sender
	 */
	public function create(int $userId, int $objectId): Result;

	/**
	 * Whether the link this gateway creates carries the mark of the mail attachments scenario.
	 *
	 * The storage checks the link it has just created against what it asked for, and the mark belongs to
	 * that check — but only for the gateway that promises it. The answer is a property of the gateway and
	 * not of the portal: a set created before the update of Disk keeps a link without the mark, and it
	 * stays serviceable.
	 */
	public function createsServiceLink(): bool;
}

