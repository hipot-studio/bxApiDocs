<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Helper;
use Bitrix\Mail\Helper\Label\LabelsFeature;
use Bitrix\Mail\Internal\Service\Label\LabelCountersService;
use Bitrix\Mail\Internals\MailboxDirectoryTable;
use Bitrix\Mail\Internals\MailCounterTable;
use Bitrix\Mail\Internals\MessageLabelTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Application;
use Bitrix\Main\ORM\Fields\ExpressionField;

/**
 * The stored unread counters of a mailbox right after it changed hands (ALG-02).
 *
 * Every counter of the module is a number counted once and stored, and the placements it was
 * counted over belong to a generation: the final synchronization of the retained source runs
 * moments before the switch, so the numbers it stored describe a source the user no longer
 * has. The counter of a folder is the exception - a folder row carries its own generation and
 * every reading path restricts itself to the active one, so those rows tell the generations
 * apart by themselves. The mailbox counter, the global badge summed from it and the counters
 * of the labels have no generation of their own and would keep answering for the retained
 * source until the next synchronization, the labels until a binding of theirs changes.
 *
 * They are recounted here rather than dropped: a dropped counter reads as a zero, which is a
 * wrong number as well, and the recount is a handful of queries over rows already stored - the
 * switch stays as instant as it was. Nothing here talks to the mail server: the counters of the
 * folders of the new source come from its first synchronization, as they always have.
 */
final class SwitchedCounters
{
	/**
	 * Must run after the pointer cache of the mailbox is invalidated: what the counters are
	 * recounted over is the scope of the generation the pointer names now.
	 */
	public function recount(int $mailboxId): void
	{
		if ($mailboxId <= 0)
		{
			return;
		}

		$this->guard(fn () => $this->recountLabels($mailboxId));
		$this->guard(function () use ($mailboxId): void {
			if ($this->recountMailbox($mailboxId))
			{
				$this->recountGlobalCounters($mailboxId);
			}
		});
	}

	/**
	 * The switch has already happened: a counter that failed to recount never takes it back,
	 * and never keeps the other counters from being recounted either.
	 */
	private function guard(callable $step): void
	{
		try
		{
			$step();
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
	}

	/**
	 * @return bool Whether the stored counter of the mailbox had to change.
	 */
	private function recountMailbox(int $mailboxId): bool
	{
		$stored = MailCounterTable::getRow([
			'select' => ['VALUE'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'=ENTITY_TYPE' => MailCounterTable::MAILBOX,
				'=ENTITY_ID' => (string)$mailboxId,
			],
		]);

		$counted = $this->sumFoldersOfTheActiveGeneration($mailboxId);

		if ((int)($stored['VALUE'] ?? 0) === $counted)
		{
			return false;
		}

		Helper::setMailboxUnseenCounter($mailboxId, $counted);

		return true;
	}

	/**
	 * The counters of the folders serving the user now. A folder of the new source that has
	 * no counter row yet counts as none: its number is the business of the first
	 * synchronization of that source, and a missing row is a zero, not a licence to keep the
	 * sum of the source that has been left.
	 */
	private function sumFoldersOfTheActiveGeneration(int $mailboxId): int
	{
		$filter = [
			'=MAILBOX_ID' => $mailboxId,
			'=ENTITY_TYPE' => MailCounterTable::DIR,
		];

		$scope = GenerationScope::servingMailbox($mailboxId);
		if (!$scope->isUnfiltered())
		{
			/*
				The folders are named in the query and not joined to it: ENTITY_ID of a counter is a
				string while the id of a folder is an integer, and no portable comparison of the two
				exists. A mailbox holds units of folders, so the list stays short.
			*/
			$folderIds = array_column(
				MailboxDirectoryTable::getList([
					'select' => ['ID'],
					'filter' => $scope->apply(['=MAILBOX_ID' => $mailboxId]),
				])->fetchAll(),
				'ID',
			);

			if ($folderIds === [])
			{
				return 0;
			}

			$filter['@ENTITY_ID'] = array_map('strval', $folderIds);
		}

		$row = MailCounterTable::getRow([
			'runtime' => [new ExpressionField('UNSEEN', 'SUM(%s)', ['VALUE'])],
			'select' => ['UNSEEN'],
			'filter' => $filter,
		]);

		return max(0, (int)($row['UNSEEN'] ?? 0));
	}

	/**
	 * The badge of every user the mailbox is on the screen of: it sums the stored counters of
	 * their mailboxes, so it follows the one recounted above. The owner is refreshed together
	 * with the users the mailbox is shared with - a mailbox nobody shared still has a badge.
	 */
	private function recountGlobalCounters(int $mailboxId): void
	{
		$mailbox = MailboxTable::getList([
			'select' => ['LID', 'USER_ID'],
			'filter' => ['=ID' => $mailboxId],
		])->fetch();

		if (!$mailbox)
		{
			return;
		}

		$userIds = array_map(
			'intval',
			Helper\Mailbox\SharedMailboxesManager::getUserIdsWithAccessToMailbox($mailboxId),
		);
		$userIds[] = (int)$mailbox['USER_ID'];

		$userIds = array_unique(array_filter($userIds, static fn (int $userId): bool => $userId > 0));

		foreach ($userIds as $userId)
		{
			Helper\Message::resetCountersCache($userId);
			Helper\Message::setUserUnseenCounter($userId, (string)$mailbox['LID']);
		}
	}

	/**
	 * A label counts the unread letters of the placements of the active generation, and the
	 * badge of it is stored: with the feature off nothing here queries the database.
	 */
	private function recountLabels(int $mailboxId): void
	{
		if (!LabelsFeature::isEnabled())
		{
			return;
		}

		$labelIds = array_column(
			MessageLabelTable::getList([
				'select' => ['LABEL_ID'],
				'filter' => ['=MAILBOX_ID' => $mailboxId],
				'group' => ['LABEL_ID'],
			])->fetchAll(),
			'LABEL_ID',
		);

		if ($labelIds === [])
		{
			return;
		}

		(new LabelCountersService())->recalculateForLabels($mailboxId, array_map('intval', $labelIds));
	}
}
