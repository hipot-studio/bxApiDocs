<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service;

use Bitrix\Main\Mail\Sender;

final class SenderCacheCleaner
{
	/** @param class-string $senderClass */
	public function __construct(private readonly string $senderClass = Sender::class)
	{
	}

	/**
	 * @param string[] $emails
	 * @param array<int, array{parentModuleId: string, parentId: int}> $identities
	 */
	public function clearForSender(int $senderId, array $emails, array $identities = []): void
	{
		$senderClass = $this->senderClass;
		$emails = array_values(array_unique(array_filter(
			$emails,
			static fn (mixed $email): bool => is_string($email) && $email !== '',
		)));

		if (!method_exists($senderClass, 'clearSenderCache'))
		{
			if (!method_exists($senderClass, 'clearCustomSmtpCache'))
			{
				return;
			}

			foreach ($emails as $email)
			{
				$senderClass::clearCustomSmtpCache($email);
			}

			return;
		}

		if ($emails === [])
		{
			$senderClass::clearSenderCache($senderId);
		}
		else
		{
			foreach ($emails as $email)
			{
				$senderClass::clearSenderCache($senderId, $email);
			}
		}

		if (!method_exists($senderClass, 'clearIdentitySenderCache'))
		{
			return;
		}

		$uniqueIdentities = [];
		foreach ($identities as $identity)
		{
			$parentModuleId = $identity['parentModuleId'];
			$parentId = $identity['parentId'];
			$uniqueIdentities[$parentModuleId . ':' . $parentId] = [$parentModuleId, $parentId];
		}

		if ($uniqueIdentities === [])
		{
			$senderClass::clearIdentitySenderCache($senderId);

			return;
		}

		foreach ($uniqueIdentities as [$parentModuleId, $parentId])
		{
			$senderClass::clearIdentitySenderCache($senderId, $parentModuleId, $parentId);
		}
	}
}
