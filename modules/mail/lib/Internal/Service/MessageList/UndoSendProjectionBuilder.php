<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MessageList;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Type\DateTime;

/**
 * A row for a letter on its way out that can still be called back. It names its recipients where a
 * message names its sender and carries the moment the screen asks for the list again at.
 *
 * The screen builds the same record when a letter leaves the compose form, so both sides fill one
 * shape.
 */
class UndoSendProjectionBuilder
{
	/** Used while the dispatcher has named no attempt yet: the row is drawn before the queue picks it up. */
	private const DEFAULT_REFRESH_DELAY = 15;

	public function __construct(
		private readonly DatePresenter $datePresenter = new DatePresenter(),
	)
	{
	}

	/**
	 * @param array<int, array<string, mixed>> $items rows of the operations, as the service reads them
	 * @return array<int, array<string, mixed>>
	 */
	public function build(array $items): array
	{
		$rows = [];

		foreach ($items as $item)
		{
			$row = $this->buildRow((array)$item);
			if ($row !== null)
			{
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * @return array<string, mixed>|null null for an operation without an identifier of its own
	 */
	private function buildRow(array $item): ?array
	{
		$operationId = (int)($item['ID'] ?? 0);
		if ($operationId <= 0)
		{
			return null;
		}

		$subject = trim((string)($item['SUBJECT'] ?? ''));

		return [
			'id' => 'undo-send-' . $operationId,
			'operationId' => $operationId,
			'sender' => $this->presentRecipients($item),
			'subject' => $subject !== ''
				? $subject
				: (string)Loc::getMessage('MAIL_MESSAGE_EMPTY_SUBJECT_PLACEHOLDER'),
			'status' => (string)Loc::getMessage('MAIL_SCHEDULED_LIST_STATUS_SENDING'),
			'date' => $this->datePresenter->presentWithoutTimestamp($this->resolveCreatedAt($item)),
			'refreshAt' => $this->resolveRefreshAt($item),
		];
	}

	/** All recipients in one line, the way the sender cell carries one. */
	private function presentRecipients(array $item): string
	{
		$names = [];

		foreach (['to', 'cc', 'bcc'] as $type)
		{
			foreach ((array)($item['RECIPIENTS'][$type] ?? []) as $recipient)
			{
				$name = is_array($recipient)
					? $this->presentAddress($recipient)
					: trim((string)$recipient)
				;
				if ($name !== '')
				{
					$names[] = $name;
				}
			}
		}

		return $names === []
			? (string)Loc::getMessage('MAIL_MESSAGE_EMPTY_RCPT')
			: implode(
				(string)Loc::getMessage('MAIL_MESSAGE_SEPARATOR_OF_NAMES_AND_EMAILS_IN_LISTS'),
				$names,
			)
		;
	}

	private function presentAddress(array $address): string
	{
		$name = trim((string)($address['name'] ?? ''));

		return $name !== '' ? $name : trim((string)($address['email'] ?? ''));
	}

	private function resolveCreatedAt(array $item): ?int
	{
		$createdAt = $item['CREATED_AT'] ?? null;

		return $createdAt instanceof DateTime
			? $createdAt->getTimestamp() + \CTimeZone::getOffset()
			: null
		;
	}

	/** The screen reads this off the row and asks for the list again a couple of seconds past it. */
	private function resolveRefreshAt(array $item): int
	{
		$nextAttemptAt = $item['NEXT_ATTEMPT_AT'] ?? null;
		$refreshAt = $nextAttemptAt instanceof DateTime
			? $nextAttemptAt->getTimestamp()
			: time() + self::DEFAULT_REFRESH_DELAY
		;

		return $refreshAt * 1000;
	}
}
