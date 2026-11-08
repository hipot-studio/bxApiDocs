<?php

namespace Bitrix\Bizproc\Api\Service;

use Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService\TemplateChangeAttribution;
use Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService\TemplateChangeEntry;
use Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService\TemplateChangeEvent;
use Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService\TemplateVersion;
use Bitrix\Bizproc\Api\Enum\ErrorMessage;
use Bitrix\Bizproc\Api\Enum\Template\TemplateChangeElementType;
use Bitrix\Bizproc\Api\Enum\Template\TemplateChangeEventType;
use Bitrix\Bizproc\Api\Enum\Template\TemplateChangeKind;
use Bitrix\Bizproc\Api\Enum\Template\TemplateChangeRequestSource;
use Bitrix\Bizproc\Api\Enum\Template\TemplatePublicationType;
use Bitrix\Bizproc\Api\Enum\Template\WorkflowTemplateType;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateHistoryService\GetChangeEventRequest;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateHistoryService\GetChangeEventsRequest;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateHistoryService\GetVersionRequest;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateHistoryService\GetVersionsRequest;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateHistoryService\RecordChangeRequest;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateHistoryService\RecordPublicationRequest;
use Bitrix\Bizproc\Api\Request\WorkflowTemplateHistoryService\RestoreVersionRequest;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateHistoryService\GetChangeEventResponse;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateHistoryService\GetChangeEventsResponse;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateHistoryService\GetVersionResponse;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateHistoryService\GetVersionsResponse;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateHistoryService\RecordChangeResponse;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateHistoryService\RecordPublicationResponse;
use Bitrix\Bizproc\Api\Response\WorkflowTemplateHistoryService\RestoreVersionResponse;
use Bitrix\Bizproc\Error;
use Bitrix\Bizproc\Internal\Config\TemplateHistory;
use Bitrix\Bizproc\Internal\Service\TemplateChange\TemplateChangeDiffBuilder;
use Bitrix\Bizproc\Public\Service\TemplateAccessService;
use Bitrix\Bizproc\Workflow\Template\Converter\NodesToTemplate;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateChangeTable;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable;
use Bitrix\Main\Application;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Diag\ExceptionHandler;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

class WorkflowTemplateHistoryService
{
	private const SNAPSHOT_FIELDS = ['TEMPLATE', 'PARAMETERS', 'VARIABLES', 'CONSTANTS', 'NAME', 'DESCRIPTION'];
	private const PARTICIPATION_FIELDS = ['TYPE', 'SYSTEM_CODE'];
	private const DOCUMENT_TYPE_FIELDS = ['MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE'];

	private const CHANGE_EVENTS_DEFAULT_LIMIT = 20;
	private const CHANGE_EVENTS_MAX_LIMIT = 100;
	private const CHANGES_PREVIEW_LIMIT = 20;
	private const CHANGES_LIMIT = 200;
	private const MERGE_LOCK_PREFIX = 'bp_template_change_';
	private const MERGE_LOCK_TIMEOUT = 0;

	private TemplateAccessService $templateAccessService;
	private ExceptionHandler $exceptionHandler;

	public function __construct(
		?TemplateAccessService $templateAccessService = null,
		?ExceptionHandler $exceptionHandler = null,
	)
	{
		$this->templateAccessService = $templateAccessService ?? new TemplateAccessService();
		$this->exceptionHandler = $exceptionHandler ?? Application::getInstance()->getExceptionHandler();
	}

	/**
	 * The journal is a surface of the node editor: a template of another type is edited elsewhere and a
	 * system one never opens at all, so neither takes part in the history — neither read nor written. An
	 * empty system code is a value of the column too and is checked exactly as the template loader checks it.
	 *
	 * @param array $templateRow template row carrying at least self::PARTICIPATION_FIELDS
	 */
	public function isTemplateRowInHistory(array $templateRow): bool
	{
		return $templateRow['SYSTEM_CODE'] === null
			&& $templateRow['TYPE'] === WorkflowTemplateType::Nodes->value
		;
	}

	private function isJournaledTemplate(int $templateId): bool
	{
		$persisted = $this->getPersistedTemplate($templateId, self::PARTICIPATION_FIELDS);

		return $persisted !== null && $this->isTemplateRowInHistory($persisted);
	}

	/**
	 * Writes the published configuration of the template as a new version of its journal. Must be called
	 * inside the transaction of the publication itself: a version that cannot be written cancels it.
	 *
	 * The caller keeps the initial version of a template that has none: `ensureHistoryInitialized` has to
	 * run before the template row is overwritten, otherwise it copies the publication being made.
	 *
	 * The configuration is taken from the fields the caller has just written and only completed from the
	 * row, so the publication does not read its own body back.
	 */
	public function recordPublication(RecordPublicationRequest $request): RecordPublicationResponse
	{
		$response = new RecordPublicationResponse();

		$writtenFields = array_intersect_key($request->templateFields, array_flip(self::SNAPSHOT_FIELDS));
		$missingFields = array_values(array_diff(self::SNAPSHOT_FIELDS, array_keys($writtenFields)));

		$persisted = $this->getPersistedTemplate(
			$request->templateId,
			[...$missingFields, 'MODIFIED', ...self::PARTICIPATION_FIELDS],
		);
		if ($persisted === null)
		{
			$response->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError());

			return $response;
		}

		// The write is decided before the row is overwritten and confirmed on the row it left behind: a save
		// that turned the template into a type edited elsewhere takes it out of the history at the same time.
		if (!$this->isTemplateRowInHistory($persisted))
		{
			return $response;
		}

		$versionNumber = $this->getNextVersionNumber($request->templateId);

		try
		{
			WorkflowTemplateChangeTable::add([
				'TEMPLATE_ID' => $request->templateId,
				'EVENT_TYPE' => TemplateChangeEventType::Publication->value,
				'USER_ID' => $request->userId > 0 ? $request->userId : null,
				'CREATED' => new DateTime(),
				'VERSION_NUMBER' => $versionNumber,
				'PUBLICATION_TYPE' => $request->publicationType->value,
				'SNAPSHOT' => $this->buildSnapshot($writtenFields + $persisted),
				'HAS_SNAPSHOT' => true,
				'TEMPLATE_MODIFIED' => $persisted['MODIFIED'],
			]);
		}
		catch (DuplicateEntryException)
		{
			$response->addError(ErrorMessage::TEMPLATE_VERSION_CONFLICT->getCodedError());

			return $response;
		}
		catch (SqlQueryException)
		{
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}

		// A template with fewer publications than the limit has nothing to rotate and pays no query for it.
		if ($versionNumber > TemplateHistory::getVersionLimit() && !$this->rotate($request->templateId))
		{
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}

		return $response->setVersionNumber($versionNumber);
	}

	/**
	 * Marks the birth of a template in its journal. A template created while the history was already on
	 * has no configuration older than the journal itself, so its history stays empty until the first
	 * publication instead of being backfilled from the live row.
	 *
	 * A template outside the history gets no mark at all: it may still be converted into a node one later,
	 * and a birth record would then pass its pre-feature configuration off as born with the journal.
	 */
	public function recordCreation(int $templateId, int $userId): bool
	{
		$persisted = $this->getPersistedTemplate($templateId, self::PARTICIPATION_FIELDS);
		if ($persisted === null || !$this->isTemplateRowInHistory($persisted))
		{
			return true;
		}

		try
		{
			$added = WorkflowTemplateChangeTable::add([
				'TEMPLATE_ID' => $templateId,
				'EVENT_TYPE' => TemplateChangeEventType::Creation->value,
				'USER_ID' => $userId > 0 ? $userId : null,
				'CREATED' => new DateTime(),
			]);
		}
		catch (SqlQueryException)
		{
			return false;
		}

		return $added->isSuccess();
	}

	/**
	 * The journal never throws at its caller: a change is recorded beside a save that has already happened,
	 * so a journal that cannot read the template or build the difference reports it in the response and
	 * leaves the save alone.
	 */
	public function recordChange(RecordChangeRequest $request): RecordChangeResponse
	{
		$response = new RecordChangeResponse();

		if (!TemplateHistory::isEnabled())
		{
			return $response;
		}

		try
		{
			$entries = TemplateChangeDiffBuilder::buildDiff($request->prevState, $request->nextState);
			if ($entries === [])
			{
				return $response;
			}

			if (!$request->isTemplateJournaled && !$this->isJournaledTemplate($request->templateId))
			{
				return $response;
			}

			$isWritten = $this->writeChange($request, $entries);
		}
		catch (\Throwable $exception)
		{
			$this->exceptionHandler->writeToLog($exception);
			$isWritten = false;
		}

		if (!$isWritten)
		{
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());
		}

		return $response;
	}

	/**
	 * @param TemplateChangeEntry[] $entries
	 */
	private function writeChange(RecordChangeRequest $request, array $entries): bool
	{
		$connection = Application::getConnection();
		$lockName = self::MERGE_LOCK_PREFIX . $request->templateId;
		$now = new DateTime();

		// Merging reads the record of the session, changes it and writes it back, so two saves of one template
		// at once would write over each other. The lock is taken without waiting: a save runs inside the
		// request of the person, and holding its worker on a busy lock costs more than the record of its own
		// the loser keeps its changes in.
		if ($connection->lock($lockName, self::MERGE_LOCK_TIMEOUT))
		{
			try
			{
				$isMerged = $this->mergeIntoSession($request, $entries, $now);
			}
			finally
			{
				$connection->unlock($lockName);
			}

			if ($isMerged !== null)
			{
				return $isMerged;
			}
		}

		return $this->addChange($request, $entries, $now);
	}

	/**
	 * @param TemplateChangeEntry[] $entries
	 * @return bool|null null when the template has no session of this author to merge into
	 */
	private function mergeIntoSession(RecordChangeRequest $request, array $entries, DateTime $now): ?bool
	{
		$latest = $this->getLatestChange($request->templateId);

		if ($this->isSessionOf($latest, $request->attribution))
		{
			$kept = self::readStoredEntries($latest['CHANGES'] ?? null);

			// A record filled to the cap keeps only a part of its changes, so merging into it would count
			// the same change twice or lose it: such a session goes on in a record of its own.
			if (count($kept) < self::CHANGES_LIMIT)
			{
				$merged = self::mergeEntries($kept, $entries);
				$latestId = (int)$latest['ID'];

				if ($merged === [])
				{
					return WorkflowTemplateChangeTable::delete($latestId)->isSuccess();
				}

				return WorkflowTemplateChangeTable::update($latestId, [
					...self::buildChangesFields($merged, $now->getTimestamp()),
					'CREATED' => $now,
				])->isSuccess();
			}
		}

		return null;
	}

	/**
	 * @param TemplateChangeEntry[] $entries
	 */
	private function addChange(RecordChangeRequest $request, array $entries, DateTime $now): bool
	{
		$attribution = $request->attribution;

		return WorkflowTemplateChangeTable::add([
			'TEMPLATE_ID' => $request->templateId,
			'EVENT_TYPE' => TemplateChangeEventType::DraftSave->value,
			'CREATED' => $now,
			...self::buildChangesFields($entries, $now->getTimestamp()),
			'USER_ID' => $attribution->userId,
			'AUTHOR_TYPE' => $attribution->authorType->value,
			'AI_TOOL' => $attribution->aiTool,
			'INITIATOR_USER_ID' => $attribution->initiatorUserId,
			'REQUEST_SOURCE' => $attribution->requestSource->value,
		])->isSuccess();
	}

	private function isSessionOf(?array $latest, TemplateChangeAttribution $attribution): bool
	{
		if ($latest === null || (int)$latest['EVENT_TYPE'] !== TemplateChangeEventType::DraftSave->value)
		{
			return false;
		}

		// The initiator is part of the author too: an AI record carries no USER_ID at all, so without it two
		// people editing one template through agents would share a session and one record of both.
		$isSameAuthor =
			(int)$latest['USER_ID'] === (int)$attribution->userId
			&& (int)$latest['AUTHOR_TYPE'] === $attribution->authorType->value
			&& (int)$latest['INITIATOR_USER_ID'] === (int)$attribution->initiatorUserId
			&& (string)$latest['REQUEST_SOURCE'] === $attribution->requestSource->value
			&& (string)$latest['AI_TOOL'] === (string)$attribution->aiTool
		;
		if (!$isSameAuthor)
		{
			return false;
		}

		$created = $latest['CREATED'] ?? null;

		return $created instanceof DateTime
			&& (new DateTime())->getTimestamp() - $created->getTimestamp() < TemplateHistory::getMergeWindow()
		;
	}

	/**
	 * The record a save can be merged into: only a record of the open merge window is one, so a template
	 * left alone since yesterday is asked about through the index `(TEMPLATE_ID, CREATED)` and answers
	 * with nothing at all instead of with the changes of its last session.
	 */
	private function getLatestChange(int $templateId): ?array
	{
		$row = WorkflowTemplateChangeTable::query()
			->setSelect([
				'ID',
				'EVENT_TYPE',
				'USER_ID',
				'AUTHOR_TYPE',
				'AI_TOOL',
				'INITIATOR_USER_ID',
				'REQUEST_SOURCE',
				'CREATED',
				'CHANGES',
			])
			->where('TEMPLATE_ID', $templateId)
			->where('CREATED', '>', self::getMergeWindowStart())
			->setOrder(['CREATED' => 'DESC', 'ID' => 'DESC'])
			->setLimit(1)
			->fetch()
		;

		return $row ?: null;
	}

	private static function getMergeWindowStart(): DateTime
	{
		return (new DateTime())->add('-' . TemplateHistory::getMergeWindow() . ' seconds');
	}

	/**
	 * @return TemplateChangeEntry[]
	 */
	private static function readStoredEntries(mixed $changes): array
	{
		$entries = [];
		foreach (is_array($changes) ? $changes : [] as $stored)
		{
			$entry = is_array($stored) ? TemplateChangeEntry::fromArray($stored) : null;
			if ($entry !== null)
			{
				$entries[] = $entry;
			}
		}

		return $entries;
	}

	/**
	 * @param TemplateChangeEntry[] $entries
	 */
	private static function toStoredEntries(array $entries): array
	{
		return array_map(static fn(TemplateChangeEntry $entry): array => $entry->toArray(), $entries);
	}

	/**
	 * The changes of an event as the journal keeps them: the body holds the latest ones up to the cap, while
	 * the summary beside it is counted over all of them and stays true even for what the body dropped.
	 *
	 * @param TemplateChangeEntry[] $entries
	 */
	public static function buildChangesFields(array $entries, int $changedTimestamp): array
	{
		$stamped = self::stampEntries($entries, $changedTimestamp);

		return [
			'CHANGES' => self::toStoredEntries(array_slice($stamped, -self::CHANGES_LIMIT)),
			'CHANGES_SUMMARY' => [
				'counters' => self::summarizeEntries($stamped),
				'preview' => self::previewEntries($stamped),
			],
		];
	}

	/**
	 * The moment of a change is the moment of the save it came with; a change stored before the journal
	 * kept one at all takes the moment of its event, so no line of the feed is left without a time.
	 *
	 * @param TemplateChangeEntry[] $entries
	 * @return TemplateChangeEntry[]
	 */
	private static function stampEntries(array $entries, int $timestamp): array
	{
		return array_map(
			static fn(TemplateChangeEntry $entry): TemplateChangeEntry => $entry->withTimestamp($timestamp),
			$entries,
		);
	}

	/**
	 * @param TemplateChangeEntry[] $kept
	 * @param TemplateChangeEntry[] $fresh
	 * @return TemplateChangeEntry[]
	 */
	private static function mergeEntries(array $kept, array $fresh): array
	{
		$merged = [];
		foreach ($kept as $entry)
		{
			$merged[self::getEntryKey($entry)] = $entry;
		}

		foreach ($fresh as $entry)
		{
			$key = self::getEntryKey($entry);
			$stored = $merged[$key] ?? null;

			// A change touched again takes its place at the end of the session, where the latest ones are.
			unset($merged[$key]);

			if ($stored === null)
			{
				$merged[$key] = $entry;

				continue;
			}

			$kind = self::mergeKind($stored->kind, $entry->kind);
			$combined = $kind === null ? null : new TemplateChangeEntry(
				$stored->elementType,
				$stored->elementId,
				$entry->elementTitle ?? $stored->elementTitle,
				$kind,
				$stored->oldValue,
				$entry->newValue,
				$entry->settingTitle ?? $stored->settingTitle,
				$entry->settingKey ?? $stored->settingKey,
			);

			if ($combined === null)
			{
				$merged = self::forgetBlockLines($merged, $entry);

				continue;
			}

			if (self::isVoidedChange($combined))
			{
				continue;
			}

			if (self::isBlockRebuilt($stored->kind, $entry->kind))
			{
				$merged = self::forgetBlockLines($merged, $entry);
			}

			$merged[$key] = $combined;
		}

		return array_values($merged);
	}

	private static function isBlockRebuilt(TemplateChangeKind $kept, TemplateChangeKind $fresh): bool
	{
		return $kept === TemplateChangeKind::Removed && $fresh === TemplateChangeKind::Added;
	}

	/**
	 * A block added and taken away inside one session leaves nothing behind: its title, its settings and its
	 * links tell about an element the session neither started nor ended with. A block taken away and put back
	 * is a new one, and what those lines told about the one before it no longer holds, so the session keeps
	 * the fact of the change and drops the details of the block that is gone.
	 *
	 * @param TemplateChangeEntry[] $merged
	 * @return TemplateChangeEntry[]
	 */
	private static function forgetBlockLines(array $merged, TemplateChangeEntry $entry): array
	{
		if ($entry->elementType !== TemplateChangeElementType::Block || $entry->elementId === null)
		{
			return $merged;
		}

		return array_filter(
			$merged,
			static fn(TemplateChangeEntry $kept): bool => !$kept->elementType->isBlockLine()
				|| $kept->elementId !== $entry->elementId
			,
		);
	}

	private static function getEntryKey(TemplateChangeEntry $entry): string
	{
		return implode(
			'|',
			[$entry->elementType->value, $entry->elementId, $entry->settingKey, $entry->settingTitle],
		);
	}

	/**
	 * @return TemplateChangeKind|null null when the changes annihilate: added and then removed
	 */
	private static function mergeKind(TemplateChangeKind $kept, TemplateChangeKind $fresh): ?TemplateChangeKind
	{
		return match ($kept)
		{
			TemplateChangeKind::Added => $fresh === TemplateChangeKind::Removed ? null : TemplateChangeKind::Added,
			TemplateChangeKind::Removed => $fresh === TemplateChangeKind::Added
				? TemplateChangeKind::Updated
				: TemplateChangeKind::Removed
			,
			TemplateChangeKind::Updated => $fresh === TemplateChangeKind::Removed
				? TemplateChangeKind::Removed
				: TemplateChangeKind::Updated
			,
			TemplateChangeKind::Moved => match ($fresh)
			{
				TemplateChangeKind::Removed => TemplateChangeKind::Removed,
				TemplateChangeKind::Updated => TemplateChangeKind::Updated,
				default => TemplateChangeKind::Moved,
			},
		};
	}

	/**
	 * Two changes of one setting cancelling each other leave no line. Values of the journal are bounded, and
	 * two different ones can be bounded to the same text, so a value written up to the bound tells nothing
	 * about equality and the line stays.
	 */
	private static function isVoidedChange(TemplateChangeEntry $entry): bool
	{
		return $entry->kind === TemplateChangeKind::Updated
			&& $entry->oldValue !== null
			&& $entry->oldValue === $entry->newValue
			&& mb_strlen($entry->oldValue) < TemplateChangeEntry::TEXT_LENGTH_LIMIT
		;
	}

	public function getVersions(GetVersionsRequest $request): GetVersionsResponse
	{
		$response = new GetVersionsResponse();

		$accessError = $this->getAccessError($request->templateId, $request->userId);
		if ($accessError !== null)
		{
			$response->addError($accessError);

			return $response;
		}

		try
		{
			if (!$this->ensureHistoryInitialized($request->templateId))
			{
				$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

				return $response;
			}

			return $response->setVersions($this->getAvailableVersions($request->templateId));
		}
		catch (SqlQueryException)
		{
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}
	}

	/**
	 * The snapshot is handed over as it was published: a configuration made by the rules of its time is
	 * opened as is, without validation against the current ones.
	 */
	public function getVersion(GetVersionRequest $request): GetVersionResponse
	{
		$response = new GetVersionResponse();

		$accessError = $this->getAccessError($request->templateId, $request->userId);
		if ($accessError !== null)
		{
			$response->addError($accessError);

			return $response;
		}

		try
		{
			$version = null;
			foreach ($this->getAvailableVersions($request->templateId) as $available)
			{
				if ($available->id === $request->versionId)
				{
					$version = $available;

					break;
				}
			}

			if ($version === null)
			{
				$response->addError(ErrorMessage::TEMPLATE_VERSION_NOT_FOUND->getCodedError());

				return $response;
			}

			return $response
				->setVersion($version)
				->setTemplateData($this->getSnapshot($version->id))
			;
		}
		catch (SqlQueryException)
		{
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}
	}

	/**
	 * The journal of the template page by page, freshest first. Ordering by CREATED matches ordering by ID:
	 * a session is merged into the freshest record of the template, so no record ever moves ahead of a
	 * younger one. The moment leads the pair because the journal is indexed by `(TEMPLATE_ID, CREATED)`.
	 */
	public function getChangeEvents(GetChangeEventsRequest $request): GetChangeEventsResponse
	{
		$response = new GetChangeEventsResponse();

		$accessError = $this->getAccessError($request->templateId, $request->userId);
		if ($accessError !== null)
		{
			$response->addError($accessError);

			return $response;
		}

		$limit = self::resolveChangeEventsLimit($request->limit);

		try
		{
			$rows = $this->getChangeEventRows($request->templateId, $request->cursor, $limit + 1);
		}
		catch (SqlQueryException)
		{
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}

		$hasNextPage = count($rows) > $limit;
		$events = array_map(
			fn(array $row): TemplateChangeEvent => $this->makeChangeEvent($row),
			array_slice($rows, 0, $limit),
		);

		return $response
			->setEvents($events)
			->setNextCursor($hasNextPage ? end($events)->id : null)
		;
	}

	private static function resolveChangeEventsLimit(?int $limit): int
	{
		if ($limit === null || $limit <= 0)
		{
			return self::CHANGE_EVENTS_DEFAULT_LIMIT;
		}

		return min($limit, self::CHANGE_EVENTS_MAX_LIMIT);
	}

	private function getChangeEventRows(int $templateId, ?int $cursor, int $limit): array
	{
		$query = $this->getChangeEventQuery($templateId)
			->setOrder(['CREATED' => 'DESC', 'ID' => 'DESC'])
			->setLimit($limit)
		;

		if ($cursor !== null && $cursor > 0)
		{
			$this->applyChangeEventCursor($query, $templateId, $cursor);
		}

		return $query->fetchAll();
	}

	/**
	 * The cursor of the feed stays a plain event id, while the feed itself is ordered by the index the
	 * journal is read through, `(TEMPLATE_ID, CREATED)`. The moment of the event the client stopped at is
	 * therefore read back by its primary key, and the page continues after the pair `(CREATED, ID)`.
	 */
	private function applyChangeEventCursor(Query $query, int $templateId, int $cursor): void
	{
		$cursorRow = WorkflowTemplateChangeTable::query()
			->setSelect(['CREATED'])
			->where('ID', $cursor)
			->where('TEMPLATE_ID', $templateId)
			->fetch()
		;

		$created = $cursorRow['CREATED'] ?? null;
		if (!$created instanceof DateTime)
		{
			// The event of the cursor is gone from the journal; its id alone still moves the feed forward.
			$query->where('ID', '<', $cursor);

			return;
		}

		$query->where(
			Query::filter()
				->logic('or')
				->where('CREATED', '<', $created)
				->where(
					Query::filter()
						->where('CREATED', $created)
						->where('ID', '<', $cursor)
				)
		);
	}

	private function getChangeEventRow(int $templateId, int $eventId): ?array
	{
		$row = $this->getChangeEventQuery($templateId)
			->addSelect('CHANGES')
			->where('ID', $eventId)
			->fetch()
		;

		return $row ?: null;
	}

	/**
	 * The feed reads the summary of an event instead of its changes: the changes of one event weigh as much
	 * as the template it belongs to, and only the page of a single event shows them.
	 */
	private function getChangeEventQuery(int $templateId): Query
	{
		return WorkflowTemplateChangeTable::query()
			->setSelect([
				'ID',
				'EVENT_TYPE',
				'USER_ID',
				'AUTHOR_TYPE',
				'AI_TOOL',
				'INITIATOR_USER_ID',
				'REQUEST_SOURCE',
				'CREATED',
				'VERSION_NUMBER',
				'CHANGES_SUMMARY',
			])
			->where('TEMPLATE_ID', $templateId)
			->where('CREATED', '>=', self::getRetentionWindowStart())
		;
	}

	/**
	 * The shown history is bounded by the retention period itself: an event the cleanup has not reached yet
	 * is already out of the window.
	 */
	private static function getRetentionWindowStart(): DateTime
	{
		return (new DateTime())->add('-' . TemplateHistory::getRetentionDays() . ' days');
	}

	private function makeChangeEvent(array $row): TemplateChangeEvent
	{
		$summary = self::readSummaryCounters($row['CHANGES_SUMMARY'] ?? null);
		$authorId = (int)$row['USER_ID'];
		$initiatorUserId = (int)$row['INITIATOR_USER_ID'];
		$createdTimestamp = self::readCreatedTimestamp($row);

		return new TemplateChangeEvent(
			id: (int)$row['ID'],
			eventType: (int)$row['EVENT_TYPE'],
			authorType: (int)$row['AUTHOR_TYPE'],
			authorId: $authorId > 0 ? $authorId : null,
			initiatorUserId: $initiatorUserId > 0 ? $initiatorUserId : null,
			aiTool: \CBPHelper::isEmptyValue($row['AI_TOOL']) ? null : (string)$row['AI_TOOL'],
			requestSource: \CBPHelper::isEmptyValue($row['REQUEST_SOURCE']) ? null : (string)$row['REQUEST_SOURCE'],
			createdTimestamp: $createdTimestamp,
			versionNumber: $row['VERSION_NUMBER'] === null ? null : (int)$row['VERSION_NUMBER'],
			summary: $summary,
			hasDetails: array_sum($summary) > 0,
			changesPreview: self::readSummaryPreview($row['CHANGES_SUMMARY'] ?? null, $createdTimestamp),
		);
	}

	/**
	 * @return array<string, int> counters of every kind, zero included
	 */
	private static function readSummaryCounters(mixed $storedSummary): array
	{
		$stored = is_array($storedSummary['counters'] ?? null) ? $storedSummary['counters'] : [];

		$counters = [];
		foreach (TemplateChangeKind::cases() as $kind)
		{
			$counters[$kind->value] = (int)($stored[$kind->value] ?? 0);
		}

		return $counters;
	}

	/**
	 * The preview of the feed goes through the same door as the changes of a single event, so a line the
	 * current code cannot read anymore disappears from both instead of only from the details.
	 */
	private static function readSummaryPreview(mixed $storedSummary, int $fallbackTimestamp): array
	{
		return self::toStoredEntries(
			self::stampEntries(self::readStoredEntries($storedSummary['preview'] ?? null), $fallbackTimestamp),
		);
	}

	private static function readCreatedTimestamp(array $row): int
	{
		$created = $row['CREATED'] ?? null;

		return $created instanceof DateTime ? $created->getTimestamp() : 0;
	}

	/**
	 * The latest changes of the event, because a session of an editor keeps growing and the feed is read
	 * for what has just happened.
	 *
	 * @param TemplateChangeEntry[] $entries
	 */
	private static function previewEntries(array $entries): array
	{
		return array_map(
			static fn(TemplateChangeEntry $entry): array => $entry->toArray(),
			array_slice($entries, -self::CHANGES_PREVIEW_LIMIT),
		);
	}

	/**
	 * @param TemplateChangeEntry[] $entries
	 * @return array<string, int> counters of every kind, zero included
	 */
	private static function summarizeEntries(array $entries): array
	{
		$summary = array_fill_keys(array_column(TemplateChangeKind::cases(), 'value'), 0);

		foreach ($entries as $entry)
		{
			++$summary[$entry->kind->value];
		}

		return $summary;
	}

	/**
	 * An event belonging to another template and an event pushed out of the retention window are gone for
	 * the reader exactly like a missing one: all three answer TEMPLATE_CHANGE_NOT_FOUND.
	 */
	public function getChangeEvent(GetChangeEventRequest $request): GetChangeEventResponse
	{
		$response = new GetChangeEventResponse();

		$accessError = $this->getAccessError($request->templateId, $request->userId);
		if ($accessError !== null)
		{
			$response->addError($accessError);

			return $response;
		}

		try
		{
			$row = $this->getChangeEventRow($request->templateId, $request->eventId);
		}
		catch (SqlQueryException)
		{
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}

		if ($row === null)
		{
			$response->addError(ErrorMessage::TEMPLATE_CHANGE_NOT_FOUND->getCodedError());

			return $response;
		}

		return $response
			->setEvent($this->makeChangeEvent($row))
			->setChanges(
				self::stampEntries(self::readStoredEntries($row['CHANGES'] ?? null), self::readCreatedTimestamp($row)),
			)
		;
	}

	/**
	 * The configuration of the version becomes a new draft, the draft that was the freshest one stays
	 * untouched as a backup copy. The published template is not changed and no version is written.
	 */
	public function restoreVersion(RestoreVersionRequest $request): RestoreVersionResponse
	{
		$response = new RestoreVersionResponse();

		$versionResponse = $this->getVersion(
			new GetVersionRequest($request->templateId, $request->versionId, $request->userId),
		);
		if (!$versionResponse->isSuccess())
		{
			$response->addErrors($versionResponse->getErrors());

			return $response;
		}

		$template = $this->getPersistedTemplate(
			$request->templateId,
			[...self::DOCUMENT_TYPE_FIELDS, ...self::SNAPSHOT_FIELDS],
		);
		if ($template === null)
		{
			$response->addError(ErrorMessage::TEMPLATE_NOT_FOUND->getCodedError());

			return $response;
		}

		$backup = WorkflowTemplateDraftTable::getLatestDraftWithDataByTemplateId($request->templateId);

		$restoredState = $this->alignBlockMarks(
			$versionResponse->getTemplateData(),
			$template['TEMPLATE'] ?? [],
		);

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$added = WorkflowTemplateDraftTable::add([
				'MODULE_ID' => $template['MODULE_ID'],
				'ENTITY' => $template['ENTITY'],
				'DOCUMENT_TYPE' => $template['DOCUMENT_TYPE'],
				'TEMPLATE_ID' => $request->templateId,
				'TEMPLATE_DATA' => $restoredState,
				'STATUS' => WorkflowTemplateDraftTable::STATUS_RESTORED,
				'USER_ID' => $request->userId,
				'CREATED' => new DateTime(),
			]);

			$draftId = (int)$added->getId();

			if ($added->isSuccess())
			{
				$keptIds = $backup === null ? [$draftId] : [$draftId, (int)$backup['ID']];

				$this->deleteDraftsExcept($request->templateId, $keptIds);
			}
		}
		catch (\Throwable)
		{
			$this->rollbackTransaction($connection);
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}

		if (!$added->isSuccess())
		{
			$this->rollbackTransaction($connection);
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}

		$connection->commitTransaction();

		$this->recordChange(
			new RecordChangeRequest(
				templateId: $request->templateId,
				attribution: TemplateChangeAttribution::forUser($request->userId, TemplateChangeRequestSource::Editor),
				prevState: is_array($backup['TEMPLATE_DATA'] ?? null) ? $backup['TEMPLATE_DATA'] : $template,
				nextState: self::withDocumentType($restoredState, $template),
			),
		);

		// The draft is written past the editor and starts without a revision: there is nothing for the first
		// write of the client to be compared against.
		return $response->setDraftId($draftId)->setDraftRevision(0);
	}

	/**
	 * The editor calls a block published while the mark of the graph it shows equals the mark of the live
	 * template. A version carries the marks of the publication it was made by, so every block of a restored
	 * draft would look unpublished; a block equal to the live one takes over the mark of that one instead and
	 * the canvas highlights the real difference. Marks themselves are not part of the comparison.
	 */
	private function alignBlockMarks(array $templateData, array $liveTemplate): array
	{
		$blocks = $templateData['TEMPLATE'][0][NodesToTemplate::ELEMENT_CHILDREN] ?? null;
		$liveBlocks = TemplateChangeDiffBuilder::indexBlocksById($liveTemplate);
		if (!is_array($blocks) || $liveBlocks === [])
		{
			return $templateData;
		}

		foreach ($blocks as $index => $block)
		{
			$id = TemplateChangeDiffBuilder::getBlockId($block);
			$liveBlock = $id === null ? null : ($liveBlocks[$id] ?? null);
			if (
				$liveBlock === null
				|| TemplateChangeDiffBuilder::withoutMarks($block) != TemplateChangeDiffBuilder::withoutMarks($liveBlock)
			)
			{
				continue;
			}

			$blocks[$index] = $this->applyPublishedMark($block, $liveBlock);
		}

		$templateData['TEMPLATE'][0][NodesToTemplate::ELEMENT_CHILDREN] = $blocks;

		return $templateData;
	}

	private function applyPublishedMark(array $block, array $liveBlock): array
	{
		$publishedMark = $liveBlock['Node']['node']['published'] ?? null;
		if ($publishedMark === null)
		{
			unset($block['Node']['node']['updated']);
		}
		else
		{
			$block['Node']['node']['updated'] = $publishedMark;
		}

		return $block;
	}

	/**
	 * Drops the draft created by the restore, so the editor returns to the backup copy or, when there is
	 * none, to the published configuration.
	 */
	public function undoRestore(int $templateId, ?int $userId): RestoreVersionResponse
	{
		$response = new RestoreVersionResponse();

		$accessError = $this->getAccessError($templateId, $userId);
		if ($accessError !== null)
		{
			$response->addError($accessError);

			return $response;
		}

		$restored = $this->getRestoredDraft($templateId, withData: true);
		if ($restored === null)
		{
			$response->addError(ErrorMessage::TEMPLATE_RESTORE_UNDO_UNAVAILABLE->getCodedError());

			return $response;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$deleted = WorkflowTemplateDraftTable::delete((int)$restored['ID']);
		}
		catch (\Throwable)
		{
			$this->rollbackTransaction($connection);
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}

		if (!$deleted->isSuccess())
		{
			$this->rollbackTransaction($connection);
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}

		$connection->commitTransaction();

		$remaining = WorkflowTemplateDraftTable::getLatestDraftWithDataByTemplateId($templateId);
		$nextState = is_array($remaining['TEMPLATE_DATA'] ?? null)
			? $remaining['TEMPLATE_DATA']
			: ($this->getPersistedTemplate($templateId, self::SNAPSHOT_FIELDS) ?? [])
		;

		$this->recordChange(
			new RecordChangeRequest(
				templateId: $templateId,
				attribution: TemplateChangeAttribution::forUser((int)$userId, TemplateChangeRequestSource::Editor),
				prevState: is_array($restored['TEMPLATE_DATA'] ?? null) ? $restored['TEMPLATE_DATA'] : [],
				nextState: self::withDocumentType(
					$nextState,
					$this->getPersistedTemplate($templateId, self::DOCUMENT_TYPE_FIELDS) ?? [],
				),
			),
		);

		// The row carries its revision, and it is the freshest draft of the template by construction: the
		// caller has nothing left to look up.
		return $response
			->setDraftId($remaining === null ? null : (int)$remaining['ID'])
			->setDraftRevision($remaining === null ? 0 : (int)$remaining['REVISION'])
		;
	}

	/**
	 * The user keeps the restored draft: the backup copy is not needed anymore.
	 */
	public function discardRestoreBackup(int $templateId, ?int $userId): RestoreVersionResponse
	{
		$response = new RestoreVersionResponse();

		$accessError = $this->getAccessError($templateId, $userId);
		if ($accessError !== null)
		{
			$response->addError($accessError);

			return $response;
		}

		$restored = $this->getRestoredDraft($templateId);
		if ($restored === null)
		{
			return $response;
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$this->deleteDraftsExcept($templateId, [(int)$restored['ID']]);
			// The kept draft stops being a restore: with the backup gone there is nothing to undo,
			// so the editor must not offer the undo after a reload either.
			$updated = WorkflowTemplateDraftTable::update(
				(int)$restored['ID'],
				['STATUS' => WorkflowTemplateDraftTable::STATUS_RESTORED_WITHOUT_BACKUP],
			);
		}
		catch (\Throwable)
		{
			$this->rollbackTransaction($connection);
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}

		if (!$updated->isSuccess())
		{
			$this->rollbackTransaction($connection);
			$response->addError(ErrorMessage::TEMPLATE_HISTORY_UNAVAILABLE->getCodedError());

			return $response;
		}

		$connection->commitTransaction();

		return $response;
	}

	private function getRestoredDraft(int $templateId, bool $withData = false): ?array
	{
		$latest = $withData
			? WorkflowTemplateDraftTable::getLatestDraftWithDataByTemplateId($templateId)
			: WorkflowTemplateDraftTable::getLatestDraftByTemplateId($templateId)
		;

		return WorkflowTemplateDraftTable::isRestoredDraft($latest) ? $latest : null;
	}

	/**
	 * @param int[] $keptIds never empty: the draft to keep is the reason the deletion happens at all
	 */
	private function deleteDraftsExcept(int $templateId, array $keptIds): void
	{
		WorkflowTemplateDraftTable::deleteByFilter([
			'=TEMPLATE_ID' => $templateId,
			'!@ID' => $keptIds,
		]);
	}

	private function rollbackTransaction(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException $exception)
		{
			if ($exception->getMessage() !== 'Nested rollbacks are unsupported.')
			{
				throw $exception;
			}
		}
	}

	/**
	 * Templates published before the feature was enabled get their first version on the first request that
	 * touches the history. The initial version only copies the live configuration and the publication
	 * metadata that survived on the template row.
	 */
	public function ensureHistoryInitialized(int $templateId): bool
	{
		$existingTypes = [];
		foreach ($this->getRecordedEvents($templateId) as $row)
		{
			if ((int)$row['EVENT_TYPE'] === TemplateChangeEventType::Creation->value)
			{
				return true;
			}

			$existingTypes[] = (int)$row['PUBLICATION_TYPE'];
		}

		foreach ($this->getActivePublicationTypes() as $type)
		{
			if (in_array($type->value, $existingTypes, true))
			{
				continue;
			}

			$persisted = $this->getPersistedTemplate($templateId);
			if ($persisted === null || \CBPHelper::isEmptyValue($persisted['TEMPLATE']))
			{
				continue;
			}

			$authorId = (int)($persisted['USER_ID'] ?? 0);

			try
			{
				$added = WorkflowTemplateChangeTable::add([
					'TEMPLATE_ID' => $templateId,
					'EVENT_TYPE' => TemplateChangeEventType::Publication->value,
					'USER_ID' => $authorId > 0 ? $authorId : null,
					'CREATED' => $persisted['MODIFIED'] ?? new DateTime(),
					'VERSION_NUMBER' => $this->getNextVersionNumber($templateId),
					'PUBLICATION_TYPE' => $type->value,
					'SNAPSHOT' => $this->buildSnapshot($persisted),
					'HAS_SNAPSHOT' => true,
					'TEMPLATE_MODIFIED' => $persisted['MODIFIED'],
				]);
			}
			// A version number taken by a publication running in parallel means that publication has already
			// given the type its version: the history is initialized, just not by this call.
			catch (DuplicateEntryException)
			{
				continue;
			}
			catch (SqlQueryException)
			{
				return false;
			}

			if (!$added->isSuccess())
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @return TemplatePublicationType[]
	 */
	private function getActivePublicationTypes(): array
	{
		return [TemplatePublicationType::Common];
	}

	/**
	 * Every kind of event the journal of the template already keeps: what it says about the birth of the
	 * template and about the publication types it holds is decided on the same rows.
	 */
	protected function getRecordedEvents(int $templateId): array
	{
		return WorkflowTemplateChangeTable::query()
			->setSelect(['EVENT_TYPE', 'PUBLICATION_TYPE'])
			->where('TEMPLATE_ID', $templateId)
			->setDistinct()
			->fetchAll()
		;
	}

	/**
	 * @param bool $loadAll whether to load every restorable version for rotation
	 * @return TemplateVersion[] freshest first; versions whose body was dropped by rotation are not versions
	 * anymore and never appear here
	 */
	protected function getAvailableVersions(int $templateId, bool $loadAll = false): array
	{
		$query = WorkflowTemplateChangeTable::query()
			->setSelect([
				'ID',
				'VERSION_NUMBER',
				'PUBLICATION_TYPE',
				'CREATED',
				'USER_ID',
				'TEMPLATE_MODIFIED',
				'LIVE_MODIFIED' => 'TEMPLATE.MODIFIED',
			])
			->where('TEMPLATE_ID', $templateId)
			->where('EVENT_TYPE', TemplateChangeEventType::Publication->value)
			->whereNotNull('SNAPSHOT')
			->setOrder(['VERSION_NUMBER' => 'DESC'])
		;
		if (!$loadAll)
		{
			$query->setLimit($this->getKeptVersionCount());
		}

		$rows = $query->fetchAll();

		$currentIds = [];
		foreach ($rows as $row)
		{
			if ($this->isVersionOfLiveTemplate($row))
			{
				$currentIds[(int)$row['PUBLICATION_TYPE']] ??= (int)$row['ID'];
			}
		}

		$versions = [];
		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			$authorId = (int)$row['USER_ID'];

			$versions[] = new TemplateVersion(
				id: $id,
				versionNumber: (int)$row['VERSION_NUMBER'],
				publicationType: (int)$row['PUBLICATION_TYPE'],
				createdTimestamp: $row['CREATED'] instanceof DateTime ? $row['CREATED']->getTimestamp() : null,
				authorId: $authorId > 0 ? $authorId : null,
				isCurrent: in_array($id, $currentIds, true),
			);
		}

		return $versions;
	}

	/**
	 * The current version is the one the template row still carries: writes that go around the publication
	 * path (import, package installation) leave the journal behind, and then no version of it is current.
	 */
	private function isVersionOfLiveTemplate(array $row): bool
	{
		return $row['TEMPLATE_MODIFIED'] instanceof DateTime
			&& $row['LIVE_MODIFIED'] instanceof DateTime
			&& $row['TEMPLATE_MODIFIED']->getTimestamp() === $row['LIVE_MODIFIED']->getTimestamp()
		;
	}

	protected function getSnapshot(int $versionId): array
	{
		$row = WorkflowTemplateChangeTable::query()
			->setSelect(['SNAPSHOT'])
			->where('ID', $versionId)
			->fetch()
		;

		return is_array($row['SNAPSHOT'] ?? null) ? $row['SNAPSHOT'] : [];
	}

	/**
	 * A missing template and a foreign one are indistinguishable from the outside: both answer ACCESS_DENIED.
	 * The journal is a surface of the node editor, so a system template and a non-Nodes one are refused the
	 * same way: they live outside the template ACL and never read the draft a restore writes.
	 */
	private function getAccessError(int $templateId, ?int $userId): ?Error
	{
		if (!TemplateHistory::isEnabled())
		{
			return ErrorMessage::FEATURE_DISABLED->getCodedError();
		}

		$template = $this->getPersistedTemplate($templateId, self::PARTICIPATION_FIELDS);

		$isAllowed =
			$template !== null
			&& $this->isTemplateRowInHistory($template)
			&& $this->templateAccessService->canEdit($templateId, $userId)
		;

		return $isAllowed ? null : ErrorMessage::ACCESS_DENIED->getCodedError();
	}

	/**
	 * A snapshot of a version and a draft the editor left behind carry the configuration alone, while the
	 * difference asks the activities of the template for the names of their settings and needs the document
	 * type to do it. A state that already carries its own type keeps it.
	 */
	private static function withDocumentType(array $state, array $templateRow): array
	{
		return $state + array_intersect_key($templateRow, array_flip(self::DOCUMENT_TYPE_FIELDS));
	}

	/**
	 * The configuration of the template in the order the journal keeps it: what the caller has just written
	 * is taken from it, the rest from the template row.
	 */
	private function buildSnapshot(array $fields): array
	{
		$snapshot = [];
		foreach (self::SNAPSHOT_FIELDS as $field)
		{
			$snapshot[$field] = $fields[$field] ?? null;
		}

		return $snapshot;
	}

	/**
	 * Rotation never keeps more restorable versions than this: the configured limit, and never fewer than
	 * one version per publication type, because the version currently live is never emptied.
	 */
	private function getKeptVersionCount(): int
	{
		return max(TemplateHistory::getVersionLimit(), count($this->getActivePublicationTypes()));
	}

	/**
	 * Keeps the configured number of restorable versions: the body of the oldest versions above the limit
	 * is dropped, while the row itself stays in the journal as an event. The freshest version of every
	 * publication type is the one currently active and is never emptied.
	 *
	 * @return bool false when the journal refused the rotation: the publication it belongs to is cancelled
	 * rather than reported with the message of the driver.
	 */
	private function rotate(int $templateId): bool
	{
		try
		{
			$available = $this->getAvailableVersions($templateId, loadAll: true);
			$excess = count($available) - TemplateHistory::getVersionLimit();

			foreach (array_reverse($available) as $version)
			{
				if ($excess <= 0)
				{
					break;
				}

				if ($version->isCurrent)
				{
					continue;
				}

				WorkflowTemplateChangeTable::update($version->id, ['SNAPSHOT' => null, 'HAS_SNAPSHOT' => false]);
				--$excess;
			}
		}
		catch (SqlQueryException)
		{
			return false;
		}

		return true;
	}

	private function getPersistedTemplate(int $templateId, ?array $select = null): ?array
	{
		$row = WorkflowTemplateTable::query()
			->setSelect($select ?? [...self::SNAPSHOT_FIELDS, 'MODIFIED', 'USER_ID'])
			->where('ID', $templateId)
			->fetch()
		;

		return $row ?: null;
	}

	private function getNextVersionNumber(int $templateId): int
	{
		$row = WorkflowTemplateChangeTable::query()
			->addSelect(Query::expr('MAX_VERSION')->max('VERSION_NUMBER'))
			->where('TEMPLATE_ID', $templateId)
			->fetch()
		;

		return (int)($row['MAX_VERSION'] ?? 0) + 1;
	}
}
