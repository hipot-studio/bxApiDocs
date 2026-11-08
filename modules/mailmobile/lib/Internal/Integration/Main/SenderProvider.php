<?php

namespace Bitrix\MailMobile\Internal\Integration\Main;

use Bitrix\Main\Mail\Sender;

class SenderProvider
{
	public function getUserAvailableSenders(?int $userId = null): array
	{
		return Sender::prepareUserMailboxes($userId);
	}

	/**
	 * Sender list enriched by the core with the signatures its subscribers resolved.
	 *
	 * Modules reach a portal independently, so mailmobile can be updated earlier than the main
	 * version that publishes the signature API. Without that method the sender list is still built,
	 * just without signatures. Drop the fallback together with hasCoreSignatureContext() once the
	 * main version carrying prepareUserMailboxesWithSignatures() is delivered to clients.
	 *
	 * @return array{
	 *     senders: array<int, array<string, mixed>>,
	 *     signatures?: array<int, array<string, mixed>>,
	 *     signaturesBySender?: array<string, array<string, mixed>>
	 * }
	 */
	public function getUserAvailableSendersWithSignatures(?int $userId = null): array
	{
		if (!$this->hasCoreSignatureContext())
		{
			return ['senders' => $this->getUserAvailableSenders($userId)];
		}

		return Sender::prepareUserMailboxesWithSignatures($userId);
	}

	protected function hasCoreSignatureContext(): bool
	{
		return method_exists(Sender::class, 'prepareUserMailboxesWithSignatures');
	}
}
