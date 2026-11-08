<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Internals\MailboxSourceGenerationTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Mail\MailServicesTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;

/**
 * Resolves service names for generations accepted before the corresponding service row was installed.
 * Active generation and mailbox service identifiers are updated atomically.
 */
final class LateServiceBinder
{
	/**
	 * Processes at most $limit matches within a bounded primary-key range.
	 *
	 * The range bounds the unindexed scan; the limit bounds per-generation locking and writes.
	 *
	 * @return array{bound: int, deferred: int, reachedId: int}
	 */
	public function bindGenerationsOfWindow(int $afterId, int $upToId, int $limit): array
	{
		$limit = max(1, $limit);
		$generations = $this->findGenerationsOwedANumber($afterId, $upToId, $limit);
		$counted = ['bound' => 0, 'deferred' => 0, 'reachedId' => $upToId];

		if (count($generations) >= $limit)
		{
			// Continue the same window after the last processed generation.
			$counted['reachedId'] = (int)$generations[array_key_last($generations)]['ID'];
		}

		foreach ($generations as $generation)
		{
			$outcome = $this->bind(
				(int)$generation['MAILBOX_ID'],
				(int)$generation['ID'],
				(string)$generation['OPERATION_ID'],
				(string)$generation['SERVICE_NAME'],
			);

			$counted['bound'] += $outcome === LateBindingOutcome::Bound ? 1 : 0;
			$counted['deferred'] += $outcome === LateBindingOutcome::Deferred ? 1 : 0;
		}

		return $counted;
	}

	/** Returns the upper bound captured by a new background walk. */
	public function lastGenerationId(): int
	{
		$last = MailboxSourceGenerationTable::query()
			->addSelect(new ExpressionField('LAST_ID', 'MAX(%s)', 'ID'))
			->fetch()
		;

		return (int)($last['LAST_ID'] ?? 0);
	}

	/**
	 * Fills a missing generation service and its active mailbox projection under the mailbox lock.
	 * Existing identifiers are preserved; when only the mailbox is missing one, the generation wins.
	 * Both missing values are written in one transaction.
	 *
	 * @param array{ID?: int|string, SERVICE_ID?: int|string|null} $generation
	 * @param array{SERVICE_ID?: int|string|null, ACTIVE_GENERATION_ID?: int|string|null} $mailbox
	 * @return Result Data contains ['filled' => bool].
	 */
	public function fill(int $mailboxId, int $serviceId, array $generation, array $mailbox): Result
	{
		$result = (new Result())->setData(['filled' => false]);
		$generationId = (int)($generation['ID'] ?? 0);
		$carried = (int)($generation['SERVICE_ID'] ?? 0);

		// The mailbox must remain a projection of its active generation.
		$number = $carried > 0 ? $carried : $serviceId;

		if ($generationId <= 0 || $number <= 0)
		{
			return $result;
		}

		$owedByGeneration = $carried <= 0;
		$owedByMailbox = (int)($mailbox['ACTIVE_GENERATION_ID'] ?? 0) === $generationId
			&& (int)($mailbox['SERVICE_ID'] ?? 0) <= 0
		;

		if (!$owedByGeneration && !$owedByMailbox)
		{
			return $result;
		}

		if (!$owedByGeneration && !$this->directoryStillHolds($number))
		{
			// Do not restore a service removed after a partial binding.
			return $result;
		}

		if ($owedByGeneration && $owedByMailbox)
		{
			$written = $this->writeBothSides($mailboxId, $generationId, $number);
		}
		else
		{
			$written = $owedByGeneration
				? MailboxSourceGenerationTable::update($generationId, ['SERVICE_ID' => $number])
				: MailboxTable::update($mailboxId, ['SERVICE_ID' => $number])
			;
		}

		return $written->isSuccess()
			? $result->setData(['filled' => true])
			: $result->addErrors($written->getErrors())
		;
	}

	/** Writes the generation and mailbox projection atomically, including inside an outer transaction. */
	private function writeBothSides(int $mailboxId, int $generationId, int $serviceId): Result
	{
		$result = new Result();
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$filled = MailboxSourceGenerationTable::update($generationId, ['SERVICE_ID' => $serviceId]);
			if (!$filled->isSuccess())
			{
				$this->rollback($connection);

				return $result->addErrors($filled->getErrors());
			}

			$projected = MailboxTable::update($mailboxId, ['SERVICE_ID' => $serviceId]);
			if (!$projected->isSuccess())
			{
				$this->rollback($connection);

				return $result->addErrors($projected->getErrors());
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$this->rollback($connection);

			return $result->addError(new Error($exception->getMessage()));
		}

		return $result;
	}

	private function directoryStillHolds(int $serviceId): bool
	{
		return (bool)MailServicesTable::getList([
			'select' => ['ID'],
			'filter' => ['=ID' => $serviceId],
			'limit' => 1,
		])->fetch();
	}

	private function rollback(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (\Throwable)
		{
			// A transaction of a caller has already taken the writes back, or refuses to unwind
			// itself from the inside - either way there is nothing of ours left to undo
		}
	}

	/**
	 * Finds preparing or active generations without a service and active partial bindings.
	 * Preparing generations are included so a later switch cannot project a zero service identifier.
	 *
	 * @return array<int, array{ID: int|string, MAILBOX_ID: int|string, OPERATION_ID: string, SERVICE_NAME: string|null}>
	 */
	private function findGenerationsOwedANumber(int $afterId, int $upToId, int $limit): array
	{
		return MailboxSourceGenerationTable::query()
			->setSelect(['ID', 'MAILBOX_ID', 'OPERATION_ID', 'SERVICE_NAME'])
			->registerRuntimeField(new Reference(
				'MAILBOX',
				MailboxTable::class,
				Join::on('this.MAILBOX_ID', 'ref.ID'),
				['join_type' => Join::TYPE_LEFT],
			))
			->where('ID', '>', $afterId)
			->where('ID', '<=', $upToId)
			->where(
				Query::filter()
					->logic('or')
					->where(
						Query::filter()
							->where('SERVICE_ID', 0)
							->whereIn('STATUS', [
								MailboxSourceGenerationTable::STATUS_PREPARING,
								MailboxSourceGenerationTable::STATUS_ACTIVE,
							])
							->whereNotNull('SERVICE_NAME')
							->where('SERVICE_NAME', '!=', ''),
					)
					->where(
						Query::filter()
							->where('SERVICE_ID', '>', 0)
							->where('STATUS', MailboxSourceGenerationTable::STATUS_ACTIVE)
							->where('MAILBOX.SERVICE_ID', 0)
							->whereColumn('MAILBOX.ACTIVE_GENERATION_ID', 'ID'),
					),
			)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;
	}

	/**
	 * Journals unresolved generations within the same scan bounds used for binding.
	 *
	 * @return array{named: int, reachedId: int}
	 */
	public function journalGenerationsLeftBehind(int $afterId, int $upToId, int $limit): array
	{
		$limit = max(1, $limit);
		$left = $this->findGenerationsOwedANumber($afterId, $upToId, $limit);
		$counted = ['named' => count($left), 'reachedId' => $upToId];

		if (count($left) >= $limit)
		{
			// Continue the same window after the last journaled generation.
			$counted['reachedId'] = (int)$left[array_key_last($left)]['ID'];
		}

		foreach ($left as $generation)
		{
			MigrationMetrics::journalOperation(
				'service number given up on, the walk is over',
				(int)$generation['MAILBOX_ID'],
				(string)$generation['OPERATION_ID'],
				(int)$generation['ID'],
			);
		}

		return $counted;
	}

	/**
	 * Binds one generation while holding its mailbox lock and re-reading both rows under that lock.
	 * Service names are resolved in the mailbox site directory.
	 */
	private function bind(
		int $mailboxId,
		int $generationId,
		string $operationId,
		string $serviceName,
	): LateBindingOutcome
	{
		if (!OperationLock::acquire($mailboxId))
		{
			MigrationMetrics::journalOperation(
				'service number deferred, the mailbox is busy',
				$mailboxId,
				$operationId,
				$generationId,
			);

			return LateBindingOutcome::Deferred;
		}

		try
		{
			$generation = MailboxSourceGenerationTable::getList([
				'select' => ['ID', 'SERVICE_ID'],
				'filter' => ['=ID' => $generationId],
				'limit' => 1,
			])->fetch();

			$mailbox = MailboxTable::getList([
				'select' => ['ID', 'LID', 'SERVICE_ID', 'ACTIVE_GENERATION_ID'],
				'filter' => ['=ID' => $mailboxId],
				'limit' => 1,
			])->fetch();

			if (!$generation || !$mailbox)
			{
				return LateBindingOutcome::Skipped;
			}

			$serviceId = (int)$generation['SERVICE_ID'];
			if ($serviceId <= 0)
			{
				$serviceId = MailServicesTable::findImapServiceId((string)$mailbox['LID'], $serviceName);
			}

			if ($serviceId <= 0)
			{
				return LateBindingOutcome::Skipped;
			}

			$filled = $this->fill($mailboxId, $serviceId, $generation, $mailbox);
			if ($filled->isSuccess())
			{
				return ($filled->getData()['filled'] ?? false)
					? LateBindingOutcome::Bound
					: LateBindingOutcome::Skipped
				;
			}

			// Keep the generation eligible for a later pass and record the failed write.
			MigrationMetrics::journalOperation(
				'service number not filled in',
				$mailboxId,
				$operationId,
				$generationId,
				null,
				implode('; ', $filled->getErrorMessages()),
			);

			return LateBindingOutcome::Deferred;
		}
		finally
		{
			OperationLock::release($mailboxId);
		}
	}
}
