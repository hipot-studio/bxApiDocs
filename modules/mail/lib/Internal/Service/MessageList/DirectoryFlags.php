<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MessageList;

use Bitrix\Mail\Internals\Entity\MailboxDirectory;

/** What a row asks of its folder. A folder the screen does not know answers as an ordinary one. */
final class DirectoryFlags
{
	public function __construct(
		public readonly bool $isSpam = false,
		public readonly bool $isTrash = false,
	)
	{
	}

	public static function fromDirectory(?MailboxDirectory $directory): self
	{
		if ($directory === null)
		{
			return new self();
		}

		return new self($directory->isSpam(), $directory->isTrash());
	}
}
