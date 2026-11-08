<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

use Bitrix\Mail\Internal\Entity\SourceGeneration\Context;
use Bitrix\Mail\Internals\MailboxSourceGenerationTable;
use Bitrix\Main\Application;

/**
 * The generation scope of physical data reads and writes: folder maps and caches,
 * uid lookups by physical coordinates and the queues. One shared value instead of
 * scattered GENERATION_ID conditions.
 *
 * Four shapes:
 *  - unfiltered: the mailbox pointer is 0 (not backfilled yet, or the G1 walk is
 *    still in progress and its rows are split between 0 and the G1 id);
 *  - active(P): pointer P > 0, reads cover IN (0, P) - rows with the implicit 0
 *    are written only by generation-unaware code and always belong to the active
 *    source, the backfill adopts them later;
 *  - exact(G): one concrete generation (e.g. the PREPARING G2 of a migration
 *    import), strictly isolated from every other generation;
 *  - withoutPreparedGenerations(mailbox): every generation the mailbox has really
 *    served - the active one and the ones retained by earlier switches - and none
 *    of the ones it is preparing. A read-only shape {@see getStampGenerationId()}.
 */
final class GenerationScope
{
	private const MAILBOX_TABLE = 'b_mail_mailbox';

	private const POINTER_COLUMN = 'ACTIVE_GENERATION_ID';

	/** @var array<int, int> */
	private static array $activePointers = [];

	/** @var array<int, int[]> Mailbox => the generations it is preparing */
	private static array $preparedGenerations = [];

	private static ?bool $pointerColumnExists = null;

	/**
	 * @param int[]|null $generationIds null accepts every generation
	 * @param int[] $excludedGenerationIds Refused generations, only for the null case above
	 */
	private function __construct(
		private readonly ?array $generationIds,
		private readonly int $stampGenerationId,
		private readonly array $excludedGenerationIds = [],
	)
	{
	}

	public static function unfiltered(): self
	{
		return new self(null, 0);
	}

	public static function active(int $activeGenerationId): self
	{
		if ($activeGenerationId <= 0)
		{
			return self::unfiltered();
		}

		return new self([0, $activeGenerationId], $activeGenerationId);
	}

	public static function exact(int $generationId): self
	{
		if ($generationId <= 0)
		{
			return self::unfiltered();
		}

		return new self([$generationId], $generationId);
	}

	/**
	 * The whole history of physical rows of the mailbox except the generations it is
	 * preparing right now.
	 *
	 * The one shape stated by what it refuses, and it is stated that way because the
	 * positive list of the same rows cannot be trusted: the retained generations of a
	 * mailbox are as many as the switches it has been through, and a row whose generation
	 * record has been cleaned away would fall out of such a list and lose the letter it
	 * stands for. The refusal names the few rows that must not be seen and leaves every
	 * other row exactly where the unfiltered read had it.
	 *
	 * For the paths that mean the identity of a letter rather than its physical
	 * coordinates - a letter keeps its identity across a change of the source, so those
	 * paths must reach the retained history - while a prepared generation is hidden from
	 * the user until the switch and decided by the matching of the migration alone.
	 *
	 * Reads only: new rows are never stamped with the generation of this shape, and
	 * {@see getStampGenerationId()} answers 0 for it.
	 */
	public static function withoutPreparedGenerations(int $mailboxId): self
	{
		$prepared = self::readPreparedGenerations($mailboxId);

		return $prepared === [] ? self::unfiltered() : new self(null, 0, $prepared);
	}

	/**
	 * A regular synchronization of a mailbox without a pointer works with everything but the
	 * generations it is preparing: the snapshot of a new source is accepted - its folders written -
	 * before the pass that moves the pointer, and a run accepting every generation treated those
	 * folders as its own, the folder sync of the old source removing the ones its server does not
	 * list. The stamp stays the implicit zero.
	 */
	public static function fromContext(Context $context): self
	{
		if ($context->isMigrationImport())
		{
			return self::exact($context->generationId);
		}

		return $context->generationId > 0
			? self::active($context->generationId)
			: self::withoutPreparedGenerations($context->mailboxId)
		;
	}

	/**
	 * The scope of the active generation of the mailbox, resolved from the
	 * ACTIVE_GENERATION_ID pointer (the default for entries without a context).
	 */
	public static function forMailbox(int $mailboxId): self
	{
		return self::active(self::readActivePointer($mailboxId));
	}

	/**
	 * The scope of the generations serving the mailbox now, as the user is to see them: the
	 * active generation while the mailbox has a pointer, and every generation except the ones
	 * it is preparing while it has none. The default of the folders of a mailbox.
	 *
	 * It differs from {@see forMailbox()} over one window, and the window is real: the folders
	 * of a new source are written when its snapshot is accepted, the pointer is moved by the
	 * pass that follows, and in between an unfiltered read shows the folders of a source the
	 * user has not been handed over to yet.
	 *
	 * A composition of two existing shapes and not a replacement by one of them, because
	 * {@see withoutPreparedGenerations()} alone does not bound a read by the active generation:
	 * on a mailbox that has been switched it would bring the folders of the generations retained
	 * by earlier switches back into the tree.
	 */
	public static function servingMailbox(int $mailboxId): self
	{
		$pointer = self::readActivePointer($mailboxId);

		return $pointer > 0
			? self::active($pointer)
			: self::withoutPreparedGenerations($mailboxId)
		;
	}

	/**
	 * ACTIVE_GENERATION_ID of the mailbox, cached for the process: a read path resolves it once
	 * per request however many scopes it builds. Zero for a mailbox of an installation whose
	 * database has not caught up with the files of the update yet.
	 */
	private static function readActivePointer(int $mailboxId): int
	{
		if ($mailboxId <= 0 || !self::isSchemaInstalled())
		{
			return 0;
		}

		if (!array_key_exists($mailboxId, self::$activePointers))
		{
			// Raw SQL on purpose: the ORM map of another release may not know the column
			self::$activePointers[$mailboxId] = (int)Application::getConnection()->queryScalar(sprintf(
				'SELECT %s FROM %s WHERE ID = %u',
				self::POINTER_COLUMN,
				self::MAILBOX_TABLE,
				$mailboxId,
			));
		}

		return self::$activePointers[$mailboxId];
	}

	/**
	 * The scopes of several mailboxes at once: the pointers unknown so far are read by one
	 * query, so a read path resolves the active generation once per request instead of once
	 * per mailbox (and never once per message).
	 *
	 * @param int[] $mailboxIds
	 * @return array<int, self> Mailbox id => its scope, in the order of the argument.
	 */
	public static function forMailboxes(array $mailboxIds): array
	{
		$mailboxIds = array_values(array_unique(array_filter(array_map('intval', $mailboxIds))));

		$unknown = array_diff($mailboxIds, array_keys(self::$activePointers));
		if ($unknown !== [])
		{
			// A mailbox missing from the table keeps the pointer 0, as a single lookup would give
			foreach ($unknown as $mailboxId)
			{
				self::$activePointers[$mailboxId] = 0;
			}

			$rows = self::isSchemaInstalled()
				? Application::getConnection()->query(sprintf(
					'SELECT ID, %s FROM %s WHERE ID IN (%s)',
					self::POINTER_COLUMN,
					self::MAILBOX_TABLE,
					implode(', ', array_map('intval', $unknown)),
				))->fetchAll()
				: []
			;

			foreach ($rows as $row)
			{
				self::$activePointers[(int)$row['ID']] = (int)$row[self::POINTER_COLUMN];
			}
		}

		$scopes = [];
		foreach ($mailboxIds as $mailboxId)
		{
			$scopes[$mailboxId] = self::active(self::$activePointers[$mailboxId]);
		}

		return $scopes;
	}

	/**
	 * Whether the schema of the feature is installed at all.
	 *
	 * The pointer column arrives with the update that brings the generations, and until it
	 * does every path must behave exactly as before the feature: files of a Bitrix update
	 * reach the installation before its database. Public so that a caller which would do
	 * work of its own before asking for a scope - a lookup of the owning mailbox, a read of
	 * the generation table - can skip it as well. The columns of a table are cached by the
	 * connection, so the answer costs one query per process at most.
	 */
	public static function isSchemaInstalled(): bool
	{
		return self::$pointerColumnExists ??= isset(
			Application::getConnection()->getTableFields(self::MAILBOX_TABLE)[self::POINTER_COLUMN]
		);
	}

	/**
	 * Drops every process answer a scope of this mailbox is built from, and not the pointer alone:
	 * the generations the mailbox is preparing {@see readPreparedGenerations()} go with it, and so
	 * does the answer about the schema of the feature. The name says less than the method does,
	 * and the two answers are dropped together on purpose: {@see servingMailbox()} composes a
	 * scope out of both, so a caller invalidating one of them needs the other gone as well.
	 *
	 * @param int|null $mailboxId Null drops the answers of every mailbox of the process.
	 */
	public static function invalidateActivePointerCache(?int $mailboxId = null): void
	{
		self::$pointerColumnExists = null;

		if ($mailboxId === null)
		{
			self::$activePointers = [];
			self::$preparedGenerations = [];
		}
		else
		{
			unset(self::$activePointers[$mailboxId], self::$preparedGenerations[$mailboxId]);
		}
	}

	/**
	 * The generations the mailbox is preparing, cached for the process the way the pointer
	 * above is: a migration prepares one source at a time, and a run of a synchronization
	 * asks this once instead of once per batch of letters.
	 *
	 * Which generations those are is one answer of the table itself
	 * {@see MailboxSourceGenerationTable::preparedGenerationFilter()} - including why the
	 * generation of the G1 backfill is not one of them.
	 *
	 * @return int[]
	 */
	private static function readPreparedGenerations(int $mailboxId): array
	{
		if ($mailboxId <= 0 || !self::isSchemaInstalled())
		{
			return [];
		}

		if (!array_key_exists($mailboxId, self::$preparedGenerations))
		{
			// Raw SQL for the same reason the pointer is read that way
			$rows = Application::getConnection()->query(sprintf(
				'SELECT ID FROM %s WHERE %s',
				MailboxSourceGenerationTable::getTableName(),
				MailboxSourceGenerationTable::preparedGenerationSqlCondition($mailboxId),
			))->fetchAll();

			self::$preparedGenerations[$mailboxId] = array_map(
				static fn (array $row): int => (int)$row['ID'],
				$rows,
			);
		}

		return self::$preparedGenerations[$mailboxId];
	}

	/**
	 * @return int[]|null
	 */
	public function getGenerationIds(): ?array
	{
		return $this->generationIds;
	}

	/**
	 * The generation new physical rows are stamped with.
	 */
	public function getStampGenerationId(): int
	{
		return $this->stampGenerationId;
	}

	/**
	 * Whether the scope narrows nothing at all, i.e. every physical row of the mailbox belongs to
	 * it. A read with preparatory work of its own asks this before doing it: the counters of the
	 * folders carry no generation of their own, so a bounded read of them has to name the folders
	 * it sums by their ids first.
	 *
	 * Not the same question as "is there a list of generations": the shape stating what it refuses
	 * has no list either {@see withoutPreparedGenerations()}, and a caller reading
	 * {@see getGenerationIds()} as this answer skips the condition exactly where it is needed.
	 */
	public function isUnfiltered(): bool
	{
		return $this->generationIds === null && $this->excludedGenerationIds === [];
	}

	public function includes(int $generationId): bool
	{
		if ($this->generationIds === null)
		{
			return !in_array($generationId, $this->excludedGenerationIds, true);
		}

		return in_array($generationId, $this->generationIds, true);
	}

	/**
	 * Adds the generation condition to an ORM filter (a no-op for the unfiltered scope).
	 *
	 * @param string $fieldPrefix The path to the uid entity when it is joined rather than
	 *                            queried directly, e.g. 'MESSAGE_UID.'.
	 */
	public function apply(array $filter, string $fieldPrefix = ''): array
	{
		if ($this->generationIds === null)
		{
			if ($this->excludedGenerationIds !== [])
			{
				$filter['!@' . $fieldPrefix . 'GENERATION_ID'] = $this->excludedGenerationIds;
			}

			return $filter;
		}

		if (count($this->generationIds) === 1)
		{
			$filter['=' . $fieldPrefix . 'GENERATION_ID'] = $this->generationIds[0];
		}
		else
		{
			$filter['@' . $fieldPrefix . 'GENERATION_ID'] = $this->generationIds;
		}

		return $filter;
	}

	public function getCacheKey(): string
	{
		if ($this->generationIds !== null)
		{
			return 'g' . implode('_', $this->generationIds);
		}

		return $this->excludedGenerationIds === []
			? 'all'
			: 'not' . implode('_', $this->excludedGenerationIds)
		;
	}
}
