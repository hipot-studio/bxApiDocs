<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Repository;

use Bitrix\Mail\Internals\VacationAutoReplyTable;
use Bitrix\Main\Type\DateTime;

class VacationAutoReplyRepository
{
	/**
	 * Stored row of the mailbox, or null when the mailbox has no setting yet.
	 *
	 * @return array<string, mixed>|null
	 */
	public function findByMailboxId(int $mailboxId): ?array
	{
		$row = VacationAutoReplyTable::getRow([
			'select' => ['*'],
			'filter' => ['=MAILBOX_ID' => $mailboxId],
		]);

		return $row ?? null;
	}

	/**
	 * Writes the setting of the mailbox, creating the row on the first save. CREATED_AT and UPDATED_AT
	 * belong to the row rather than to the setting, so they are stamped here and not by the caller.
	 *
	 * @param array<string, mixed> $fields
	 */
	public function save(int $mailboxId, array $fields): bool
	{
		$now = new DateTime();
		$fields['UPDATED_AT'] = $now;

		if ($this->findByMailboxId($mailboxId) === null)
		{
			$fields['MAILBOX_ID'] = $mailboxId;
			$fields['CREATED_AT'] = $now;

			return VacationAutoReplyTable::add($fields)->isSuccess();
		}

		return VacationAutoReplyTable::update($mailboxId, $fields)->isSuccess();
	}
}
