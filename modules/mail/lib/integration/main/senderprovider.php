<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Main;

use Bitrix\Main\Mail\Sender\UserSenderDataProvider;

class SenderProvider
{
	/**
	 * Returns sender addresses available to the user, normalized to a flat shape.
	 *
	 * @return array<int, array{email: string, name: string, sender: string}>
	 */
	public static function getAvailableSenders(int $userId): array
	{
		$rawSenders = UserSenderDataProvider::getUserAvailableSenders(userId: $userId);

		$senders = [];
		foreach ($rawSenders as $sender)
		{
			$senders[] = [
				'email' => $sender['email'] ?? '',
				'name' => $sender['name'] ?? '',
				'sender' => $sender['formated'] ?? '',
			];
		}

		return $senders;
	}
}
