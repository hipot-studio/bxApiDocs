<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\ScheduledMessage;

use Bitrix\Main\Error;
use Bitrix\Main\Mail\Sender;
use Bitrix\Main\Result;

/**
 * The address a delayed letter leaves from, asked about by both sides of the feature: the form learns a refusal
 * while it is still open, and the moment of sending asks the same question again. The list is the one the form
 * itself offers and the one the immediate sending holds an address to, so an alias or a confirmed sender of the
 * author is as good here as the address of the mailbox. Which mailbox the letter leaves through is a question of
 * its own and is answered by the caller.
 */
class ScheduledMessageSenderChecker
{
	public const ERROR_SENDER_UNAVAILABLE = 'sender_unavailable';

	/**
	 * A batch of the dispatcher is usually a batch of one author, and every address of that author costs the
	 * same reading of the senders of the portal: the answer is kept for the whole life of the checker.
	 *
	 * @var array<int, string[]>
	 */
	private array $availableAddresses = [];

	public function check(int $userId, string $email): Result
	{
		$email = $this->normalize($email);
		if ($email === '' || !in_array($email, $this->getAvailableAddresses($userId), true))
		{
			return (new Result())->addError(
				new Error('Sender is not available to the author.', self::ERROR_SENDER_UNAVAILABLE),
			);
		}

		return new Result();
	}

	/**
	 * The senders the portal offers the author, as the form of the letter reads them.
	 *
	 * @return string[]
	 */
	protected function loadAvailableAddresses(int $userId): array
	{
		return array_map(
			static fn (array $sender): string => (string)($sender['email'] ?? ''),
			Sender::prepareUserMailboxes($userId),
		);
	}

	/** @return string[] */
	private function getAvailableAddresses(int $userId): array
	{
		return $this->availableAddresses[$userId] ??= array_values(array_unique(array_filter(
			array_map($this->normalize(...), $this->loadAvailableAddresses($userId)),
			static fn (string $address): bool => $address !== '',
		)));
	}

	private function normalize(string $email): string
	{
		return mb_strtolower(trim($email));
	}
}
