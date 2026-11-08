<?php

namespace Bitrix\MailMobile\Internal\Services;

use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Mail\Helper\MailboxDirectoryHelper;
use Bitrix\Mail\Internals\Entity\MailboxDirectory;

final readonly class MessageListContext
{
	public function __construct(
		public Mailbox $mailbox,
		public MailboxDirectoryHelper $directories,
		public MailboxDirectory $defaultDirectory,
		public ?MailboxDirectory $selectedDirectory,
		public int $mailboxId,
		public int $userId,
		public bool $favoritesEnabled,
		public array $accessibleMailboxIds,
		public array $excludedDirectoryHashes,
		public array $outcomeDirectoryHashes,
		public ?string $providerRestriction,
		public int $unseenCounter,
	)
	{
	}
}
