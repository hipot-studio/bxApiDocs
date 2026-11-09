<?php
declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

use Bitrix\Landing\Copilot\Model\GenerationsTable;
use Bitrix\Landing\Copilot\Services\CreateAiSiteChecker;
use Bitrix\Landing\Integration\AiAssistant\Contract\AiSiteChatBindingContract;
use Bitrix\Landing\Integration\AiAssistant\Model\AiSiteBindingTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

class AiSiteBindingService
{
	private const LOCK_TIMEOUT_SECONDS = 5;

	private const EVENT_LOG_AUDIT_TYPE_DUPLICATE_USER_SITE = 'LANDING_AI_SITE_BINDING_DUPLICATE_USER_SITE';
	private const MARKUP_CHAT_ENTITY_ID_SUFFIX = ':markup';

	private const SELECT_FIELDS = [
		'ID',
		'USER_ID',
		'SITE_ID',
		'GENERATION_ID',
		'DATE_CREATE',
		'DATE_MODIFY',
	];

	public function getOrCreateFreeDraft(int $userId): int
	{
		$userId = $this->requirePositiveId($userId, 'userId');

		return $this->withDbLock($this->getDraftLockName($userId), function () use ($userId): int
		{
			return $this->getOrCreateFreeDraftInternal($userId);
		});
	}

	/**
	 * The free row of /sites/ai/ for the session of the browser, under the draft lock of the user:
	 * - the row of a failed creation carried by its retry token, while it is own and free (the retry goes to
	 *   the same chat);
	 * - a new row when a carried request must start a new chat;
	 * - the row remembered by the session cookie, while it is own and free (F5, a second tab);
	 * - a new row otherwise.
	 * A taken existing row gets DATE_MODIFY = now, except a row at the moderation-block limit: its DATE_MODIFY
	 * anchors the cooldown. A new row inherits the highest moderation-block counter, with its cooldown anchor,
	 * of the free rows of the user whose cooldown window is still open, so a new browser session does not reset
	 * the limit.
	 */
	public function getOrCreateSitesAiDraft(
		int $userId,
		int $rememberedBindingId,
		bool $forceNew = false,
		int $handoffBindingId = 0,
	): int
	{
		$userId = $this->requirePositiveId($userId, 'userId');

		return $this->withDbLock(
			$this->getDraftLockName($userId),
			function () use ($userId, $rememberedBindingId, $forceNew, $handoffBindingId): int
			{
				if ($this->takeOwnFreeDraft($handoffBindingId, $userId))
				{
					return $handoffBindingId;
				}

				if (!$forceNew && $this->takeOwnFreeDraft($rememberedBindingId, $userId))
				{
					return $rememberedBindingId;
				}

				return $this->addSitesAiDraft($userId);
			},
		);
	}

	/**
	 * Chat key of the AI site trigger. The binding row is shared by both generations, so the generation
	 * lives in the key: another key resolves to another chat, while the legacy key stays the bare binding
	 * id and keeps finding the chats of the released generation. The caller names the generation;
	 * the key is not an authorization.
	 */
	public function getChatEntityId(int $bindingId, bool $isMarkupGeneration): string
	{
		$bindingId = $this->normalizePositiveId($bindingId);
		if ($bindingId <= 0)
		{
			return '';
		}

		return $isMarkupGeneration
			? $bindingId . self::MARKUP_CHAT_ENTITY_ID_SUFFIX
			: (string)$bindingId
		;
	}

	/**
	 * Reads a chat key made by getChatEntityId(): the binding id, bare or with the markup suffix. Any other
	 * string is not a key of a binding. Like the key itself, the result is not an authorization.
	 *
	 * @return array{bindingId: int, isMarkupGeneration: bool}|null
	 */
	public function parseChatEntityId(string $entityId): ?array
	{
		$pattern = '/^([1-9][0-9]*)(' . preg_quote(self::MARKUP_CHAT_ENTITY_ID_SUFFIX, '/') . ')?$/D';
		if (preg_match($pattern, $entityId, $matches) !== 1)
		{
			return null;
		}

		$bindingId = filter_var($matches[1], FILTER_VALIDATE_INT);
		if ($bindingId === false)
		{
			return null;
		}

		return [
			'bindingId' => $bindingId,
			'isMarkupGeneration' => ($matches[2] ?? '') !== '',
		];
	}

	/**
	 * The first of the given chat keys the user already has a chat under, in the given order; null when
	 * none of them has one. A server message goes only into an existing chat, so it is addressed by this.
	 *
	 * @param list<string> $entityIds
	 */
	public function findFirstMappedChatEntityId(int $userId, array $entityIds): ?string
	{
		$entityIds = array_values(array_unique(array_filter(
			$entityIds,
			static fn(string $entityId): bool => $entityId !== '',
		)));
		if ($userId <= 0 || $entityIds === [])
		{
			return null;
		}

		$mappedEntityIds = $this->fetchMappedChatEntityIds($userId, $entityIds);
		foreach ($entityIds as $entityId)
		{
			if (in_array($entityId, $mappedEntityIds, true))
			{
				return $entityId;
			}
		}

		return null;
	}

	/**
	 * Chat key of a binding that may already carry an accepted work (the editor). The saved scenario
	 * names the generation, but not the key the chat of that work was opened with: a markup work
	 * accepted before the generation got into the key, or from a tab that still held the bare key,
	 * lives in a chat mapped by the bare binding id. Such a chat is kept; the markup key wins as soon
	 * as it has a chat of its own.
	 */
	public function getAcceptedWorkChatEntityId(int $bindingId, int $userId): string
	{
		$isMarkupGeneration = $this->isMarkupGenerationBinding($bindingId, $userId);
		$entityId = $this->getChatEntityId($bindingId, $isMarkupGeneration);
		if (!$isMarkupGeneration || $entityId === '')
		{
			return $entityId;
		}

		$bareEntityId = $this->getChatEntityId($bindingId, false);
		$mappedEntityIds = $this->fetchMappedChatEntityIds($userId, [$entityId, $bareEntityId]);

		return !in_array($entityId, $mappedEntityIds, true) && in_array($bareEntityId, $mappedEntityIds, true)
			? $bareEntityId
			: $entityId
		;
	}

	/**
	 * Whether the work accepted on the binding is a markup one. The saved scenario of that work
	 * decides, so the answer survives a change of the selected generation; a binding without a work
	 * (a free draft, a site opened by a user who did not create it) answers false.
	 */
	public function isMarkupGenerationBinding(int $bindingId, int $userId): bool
	{
		$generationId = (int)($this->getByIdForUser($bindingId, $userId)['GENERATION_ID'] ?? 0);
		if ($generationId <= 0)
		{
			return false;
		}

		$family = CreateAiSiteChecker::getScenarioFamily($this->fetchGenerationScenario($generationId));

		return $family === CreateAiSiteChecker::SCENARIO_FAMILY_MARKUP;
	}

	public function reserveDraftForGeneration(int $bindingId, int $userId, int $generationId): bool
	{
		$bindingId = $this->normalizePositiveId($bindingId);
		$userId = $this->normalizePositiveId($userId);
		$generationId = $this->normalizePositiveId($generationId);
		if ($bindingId <= 0 || $userId <= 0 || $generationId <= 0)
		{
			return false;
		}

		return $this->executeConditionalUpdate(
			[
				'GENERATION_ID' => $generationId,
				'DATE_MODIFY' => new DateTime(),
			],
			[
				"ID = {$bindingId}",
				"USER_ID = {$userId}",
				'SITE_ID IS NULL',
				'GENERATION_ID IS NULL',
			],
		) === 1;
	}

	public function isFreeDraftForGeneration(int $bindingId, int $userId): bool
	{
		$row = $this->getByIdForUser($bindingId, $userId);

		return $row !== null
			&& $row['SITE_ID'] === null
			&& $row['GENERATION_ID'] === null
		;
	}

	public function completeGeneration(int $generationId, int $userId, int $siteId): bool
	{
		$generationId = $this->normalizePositiveId($generationId);
		$userId = $this->normalizePositiveId($userId);
		$siteId = $this->normalizePositiveId($siteId);
		if ($generationId <= 0 || $userId <= 0 || $siteId <= 0)
		{
			return false;
		}

		try
		{
			return $this->executeConditionalUpdate(
				[
					'SITE_ID' => $siteId,
					// A completed generation breaks the consecutive moderation-block streak.
					'MODERATION_BLOCK_COUNT' => 0,
					'DATE_MODIFY' => new DateTime(),
				],
				[
					"GENERATION_ID = {$generationId}",
					"USER_ID = {$userId}",
					'SITE_ID IS NULL',
				],
			) === 1;
		}
		catch (SqlQueryException $exception)
		{
			$existingBinding = $this->findByUserAndSite($userId, $siteId);
			if ($existingBinding !== null)
			{
				$this->logDuplicateUserSiteConflict($generationId, $userId, $siteId, $existingBinding, $exception);

				return true;
			}

			throw $exception;
		}
	}

	/**
	 * Consecutive moderation-block ceiling per draft (Q-2). Once reached, a new generation
	 * on the draft is refused with a soft "too many attempts" message until it resets.
	 */
	public const MODERATION_BLOCK_LIMIT = 3;

	/**
	 * Cooldown window that makes the ceiling above temporary: the limit only holds while the draft
	 * was touched recently. DATE_MODIFY is the anchor because a blocked draft is not written by
	 * anyone else - reservation happens after the limit check, and both completion and release
	 * require a GENERATION_ID the blocked draft does not have.
	 */
	public const MODERATION_BLOCK_COOLDOWN_HOURS = 24;

	public const MODERATION_BLOCK_COOLDOWN_SECONDS = self::MODERATION_BLOCK_COOLDOWN_HOURS * 3600;

	/**
	 * Reaction to a moderation block on a draft: atomically frees the reservation (so the same
	 * chat can retry) and bumps the consecutive-block counter. Keyed on GENERATION_ID, so repeated
	 * status polls of the same failed generation cannot double-count (the second call matches 0 rows).
	 *
	 * Create-only: the edit flow does not reserve a binding, so there is nothing to release there.
	 */
	public function registerModerationBlock(int $generationId, int $userId): bool
	{
		return $this->releaseGenerationReservation($generationId, $userId, true);
	}

	/**
	 * Reaction to a terminal failure that left no draft site behind: frees the reservation so the same
	 * chat can start over, and deliberately leaves MODERATION_BLOCK_COUNT alone - a technical failure
	 * must not eat the moderation-block attempts of the draft.
	 *
	 * Idempotent on the same terms as registerModerationBlock(): keyed on GENERATION_ID, so every poll
	 * after the first matches 0 rows and returns false.
	 *
	 * Create-only: the edit flow does not reserve a binding, so there is nothing to release there.
	 */
	public function releaseDraftForFailedGeneration(int $generationId, int $userId): bool
	{
		return $this->releaseGenerationReservation($generationId, $userId, false);
	}

	/**
	 * Atomically frees the draft reserved by the generation, optionally counting the release as a
	 * consecutive moderation block. Conditional on GENERATION_ID, so a repeated call is a no-op.
	 *
	 * A counted block continues the streak of the user: the counter starts from the highest one of the other
	 * free drafts of the user within the cooldown window, so blocks spread over several drafts opened at once
	 * add up instead of each draft getting attempts of its own.
	 *
	 * Protected for the same reason as the other storage seams of this class: the counter lives in a
	 * raw expression, so the in-memory double substitutes the whole statement.
	 */
	protected function releaseGenerationReservation(int $generationId, int $userId, bool $countModerationBlock): bool
	{
		$generationId = $this->normalizePositiveId($generationId);
		$userId = $this->normalizePositiveId($userId);
		if ($generationId <= 0 || $userId <= 0)
		{
			return false;
		}

		$counterSql = $countModerationBlock ? 'MODERATION_BLOCK_COUNT = MODERATION_BLOCK_COUNT + 1, ' : '';
		$ceiling = $countModerationBlock
			? (int)($this->findFreeDraftModerationCeiling($userId)['MODERATION_BLOCK_COUNT'] ?? 0)
			: 0
		;
		if ($ceiling > 0)
		{
			$counterSql = "MODERATION_BLOCK_COUNT = GREATEST(MODERATION_BLOCK_COUNT, {$ceiling}) + 1, ";
		}

		$connection = Application::getConnection();
		$sql = sprintf(
			'UPDATE %s SET GENERATION_ID = NULL, %sDATE_MODIFY = %s'
			. ' WHERE GENERATION_ID = %d AND USER_ID = %d AND SITE_ID IS NULL',
			AiSiteBindingTable::getTableName(),
			$counterSql,
			$this->convertToSqlValue(new DateTime()),
			$generationId,
			$userId,
		);
		$connection->queryExecute($sql);

		return (int)$connection->getAffectedRowsCount() === 1;
	}

	public function getModerationBlockCount(int $bindingId, int $userId): int
	{
		$bindingId = $this->normalizePositiveId($bindingId);
		$userId = $this->normalizePositiveId($userId);
		if ($bindingId <= 0 || $userId <= 0)
		{
			return 0;
		}

		$row = AiSiteBindingTable::query()
			->setSelect(['MODERATION_BLOCK_COUNT'])
			->where('ID', '=', $bindingId)
			->where('USER_ID', '=', $userId)
			->setLimit(1)
			->fetch()
		;

		return is_array($row) ? max(0, (int)($row['MODERATION_BLOCK_COUNT'] ?? 0)) : 0;
	}

	public function isModerationBlockLimitReached(int $bindingId, int $userId): bool
	{
		return $this->getModerationBlockCount($bindingId, $userId) >= self::MODERATION_BLOCK_LIMIT;
	}

	/**
	 * Start gate of a draft: true when a new generation may be started on it - the consecutive blocks
	 * are still below the ceiling, or the cooldown window has expired and this very start spends the
	 * reset. A refused start writes nothing, so the counter of the draft survives it.
	 *
	 * The limit belongs to the user, not to one draft: another free draft of the user at the limit within its
	 * cooldown window refuses the start as well, so neither a new browser session of /sites/ai/ nor the REST
	 * draft brings fresh attempts.
	 *
	 * Shared by every entry point that starts a generation on a draft, so a markup submission and a
	 * legacy create answer to the same counter with the same window; each of them refuses with an
	 * exception of its own flow.
	 */
	public function passModerationBlockGate(int $bindingId, int $userId): bool
	{
		if ($this->isModerationBlockLimitReachedOnOtherDraft($bindingId, $userId))
		{
			return false;
		}

		if (!$this->isModerationBlockLimitReached($bindingId, $userId))
		{
			return true;
		}

		return $this->resetModerationBlockCountAfterCooldown($bindingId, $userId);
	}

	private function isModerationBlockLimitReachedOnOtherDraft(int $bindingId, int $userId): bool
	{
		$userId = $this->normalizePositiveId($userId);
		if ($userId <= 0)
		{
			return false;
		}

		$ceiling = $this->findFreeDraftModerationCeiling($userId, $this->normalizePositiveId($bindingId));

		return (int)($ceiling['MODERATION_BLOCK_COUNT'] ?? 0) >= self::MODERATION_BLOCK_LIMIT;
	}

	/**
	 * Clears the consecutive-block counter of a draft whose cooldown window has expired.
	 *
	 * The whole decision lives inside the WHERE of a single UPDATE: nothing reads DATE_MODIFY
	 * beforehand, so the statement itself is the race gate - out of two concurrent starts on a
	 * cooled-down draft exactly one gets the reset, the other is refused as still blocked.
	 */
	public function resetModerationBlockCountAfterCooldown(int $bindingId, int $userId): bool
	{
		$bindingId = $this->normalizePositiveId($bindingId);
		$userId = $this->normalizePositiveId($userId);
		if ($bindingId <= 0 || $userId <= 0)
		{
			return false;
		}

		$threshold = (new DateTime())->add('-' . self::MODERATION_BLOCK_COOLDOWN_SECONDS . ' seconds');

		return $this->executeConditionalUpdate(
			[
				'MODERATION_BLOCK_COUNT' => 0,
				'DATE_MODIFY' => new DateTime(),
			],
			[
				"ID = {$bindingId}",
				"USER_ID = {$userId}",
				'MODERATION_BLOCK_COUNT >= ' . self::MODERATION_BLOCK_LIMIT,
				'DATE_MODIFY < ' . $this->convertToSqlValue($threshold),
			],
		) === 1;
	}

	public function getOrCreateForSite(int $userId, int $siteId): int
	{
		$userId = $this->requirePositiveId($userId, 'userId');
		$siteId = $this->requirePositiveId($siteId, 'siteId');

		return $this->withDbLock($this->getSiteLockName($userId, $siteId), function () use ($userId, $siteId): int
		{
			return $this->getOrCreateForSiteInternal($userId, $siteId);
		});
	}

	public function getByIdForUser(int $bindingId, int $userId): ?array
	{
		$bindingId = $this->normalizePositiveId($bindingId);
		$userId = $this->normalizePositiveId($userId);
		if ($bindingId <= 0 || $userId <= 0)
		{
			return null;
		}

		$row = AiSiteBindingTable::query()
			->setSelect(self::SELECT_FIELDS)
			->where('ID', '=', $bindingId)
			->where('USER_ID', '=', $userId)
			->setLimit(1)
			->fetch()
		;

		return $this->normalizeRow($row);
	}

	/**
	 * Rows of every user bound to the site.
	 *
	 * @return list<array>
	 */
	public function findBySite(int $siteId): array
	{
		$siteId = $this->normalizePositiveId($siteId);
		if ($siteId <= 0)
		{
			return [];
		}

		$rows = AiSiteBindingTable::query()
			->setSelect(self::SELECT_FIELDS)
			->where('SITE_ID', '=', $siteId)
			->setOrder(['ID' => 'ASC'])
			->fetchAll()
		;

		return array_map(fn(array $row): array => $this->normalizeRow($row), $rows);
	}

	/**
	 * Removes the rows of every user bound to the site.
	 *
	 * One set DELETE by the indexed SITE_ID: the ORM deletes by primary key only; the id is an integer.
	 *
	 * @return int Number of removed rows.
	 */
	public function deleteBySite(int $siteId): int
	{
		$siteId = $this->normalizePositiveId($siteId);
		if ($siteId <= 0)
		{
			return 0;
		}

		$connection = Application::getConnection();
		$connection->queryExecute(sprintf(
			'DELETE FROM %s WHERE SITE_ID = %d',
			AiSiteBindingTable::getTableName(),
			$siteId,
		));

		return (int)$connection->getAffectedRowsCount();
	}

	public function findByGenerationId(int $generationId): ?array
	{
		$generationId = $this->normalizePositiveId($generationId);
		if ($generationId <= 0)
		{
			return null;
		}

		$row = AiSiteBindingTable::query()
			->setSelect(self::SELECT_FIELDS)
			->where('GENERATION_ID', '=', $generationId)
			->setLimit(1)
			->fetch()
		;

		return $this->normalizeRow($row);
	}

	protected function fetchGenerationScenario(int $generationId): string
	{
		$row = GenerationsTable::query()
			->setSelect(['SCENARIO'])
			->where('ID', '=', $generationId)
			->setLimit(1)
			->fetch()
		;

		return is_array($row) ? (string)($row['SCENARIO'] ?? '') : '';
	}

	/**
	 * Chat keys of the AI site trigger that already have a chat of the user, out of the given ones.
	 * The chat map belongs to the optional aiassistant module: without it no key is mapped.
	 *
	 * @param list<string> $entityIds
	 * @return list<string>
	 */
	protected function fetchMappedChatEntityIds(int $userId, array $entityIds): array
	{
		if (
			!Loader::includeModule('aiassistant')
			|| !class_exists(\Bitrix\AiAssistant\Trigger\Model\ChatMapTable::class)
			|| !class_exists(\Bitrix\AiAssistant\Trigger\Model\TriggerTable::class)
		)
		{
			return [];
		}

		$trigger = \Bitrix\AiAssistant\Trigger\Model\TriggerTable::query()
			->setSelect(['ID'])
			->where('CODE', '=', AiSiteChatBindingContract::TRIGGER_CODE)
			->setLimit(1)
			->fetch()
		;
		$triggerId = is_array($trigger) ? (int)($trigger['ID'] ?? 0) : 0;
		if ($triggerId <= 0)
		{
			return [];
		}

		$rows = \Bitrix\AiAssistant\Trigger\Model\ChatMapTable::query()
			->setSelect(['ENTITY_ID'])
			->where('USER_ID', '=', $userId)
			->where('TRIGGER_ID', '=', $triggerId)
			->whereIn('ENTITY_ID', $entityIds)
			->fetchAll()
		;

		return array_map(static fn(array $row): string => (string)$row['ENTITY_ID'], $rows);
	}

	private function getOrCreateFreeDraftInternal(int $userId): int
	{
		$row = $this->findFreeDraft($userId);
		if ($row !== null)
		{
			return (int)$row['ID'];
		}

		return $this->add([
			'USER_ID' => $userId,
			'SITE_ID' => null,
			'GENERATION_ID' => null,
			'DATE_CREATE' => new DateTime(),
			'DATE_MODIFY' => new DateTime(),
		]);
	}

	private function getOrCreateForSiteInternal(int $userId, int $siteId): int
	{
		$row = $this->findByUserAndSite($userId, $siteId);
		if ($row)
		{
			return (int)$row['ID'];
		}

		return $this->add([
			'USER_ID' => $userId,
			'SITE_ID' => $siteId,
			'GENERATION_ID' => null,
			'DATE_CREATE' => new DateTime(),
			'DATE_MODIFY' => new DateTime(),
		]);
	}

	private function takeOwnFreeDraft(int $bindingId, int $userId): bool
	{
		if ($bindingId <= 0 || !$this->isFreeDraftForGeneration($bindingId, $userId))
		{
			return false;
		}

		$this->executeConditionalUpdate(
			[
				'DATE_MODIFY' => new DateTime(),
			],
			[
				"ID = {$bindingId}",
				"USER_ID = {$userId}",
				'SITE_ID IS NULL',
				'GENERATION_ID IS NULL',
				'MODERATION_BLOCK_COUNT < ' . self::MODERATION_BLOCK_LIMIT,
			],
		);

		return true;
	}

	private function addSitesAiDraft(int $userId): int
	{
		$now = new DateTime();
		$fields = [
			'USER_ID' => $userId,
			'SITE_ID' => null,
			'GENERATION_ID' => null,
			'DATE_CREATE' => $now,
			'DATE_MODIFY' => $now,
		];

		$ceiling = $this->findFreeDraftModerationCeiling($userId);
		$blockCount = (int)($ceiling['MODERATION_BLOCK_COUNT'] ?? 0);
		$anchor = $ceiling['DATE_MODIFY'] ?? null;
		if (
			$blockCount > 0
			&& $anchor instanceof DateTime
			&& $anchor->getTimestamp() > $now->getTimestamp() - self::MODERATION_BLOCK_COOLDOWN_SECONDS
		)
		{
			$fields['MODERATION_BLOCK_COUNT'] = $blockCount;
			$fields['DATE_MODIFY'] = $anchor;
		}

		return $this->add($fields);
	}

	/**
	 * The free draft of the user with the highest moderation-block counter among those whose cooldown window
	 * is still open (DATE_MODIFY within it), other than the given one; null when no such draft has a block.
	 *
	 * @return array{MODERATION_BLOCK_COUNT: int|string, DATE_MODIFY: DateTime|null}|null
	 */
	protected function findFreeDraftModerationCeiling(int $userId, int $exceptBindingId = 0): ?array
	{
		$query = AiSiteBindingTable::query()
			->setSelect(['MODERATION_BLOCK_COUNT', 'DATE_MODIFY'])
			->where('USER_ID', '=', $userId)
			->whereNull('SITE_ID')
			->whereNull('GENERATION_ID')
			->where('MODERATION_BLOCK_COUNT', '>', 0)
			->where(
				'DATE_MODIFY',
				'>',
				(new DateTime())->add('-' . self::MODERATION_BLOCK_COOLDOWN_SECONDS . ' seconds'),
			)
			->setOrder(['MODERATION_BLOCK_COUNT' => 'DESC', 'DATE_MODIFY' => 'DESC'])
			->setLimit(1)
		;
		if ($exceptBindingId > 0)
		{
			$query->where('ID', '!=', $exceptBindingId);
		}

		$row = $query->fetch();

		return is_array($row) ? $row : null;
	}

	protected function findFreeDraft(int $userId): ?array
	{
		$row = AiSiteBindingTable::query()
			->setSelect(self::SELECT_FIELDS)
			->where('USER_ID', '=', $userId)
			->whereNull('SITE_ID')
			->whereNull('GENERATION_ID')
			->setOrder(['ID' => 'ASC'])
			->setLimit(1)
			->fetch()
		;

		return $this->normalizeRow($row);
	}

	protected function findByUserAndSite(int $userId, int $siteId): ?array
	{
		$row = AiSiteBindingTable::query()
			->setSelect(self::SELECT_FIELDS)
			->where('USER_ID', '=', $userId)
			->where('SITE_ID', '=', $siteId)
			->setOrder(['ID' => 'ASC'])
			->setLimit(1)
			->fetch()
		;

		return $this->normalizeRow($row);
	}

	private function logDuplicateUserSiteConflict(
		int $generationId,
		int $userId,
		int $siteId,
		array $existingBinding,
		SqlQueryException $exception,
	): void
	{
		if (!class_exists(\CEventLog::class))
		{
			return;
		}

		\CEventLog::Add([
			'SEVERITY' => 'WARNING',
			'AUDIT_TYPE_ID' => self::EVENT_LOG_AUDIT_TYPE_DUPLICATE_USER_SITE,
			'MODULE_ID' => 'landing',
			'ITEM_ID' => (string)$siteId,
			'DESCRIPTION' => implode(PHP_EOL, [
				'AI site binding already exists for USER_ID + SITE_ID.',
				"GENERATION_ID: {$generationId}",
				"USER_ID: {$userId}",
				"SITE_ID: {$siteId}",
				'EXISTING_BINDING_ID: ' . (int)($existingBinding['ID'] ?? 0),
				'EXISTING_BINDING_GENERATION_ID: ' . (int)($existingBinding['GENERATION_ID'] ?? 0),
				'DB_ERROR: ' . $exception->getMessage(),
			]),
		]);
	}

	protected function withDbLock(string $lockName, callable $callback): mixed
	{
		$connection = Application::getConnection();
		if (!$connection->lock($lockName, self::LOCK_TIMEOUT_SECONDS))
		{
			throw new \RuntimeException("Unable to acquire AI site binding lock: {$lockName}.");
		}

		try
		{
			return $callback();
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	/**
	 * Compare-and-set over the row state: the ORM updates by primary key only, while the row must change
	 * only if it is still free, reserved by this generation, etc. Field names and conditions come from this
	 * class, values are integers, NULL or dates through SqlHelper (convertToSqlValue()).
	 */
	protected function executeConditionalUpdate(array $fields, array $where): int
	{
		$connection = Application::getConnection();
		$set = [];
		foreach ($fields as $fieldName => $value)
		{
			$set[] = "{$fieldName} = " . $this->convertToSqlValue($value);
		}

		$sql = sprintf(
			'UPDATE %s SET %s WHERE %s',
			AiSiteBindingTable::getTableName(),
			implode(', ', $set),
			implode(' AND ', $where),
		);
		$connection->queryExecute($sql);

		return (int)$connection->getAffectedRowsCount();
	}

	protected function add(array $fields): int
	{
		$result = AiSiteBindingTable::add($fields);
		if (!$result->isSuccess())
		{
			throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
		}

		return (int)$result->getId();
	}

	private function convertToSqlValue(mixed $value): string
	{
		$sqlHelper = Application::getConnection()->getSqlHelper();
		if ($value instanceof DateTime)
		{
			return $sqlHelper->convertToDbDateTime($value);
		}

		if ($value === null)
		{
			return 'NULL';
		}

		return (string)(int)$value;
	}

	private function getDraftLockName(int $userId): string
	{
		return "landing_ai_site_draft_{$userId}";
	}

	private function getSiteLockName(int $userId, int $siteId): string
	{
		return "landing_ai_site_site_{$userId}_{$siteId}";
	}

	private function normalizeRow($row): ?array
	{
		if (!is_array($row))
		{
			return null;
		}

		return [
			'ID' => (int)($row['ID'] ?? 0),
			'USER_ID' => (int)($row['USER_ID'] ?? 0),
			'SITE_ID' => AiSiteBindingTable::normalizeNullableId($row['SITE_ID'] ?? null),
			'GENERATION_ID' => AiSiteBindingTable::normalizeNullableId($row['GENERATION_ID'] ?? null),
			'DATE_CREATE' => $row['DATE_CREATE'] ?? null,
			'DATE_MODIFY' => $row['DATE_MODIFY'] ?? null,
		];
	}

	private function requirePositiveId(int $value, string $name): int
	{
		$value = $this->normalizePositiveId($value);
		if ($value <= 0)
		{
			throw new \InvalidArgumentException("{$name} must be positive.");
		}

		return $value;
	}

	private function normalizePositiveId(int $value): int
	{
		return $value > 0 ? $value : 0;
	}
}
