<?php

declare(strict_types=1);

namespace Bitrix\MailMobile\Internal\Services;

/**
 * The label mode of the message list: a virtual folder that shows every message of one label instead
 * of the messages of a physical folder.
 */
final class LabelListMode
{
	public const VIRTUAL_FOLDER_LABEL = 'label';

	/**
	 * The label the list is asked for, or null when the mode is not active. A label of another user, a
	 * label of another mailbox and a deleted label are indistinguishable from outside and all give null:
	 * the answer is built from what $labelsLoader reports for this reader in this mailbox. The loader is
	 * injected rather than resolved here so this internal service does not carry a dependency on the
	 * integration layer; the caller (the Transport controller) supplies it from the label catalog.
	 *
	 * @return array{id: int, name: string, mailboxId: int, sort: int, unread: int}|null
	 */
	public static function resolve(
		int $userId,
		int $mailboxId,
		?string $virtualFolderKey,
		?int $labelId,
		bool $available,
		callable $labelsLoader,
	): ?array
	{
		if (
			!$available
			|| $virtualFolderKey !== self::VIRTUAL_FOLDER_LABEL
			|| $labelId === null
			|| $labelId <= 0
			|| $userId <= 0
			|| $mailboxId <= 0
		)
		{
			return null;
		}

		foreach ($labelsLoader($userId, $mailboxId) as $label)
		{
			if ((int)($label['id'] ?? 0) === $labelId)
			{
				return $label;
			}
		}

		return null;
	}

	/**
	 * A filter by label excludes a filter by folder: the label gathers messages of the whole mailbox,
	 * while spam and trash are cut off by MessageFilter::addLabel() itself.
	 */
	public static function prepareFilterParams(array $filterParams, int $labelId): array
	{
		unset(
			$filterParams['DIR'],
			$filterParams['MD5_DIRS'],
			$filterParams['EXCLUDE_MD5_DIRS'],
			$filterParams['IS_FAVORITE'],
		);

		$filterParams['LABEL_ID'] = $labelId;

		return $filterParams;
	}
}
