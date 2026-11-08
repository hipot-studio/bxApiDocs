<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\Compose;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Main\Mail\Sender;
use Bitrix\Main\Mail\Sender\UserSenderDataProvider;

/**
 * Senders of the compose form, in one shape for everything the form reads: the initial data and the
 * signatures it asks for again after the settings slider is closed. Both have to see the same list,
 * otherwise a mailbox present in one of them is missing from the other.
 *
 * Connections are kept apart even when they share an address, as the form tells them by mailbox id.
 * An identity repeating the name and address of a mailbox is dropped, the way the deduplicated list
 * did before: scheduled sending and undo carry the mailbox of the form, so such a choice would leave
 * through the mailbox.
 */
class SenderListProvider
{
	public function getSenders(?int $userId = null): array
	{
		// Connections of one address are told apart by the avatar of the mailbox, so without that avatar
		// the form keeps the deduplicated list it had before and shows no indistinguishable twins.
		if (!$this->isMailboxAvatarAvailable())
		{
			return $this->loadDeduplicatedSenders($userId);
		}

		return $this->dropIdentitiesShadowedByMailboxes($this->loadSenders($userId));
	}

	protected function isMailboxAvatarAvailable(): bool
	{
		return Feature::isMailboxAvatarAvailable();
	}

	protected function loadSenders(?int $userId): array
	{
		if (method_exists(UserSenderDataProvider::class, 'getUserAvailableSenderIdentities'))
		{
			return array_values(UserSenderDataProvider::getUserAvailableSenderIdentities($userId));
		}

		return $this->loadDeduplicatedSenders($userId);
	}

	protected function loadDeduplicatedSenders(?int $userId): array
	{
		return array_values(Sender::prepareUserMailboxes($userId));
	}

	private function dropIdentitiesShadowedByMailboxes(array $senders): array
	{
		$mailboxSenders = [];
		foreach ($senders as $sender)
		{
			if ((int)($sender['mailboxId'] ?? 0) > 0)
			{
				$mailboxSenders[(string)($sender['formated'] ?? '')] = true;
			}
		}

		$kept = array_filter(
			$senders,
			static fn(array $sender): bool => (int)($sender['mailboxId'] ?? 0) > 0
				|| !isset($mailboxSenders[(string)($sender['formated'] ?? '')]),
		);

		return array_values($kept);
	}
}
