<?php

namespace Bitrix\Sender\Integration\Sender\Mail;

use Bitrix\Main\Mail;

final class SenderIdentityResolver
{
	public static function resolve(int $ownerId, string $email): ?Mail\Sender\Identity
	{
		if ($ownerId <= 0 || $email === '' || !class_exists(Mail\Sender\Identity::class))
		{
			return null;
		}

		return self::resolveByAvailableMainApi(
			$ownerId,
			$email,
			method_exists(Mail\Sender::class, 'resolveIdentityForOwner'),
		);
	}

	private static function resolveByAvailableMainApi(
		int $ownerId,
		string $email,
		bool $hasOwnerResolver,
	): ?Mail\Sender\Identity
	{
		if ($hasOwnerResolver)
		{
			return Mail\Sender::resolveIdentityForOwner($ownerId, $email);
		}

		$senders = Mail\Sender::getByEmail($email, $ownerId);
		if ($senders === [])
		{
			return null;
		}

		usort($senders, static fn(array $left, array $right): int => (int)$left['ID'] <=> (int)$right['ID']);
		$sender = $senders[0];
		if ($sender['PARENT_MODULE_ID'] === 'mail' && (int)$sender['PARENT_ID'] > 0)
		{
			return Mail\Sender\Identity::fromMailbox((int)$sender['PARENT_ID']);
		}

		return Mail\Sender\Identity::fromSender((int)$sender['ID']);
	}
}
