<?php

declare(strict_types=1);

namespace Bitrix\Call\Service;

use Bitrix\Call\Call;
use Bitrix\Call\Call\Registry;
use Bitrix\Call\DTO\FollowUp\FollowUpDto;
use Bitrix\Call\DTO\FollowUp\Outcome\EvaluationDto;
use Bitrix\Call\DTO\FollowUp\Outcome\InsightsDto;
use Bitrix\Call\DTO\FollowUp\Outcome\OverviewDto;
use Bitrix\Call\DTO\FollowUp\Outcome\SummaryDto;
use Bitrix\Call\DTO\FollowUp\Outcome\SummarySegmentDto;
use Bitrix\Call\DTO\FollowUp\Outcome\TranscriptionDto;
use Bitrix\Call\DTO\FollowUp\Outcome\TranscriptionSegmentDto;
use Bitrix\Call\DTO\FollowUp\ParticipantDto;
use Bitrix\Call\DTO\FollowUp\TrackDto;
use Bitrix\Call\Integration\AI\Outcome as OutcomeEntity;
use Bitrix\Call\Integration\AI\Outcome\Evaluation;
use Bitrix\Call\Integration\AI\Outcome\Insights;
use Bitrix\Call\Integration\AI\Outcome\OutcomeCollection;
use Bitrix\Call\Integration\AI\Outcome\Overview;
use Bitrix\Call\Integration\AI\Outcome\Summary;
use Bitrix\Call\Integration\AI\Outcome\Transcription;
use Bitrix\Call\Integration\AI\MentionService;
use Bitrix\Call\Integration\AI\SenseType;
use Bitrix\Call\Model\CallOutcomeTable;
use Bitrix\Call\Model\CallTable;
use Bitrix\Call\Model\CallUserTable;
use Bitrix\Call\Track\TrackCollection;
use Bitrix\Call\Util;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Dto\DtoCollection;

class FollowUpReader
{
	public const DEFAULT_LIMIT = 50;
	public const MAX_LIMIT_LIGHT = 200;
	public const MAX_LIMIT_HEAVY = 20;

	public const
		MENTION_FORMAT_BB = MentionService::FORMAT_BB,
		MENTION_FORMAT_HTML = MentionService::FORMAT_HTML,
		MENTION_FORMAT_NONE = MentionService::FORMAT_NONE,
		MENTION_FORMATS = [self::MENTION_FORMAT_BB, self::MENTION_FORMAT_HTML, self::MENTION_FORMAT_NONE];

	private const
		FIELD_CALL_ID = 'callId',
		FIELD_CALL_TYPE = 'callType',
		FIELD_INITIATOR_ID = 'initiatorId',
		FIELD_START_DATE = 'startDate',
		FIELD_END_DATE = 'endDate',
		FIELD_DURATION = 'durationSeconds',
		FIELD_UUID = 'uuid',
		FIELD_PARTICIPANTS = 'participants',
		FIELD_LANGUAGE = 'language',
		FIELD_VERSION = 'version',
		FIELD_OUTCOMES = 'outcomes',
		FIELD_CREATED_AT = 'createdAt',
		FIELD_TRACKS = 'tracks'
	;

	/**
	 * Meta-fields included by default when `select` is null/empty (for `list`).
	 * Only cheap fields available directly in the b_call row — no extra DB hits.
	 * Anything that requires an additional query goes to OPT_IN_META_FIELDS.
	 */
	private const DEFAULT_META_FIELDS = [
		self::FIELD_CALL_ID,
		self::FIELD_CALL_TYPE,
		self::FIELD_INITIATOR_ID,
		self::FIELD_START_DATE,
		self::FIELD_END_DATE,
		self::FIELD_DURATION,
	];

	/**
	 * Opt-in meta-fields. Valid as `select` entries but excluded from the default
	 * payload because each one triggers an extra DB query / join or is rarely needed:
	 *   - uuid          → cheap (in b_call row) but a niche identifier, opt-in by request
	 *   - participants  → b_call_user + b_user
	 *   - tracks        → b_call_track (per-call call)
	 *   - outcomes / language / version / createdAt → b_call_outcome + properties
	 */
	private const OPT_IN_META_FIELDS = [
		self::FIELD_UUID,
		self::FIELD_PARTICIPANTS,
		self::FIELD_TRACKS,
		self::FIELD_OUTCOMES,
		self::FIELD_LANGUAGE,
		self::FIELD_VERSION,
		self::FIELD_CREATED_AT,
	];

	/** All allowed meta-field names — used by validateSelect() to whitelist `select` entries. */
	private const META_FIELDS = [
		...self::DEFAULT_META_FIELDS,
		...self::OPT_IN_META_FIELDS,
	];

	private const OUTCOME_PATHS = [
		'transcription' => SenseType::TRANSCRIBE,
		'transcription.language' => SenseType::TRANSCRIBE,
		'transcription.segments' => SenseType::TRANSCRIBE,
		'overview' => SenseType::OVERVIEW,
		'overview.topic' => SenseType::OVERVIEW,
		'overview.detailedTakeaways' => SenseType::OVERVIEW,
		'overview.meetingType' => SenseType::OVERVIEW,
		'overview.agenda' => SenseType::OVERVIEW,
		'overview.agreements' => SenseType::OVERVIEW,
		'overview.actionItems' => SenseType::OVERVIEW,
		'overview.meetings' => SenseType::OVERVIEW,
		'summary' => SenseType::SUMMARY,
		'insights' => SenseType::INSIGHTS,
		'insights.speakerEvaluationAvailable' => SenseType::INSIGHTS,
		'insights.speakerAnalysis' => SenseType::INSIGHTS,
		'insights.meetingStrengths' => SenseType::INSIGHTS,
		'insights.meetingWeaknesses' => SenseType::INSIGHTS,
		'insights.speechStyleInfluence' => SenseType::INSIGHTS,
		'insights.engagementLevel' => SenseType::INSIGHTS,
		'insights.areasOfResponsibility' => SenseType::INSIGHTS,
		'insights.finalRecommendations' => SenseType::INSIGHTS,
		'evaluation' => SenseType::EVALUATION,
		'evaluation.efficiencyValue' => SenseType::EVALUATION,
		'evaluation.calendar' => SenseType::EVALUATION,
		'evaluation.criteria' => SenseType::EVALUATION,
	];

	private const HEAVY_PATHS = ['transcription', 'transcription.segments', 'overview', 'insights'];

	/**
	 * ISO 8601, UTC, with microseconds — used to serialize the pagination cursor's
	 * START_DATE so it round-trips without precision loss (keyset boundary stays exact).
	 * Distinct from the display formatter (DATE_ATOM, second granularity). {@see parseDateTime()}
	 * accepts both this and the second-precision form.
	 */
	private const CURSOR_DATE_FORMAT = 'Y-m-d\TH:i:s.uP';

	/**
	 * Map raw SenseType enum value -> public-facing outcome block name (matches DTO field names).
	 */
	private const OUTCOME_TYPE_NAME = [
		'transcribe' => 'transcription',
		'overview' => 'overview',
		'summary' => 'summary',
		'insights' => 'insights',
		'evaluation' => 'evaluation',
	];

	private string $mentionFormat = self::MENTION_FORMAT_BB;

	/**
	 * Build a Follow-up DTO for an existing call.
	 * Access checks are the caller's (REST controller) responsibility — this method
	 * only reports `call_not_found` when the row is missing.
	 *
	 * Two modes:
	 *  - $normalizedSelect === null (legacy): full FollowUpDto shape, every null field
	 *    preserved (stable contract for clients that don't care about payload size).
	 *  - $normalizedSelect provided (output of {@see validateSelect()}): only the listed
	 *    fields (plus always-present `callId`) are populated and reach the response —
	 *    same vocabulary and semantics as `call.followup.list`.
	 *
	 * @param string $mentionFormat One of {@see MENTION_FORMATS}. Unknown values fall back to `bb`.
	 * @param array{paths: array<string,bool>, hasAi: bool, heavy: bool, metaOnly: bool}|null $normalizedSelect
	 *     Output of {@see validateSelect()}. Null → full DTO; non-null → field filtering.
	 * @return Result Result with data ['followUp' => FollowUpDto] on success.
	 *                Error code `call_not_found` with customData ['callId' => int] when the call is missing.
	 */
	public function get(
		int $callId,
		string $mentionFormat = self::MENTION_FORMAT_BB,
		?array $normalizedSelect = null,
	): Result
	{
		$result = new Result();
		$this->mentionFormat = $this->normalizeMentionFormat($mentionFormat);

		$call = Registry::getCallWithId($callId);
		if (!$call)
		{
			return $result->addError(new Error(
				sprintf('Call %d not found.', $callId),
				'call_not_found',
				['callId' => $callId],
			));
		}

		if ($normalizedSelect === null)
		{
			$participants = $this->loadParticipants([$call->getId()])[$call->getId()] ?? [];
			$tracks = $this->loadTracks($call->getId());
			$outcomes = OutcomeCollection::getOutcomesByCallId($call->getId());

			$dto = new FollowUpDto();
			$this->fillMeta($dto, $call, $participants, $tracks, $outcomes);
			$this->fillAllAi($dto, $outcomes);

			// Legacy `get` returns the full DTO shape — every null field stays visible.
			$this->markDtoFullyExplicit($dto);

			return $result->setData(['followUp' => $dto]);
		}

		$paths = $normalizedSelect['paths'];

		// Selective mode — load only the surfaces actually referenced by the select.
		$participants = isset($paths[self::FIELD_PARTICIPANTS])
			? ($this->loadParticipants([$call->getId()])[$call->getId()] ?? [])
			: [];
		$tracks = isset($paths[self::FIELD_TRACKS]) ? $this->loadTracks($call->getId()) : null;
		$outcomes = $normalizedSelect['hasAi']
			? iterator_to_array(OutcomeCollection::getOutcomesByCallId($call->getId()))
			: null;

		$dto = $this->buildListItem(
			$this->mapCallToRow($call),
			$participants,
			$tracks,
			$outcomes,
			$normalizedSelect,
		);

		return $result->setData(['followUp' => $dto]);
	}

	private function normalizeMentionFormat(?string $raw): string
	{
		$raw = is_string($raw) ? strtolower(trim($raw)) : '';
		return in_array($raw, self::MENTION_FORMATS, true) ? $raw : self::MENTION_FORMAT_BB;
	}

	/**
	 * Execute a follow-up list query.
	 * All inputs must be pre-validated by the caller (REST controller) — see
	 * {@see validateSelect()} and {@see parseDateRange()} for the validators.
	 *
	 * @param array{paths: array<string,bool>, hasAi: bool, heavy: bool, metaOnly: bool} $normalizedSelect
	 *     Output of validateSelect().
	 * @param int $participantFilter 0 = all participants (admin), >0 = filter to that user.
	 * @param array<string, string>|null $order e.g. ['startDate' => 'desc']
	 * @param array{limit?: int, afterCursor?: array{startDate: string, id: int}}|null $pagination
	 * @return array{items: DtoCollection, hasMore: bool, afterCursor: ?array}
	 */
	public function list(
		array $normalizedSelect,
		DateTime $dateFrom,
		DateTime $dateTo,
		int $participantFilter,
		?array $order,
		?array $pagination,
		string $mentionFormat = self::MENTION_FORMAT_BB,
	): array
	{
		$this->mentionFormat = $this->normalizeMentionFormat($mentionFormat);

		$direction = $this->validateOrder($order)->getData()['direction'] ?? 'desc';
		$pagination = $this->validatePagination($pagination)->getData();
		$limit = $this->clampLimit($normalizedSelect, $pagination['limit'] ?? null);
		$afterCursor = $pagination['afterCursor'] ?? null;

		$rows = $this->queryCalls($dateFrom, $dateTo, $participantFilter, $direction, $limit + 1, $afterCursor);

		$hasMore = count($rows) > $limit;
		if ($hasMore)
		{
			$rows = array_slice($rows, 0, $limit);
		}

		$callIds = array_column($rows, 'ID');
		$paths = $normalizedSelect['paths'];
		$participantsByCall = ($callIds && isset($paths[self::FIELD_PARTICIPANTS]))
			? $this->loadParticipants($callIds)
			: [];
		$tracksByCall = ($callIds && isset($paths[self::FIELD_TRACKS]))
			? $this->loadTracksForCalls($callIds)
			: [];
		// Outcomes preload strategy.
		//   • `hasAi` (set by validateSelect for every path that touches b_call_outcome —
		//     both AI sub-paths and meta-from-outcomes aggregates) gates whether we touch
		//     the table at all.
		//   • If any meta-from-outcomes field is requested (outcomes / language / version /
		//     createdAt — aggregates over the full outcome set), load ALL SenseTypes;
		//     partial type filtering would corrupt those aggregates.
		//   • Otherwise (only narrow AI sub-paths like overview.topic), restrict to the
		//     SenseTypes referenced by those paths.
		$outcomesByCall = [];
		if ($callIds && $normalizedSelect['hasAi'])
		{
			$needsMetaFromOutcomes =
				isset($paths[self::FIELD_OUTCOMES])
				|| isset($paths[self::FIELD_LANGUAGE])
				|| isset($paths[self::FIELD_VERSION])
				|| isset($paths[self::FIELD_CREATED_AT]);
			$outcomeTypesFilter = $needsMetaFromOutcomes
				? [] // all types — required for correct aggregates
				: $this->collectOutcomeTypes($paths); // restrict to requested AI SenseTypes
			if (
				!$needsMetaFromOutcomes
				&& (isset($paths['insights']) || isset($paths['insights.speakerAnalysis']))
			)
			{
				// insights.speakerAnalysis enrichment (talkPercentage / duration / durationFormat)
				// is computed in buildInsights() from Transcription content.
				$outcomeTypesFilter[] = SenseType::TRANSCRIBE->value;
				$outcomeTypesFilter = array_values(array_unique($outcomeTypesFilter));
			}
			$outcomesByCall = $this->loadOutcomesByCallId($callIds, $outcomeTypesFilter);
		}

		$items = new DtoCollection(FollowUpDto::class);
		$last = null;
		foreach ($rows as $row)
		{
			$callId = (int)$row['ID'];
			$dto = $this->buildListItem(
				$row,
				$participantsByCall[$callId] ?? [],
				$tracksByCall[$callId] ?? null,
				$outcomesByCall[$callId] ?? null,
				$normalizedSelect,
			);
			$items->add($dto);
			$last = $row;
		}

		$nextCursor = null;
		if ($hasMore && $last)
		{
			$nextCursor = [
				'startDate' => $this->formatDateTime($last['START_DATE'] ?? null, self::CURSOR_DATE_FORMAT),
				'id' => (int)$last['ID'],
			];
		}

		return [
			'items' => $items,
			'hasMore' => $hasMore,
			'afterCursor' => $nextCursor,
		];
	}

	/**
	 * Validate `select` array against the whitelist of known meta fields and outcome paths.
	 *
	 * @return Result Data on success: array{paths: array<string,bool>, hasAi: bool, heavy: bool, metaOnly: bool}.
	 *                Error on failure has code `invalid_select_field` and customData `['field' => string]`.
	 */
	public function validateSelect(?array $select): Result
	{
		$result = new Result();

		if ($select === null || $select === [])
		{
			return $result->setData([
				'paths' => array_fill_keys(self::DEFAULT_META_FIELDS, true),
				'hasAi' => false,
				'heavy' => false,
				'metaOnly' => true,
			]);
		}

		$paths = [];
		$hasAi = false;
		$heavy = false;
		foreach ($select as $field)
		{
			if (!is_string($field))
			{
				$rendered = match (true)
				{
					is_int($field), is_float($field) => (string)$field,
					default => get_debug_type($field),
				};

				return $result->addError(new Error(
					sprintf('Unknown select field "%s".', $rendered),
					'invalid_select_field',
					['field' => $rendered],
				));
			}
			$field = trim($field);
			if ($field === '')
			{
				return $result->addError(new Error(
					'Unknown select field "".',
					'invalid_select_field',
					['field' => ''],
				));
			}
			if (in_array($field, self::META_FIELDS, true))
			{
				$paths[$field] = true;
				if (in_array($field, [
					self::FIELD_OUTCOMES,
					self::FIELD_LANGUAGE,
					self::FIELD_VERSION,
					self::FIELD_CREATED_AT,
				], true))
				{
					$hasAi = true;
				}
				continue;
			}
			if (isset(self::OUTCOME_PATHS[$field]))
			{
				$paths[$field] = true;
				$hasAi = true;
				if (in_array($field, self::HEAVY_PATHS, true))
				{
					$heavy = true;
				}
				continue;
			}

			return $result->addError(new Error(
				sprintf('Unknown select field "%s".', $field),
				'invalid_select_field',
				['field' => $field],
			));
		}

		// callId is always present so the response is identifiable
		$paths[self::FIELD_CALL_ID] = true;

		return $result->setData([
			'paths' => $paths,
			'hasAi' => $hasAi,
			'heavy' => $heavy,
			'metaOnly' => !$hasAi,
		]);
	}

	/**
	 * Parse and validate filter.startDate.{from,to} into Bitrix DateTime objects.
	 *
	 * @return Result Data on success: ['from' => DateTime, 'to' => DateTime].
	 *                Error on failure has code `invalid_date_range` and message describing the reason.
	 */
	public function parseDateRange(?array $filter): Result
	{
		$result = new Result();
		$startDate = $filter['startDate'] ?? null;
		if (!is_array($startDate))
		{
			return $result->addError(new Error('both from and to are required', 'invalid_date_range'));
		}

		$rawFrom = $startDate['from'] ?? null;
		$rawTo = $startDate['to'] ?? null;
		$dateFrom = is_string($rawFrom) ? $this->parseDateTime($rawFrom) : null;
		$dateTo = is_string($rawTo) ? $this->parseDateTime($rawTo) : null;

		if (!$dateFrom || !$dateTo)
		{
			return $result->addError(new Error('both from and to are required', 'invalid_date_range'));
		}
		if ($dateFrom->getTimestamp() > $dateTo->getTimestamp())
		{
			return $result->addError(new Error('from must be <= to', 'invalid_date_range'));
		}

		return $result->setData(['from' => $dateFrom, 'to' => $dateTo]);
	}

	/**
	 * Strict order validation for cursor-based pagination.
	 *
	 * Public contract: `order` ::= null | { startDate: 'asc' | 'desc' }.
	 * Any unknown key, missing/null value, non-string direction or value other
	 * than `asc`/`desc` (case-insensitive) is rejected — silently degrading to
	 * `desc` would flip the order of cursor-based pages and produce duplicate
	 * rows on the client without HTTP 400.
	 *
	 * @return Result Data on success: ['direction' => 'asc'|'desc'].
	 *                Error code `invalid_order` with customData ['value' => mixed] on failure.
	 */
	public function validateOrder(?array $order): Result
	{
		$result = new Result();
		if ($order === null || $order === [])
		{
			return $result->setData(['direction' => 'desc']);
		}

		$known = ['startDate'];
		foreach (array_keys($order) as $key)
		{
			if (!in_array($key, $known, true))
			{
				return $result->addError(new Error(
					sprintf('Unknown order key "%s".', is_string($key) ? $key : get_debug_type($key)),
					'invalid_order',
					['value' => $order],
				));
			}
		}

		$direction = $order['startDate'] ?? null;
		if (!is_string($direction))
		{
			return $result->addError(new Error(
				'order.startDate must be a string ("asc" or "desc").',
				'invalid_order',
				['value' => $order],
			));
		}
		$direction = strtolower($direction);
		if (!in_array($direction, ['asc', 'desc'], true))
		{
			return $result->addError(new Error(
				sprintf('order.startDate must be "asc" or "desc", got "%s".', $direction),
				'invalid_order',
				['value' => $order],
			));
		}

		return $result->setData(['direction' => $direction]);
	}

	/**
	 * Strict pagination validation. Public contract:
	 *
	 *   pagination ::= null | {
	 *       limit?: int > 0,
	 *       afterCursor?: { startDate: ISO 8601 string, id: int > 0 }
	 *   }
	 *
	 * Any unknown key, non-positive `limit`, missing afterCursor field or
	 * structurally invalid value is rejected. A malformed cursor used to be
	 * swallowed and the endpoint returned the first page — duplicating items
	 * the client had already consumed.
	 *
	 * @return Result Data on success: ['limit' => ?int, 'afterCursor' => ?array{startDate: DateTime, id: int}].
	 *                Error code `invalid_pagination` with customData ['value' => mixed] on failure.
	 */
	public function validatePagination(?array $pagination): Result
	{
		$result = new Result();
		if ($pagination === null || $pagination === [])
		{
			return $result->setData(['limit' => null, 'afterCursor' => null]);
		}

		$known = ['limit', 'afterCursor'];
		foreach (array_keys($pagination) as $key)
		{
			if (!in_array($key, $known, true))
			{
				return $result->addError(new Error(
					sprintf('Unknown pagination key "%s".', is_string($key) ? $key : get_debug_type($key)),
					'invalid_pagination',
					['value' => $pagination],
				));
			}
		}

		$limit = null;
		if (array_key_exists('limit', $pagination))
		{
			$rawLimit = $pagination['limit'];
			if (!is_int($rawLimit) || $rawLimit <= 0)
			{
				return $result->addError(new Error(
					'pagination.limit must be a positive integer.',
					'invalid_pagination',
					['value' => $pagination],
				));
			}
			$limit = $rawLimit;
		}

		$afterCursor = null;
		if (array_key_exists('afterCursor', $pagination))
		{
			$rawCursor = $pagination['afterCursor'];
			if (!is_array($rawCursor))
			{
				return $result->addError(new Error(
					'pagination.afterCursor must be an object with { startDate, id }.',
					'invalid_pagination',
					['value' => $pagination],
				));
			}
			$cursorKnown = ['startDate', 'id'];
			foreach (array_keys($rawCursor) as $key)
			{
				if (!in_array($key, $cursorKnown, true))
				{
					return $result->addError(new Error(
						sprintf('Unknown afterCursor key "%s".', is_string($key) ? $key : get_debug_type($key)),
						'invalid_pagination',
						['value' => $pagination],
					));
				}
			}
			$rawStartDate = $rawCursor['startDate'] ?? null;
			if (!is_string($rawStartDate))
			{
				return $result->addError(new Error(
					'afterCursor.startDate must be an ISO 8601 string.',
					'invalid_pagination',
					['value' => $pagination],
				));
			}
			$startDate = $this->parseDateTime($rawStartDate);
			if (!$startDate)
			{
				return $result->addError(new Error(
					sprintf('afterCursor.startDate "%s" is not a valid ISO 8601 datetime.', $rawStartDate),
					'invalid_pagination',
					['value' => $pagination],
				));
			}
			$rawId = $rawCursor['id'] ?? null;
			if (!is_int($rawId) || $rawId <= 0)
			{
				return $result->addError(new Error(
					'afterCursor.id must be a positive integer.',
					'invalid_pagination',
					['value' => $pagination],
				));
			}
			$afterCursor = ['startDate' => $startDate, 'id' => $rawId];
		}

		return $result->setData(['limit' => $limit, 'afterCursor' => $afterCursor]);
	}

	/**
	 * Resolve the effective page size from the (already validated) requested limit:
	 * cap a positive request at the per-weight maximum, otherwise use the default.
	 *
	 * @param array{paths: array<string,bool>, hasAi: bool, heavy: bool, metaOnly: bool} $normalizedSelect
	 */
	private function clampLimit(array $normalizedSelect, ?int $requested): int
	{
		if ($requested !== null && $requested > 0)
		{
			$max = $normalizedSelect['heavy'] ? self::MAX_LIMIT_HEAVY : self::MAX_LIMIT_LIGHT;

			return min($requested, $max);
		}

		return self::DEFAULT_LIMIT;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function queryCalls(
		DateTime $from,
		DateTime $to,
		int $participantFilter,
		string $direction,
		int $limit,
		?array $afterCursor,
	): array
	{
		$dir = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';

		$query = CallTable::query()
			->setSelect(['ID', 'TYPE', 'INITIATOR_ID', 'PROVIDER', 'STATE', 'UUID', 'START_DATE', 'END_DATE'])
			->where('START_DATE', '>=', $from)
			->where('START_DATE', '<=', $to)
			->setOrder(['START_DATE' => $dir, 'ID' => $dir])
			->setLimit($limit);

		$query->registerRuntimeField(
			new Reference(
				'HAS_OUTCOME',
				CallOutcomeTable::class,
				Join::on('this.ID', 'ref.CALL_ID'),
				['join_type' => Join::TYPE_INNER],
			),
		);
		$query->setGroup(['ID']);

		if ($participantFilter > 0)
		{
			// Match Call::checkAccess() semantics — a user "has access" to the call if either:
			//   1) they are a direct participant (row in b_call_user), OR
			//   2) they are a member of the chat associated with the call (b_im_relation).
			// We LEFT-JOIN both relations on (CALL_ID, USER_ID) and (CHAT_ID, USER_ID)
			// respectively, and require at least one to match. This keeps `list` symmetric
			// with `get` (which uses $call->checkAccess($userId)) — a chat member who
			// didn't actually join the call still sees the follow-up in their list.
			Loader::includeModule('im');

			$query->registerRuntimeField(
				new Reference(
					'DIRECT_PARTICIPATION',
					CallUserTable::class,
					Join::on('this.ID', 'ref.CALL_ID')->where('ref.USER_ID', $participantFilter),
					['join_type' => Join::TYPE_LEFT],
				),
			);
			$query->registerRuntimeField(
				new Reference(
					'CHAT_MEMBERSHIP',
					\Bitrix\Im\Model\RelationTable::class,
					Join::on('this.CHAT_ID', 'ref.CHAT_ID')->where('ref.USER_ID', $participantFilter),
					['join_type' => Join::TYPE_LEFT],
				),
			);
			$query->where(
				\Bitrix\Main\ORM\Query\Query::filter()->logic('or')
					->whereNotNull('DIRECT_PARTICIPATION.USER_ID')
					->whereNotNull('CHAT_MEMBERSHIP.USER_ID'),
			);
		}

		if ($afterCursor)
		{
			$op = $dir === 'ASC' ? '>' : '<';
			// The cursor's startDate is transported with sub-second precision (see
			// CURSOR_DATE_FORMAT), so it round-trips to the exact stored value — the
			// keyset equality branch stays correct without re-reading the row from the DB.
			$boundaryDate = $afterCursor['startDate'];
			$query->where(
				\Bitrix\Main\ORM\Query\Query::filter()->logic('or')
					->where('START_DATE', $op, $boundaryDate)
					->where(
						\Bitrix\Main\ORM\Query\Query::filter()
							->where('START_DATE', $boundaryDate)
							->where('ID', $op, $afterCursor['id'])
					),
			);
		}

		return $query->fetchAll();
	}

	/**
	 * @param int[] $callIds
	 * @return array<int, ParticipantDto[]>  Participants enriched with name/avatar/workPosition.
	 */
	private function loadParticipants(array $callIds): array
	{
		if (!$callIds)
		{
			return [];
		}

		$rows = CallUserTable::query()
			->setSelect(['CALL_ID', 'USER_ID', 'FIRST_JOINED', 'LAST_SEEN'])
			->whereIn('CALL_ID', $callIds)
			->fetchAll();

		$userIds = array_unique(array_map(static fn ($r) => (int)$r['USER_ID'], $rows));
		$userData = $this->loadUserData($userIds);

		$out = [];
		foreach ($rows as $row)
		{
			$userId = (int)$row['USER_ID'];
			$user = $userData[$userId] ?? [];
			$dto = new ParticipantDto();
			$dto->userId = $userId;
			$dto->talkedSeconds = $this->diffSeconds($row['FIRST_JOINED'], $row['LAST_SEEN']);
			$dto->name = isset($user['name']) ? (string)$user['name'] : null;
			$dto->avatar = isset($user['avatar']) ? (string)$user['avatar'] : null;
			$dto->workPosition = isset($user['work_position']) ? (string)$user['work_position'] : null;
			$out[(int)$row['CALL_ID']][] = $dto;
		}

		return $out;
	}

	/**
	 * Loads display data for the given user IDs via the module-wide cached helper.
	 * Delegates to {@see Util::getUsers()} which:
	 *   - reads from im\V2\Entity\User\UserCollection (so avatar URLs are pre-resolved,
	 *     full names are composed, extranet/invited/etc are populated);
	 *   - caches results per-process in Util::$userDataCache, so repeated calls within
	 *     the same request hit the DB only once per user.
	 *
	 * @param int[] $userIds
	 * @return array<int, array<string, mixed>>  Same shape as Util::getUsers().
	 */
	private function loadUserData(array $userIds): array
	{
		return Util::getUsers($userIds);
	}

	/**
	 * @return TrackDto[]
	 */
	private function loadTracks(int $callId): array
	{
		return $this->loadTracksForCalls([$callId])[$callId] ?? [];
	}

	/**
	 * @param int[] $callIds
	 * @return array<int, TrackDto[]>
	 */
	private function loadTracksForCalls(array $callIds): array
	{
		if (!$callIds)
		{
			return [];
		}

		$out = [];
		// TrackCollection::getRecordings is per-call; batch by iteration.
		foreach ($callIds as $callId)
		{
			$collection = TrackCollection::getRecordings((int)$callId) ?? [];
			$tracks = [];
			foreach ($collection as $track)
			{
				$raw = $track->toRestFormat();
				$dto = new TrackDto();
				$dto->trackId = isset($raw['trackId']) ? (int)$raw['trackId'] : null;
				$dto->type = isset($raw['type']) ? (string)$raw['type'] : null;
				$dto->fileId = isset($raw['fileId']) ? (int)$raw['fileId'] : null;
				$dto->diskFileId = isset($raw['diskFileId']) ? (int)$raw['diskFileId'] : null;
				$dto->duration = isset($raw['duration']) ? (int)$raw['duration'] : null;
				$dto->fileSize = isset($raw['fileSize']) ? (int)$raw['fileSize'] : null;
				$dto->fileName = isset($raw['fileName']) ? (string)$raw['fileName'] : null;
				$dto->mimeType = isset($raw['mimeType']) ? (string)$raw['mimeType'] : null;
				$dto->callId = isset($raw['callId']) ? (int)$raw['callId'] : null;
				$dto->relUrl = isset($raw['relUrl']) ? (string)$raw['relUrl'] : null;
				$dto->url = isset($raw['url']) ? (string)$raw['url'] : null;
				$dto->dateCreate = $this->formatDateTime($raw['dateCreate'] ?? null);
				$tracks[] = $dto;
			}
			if ($tracks)
			{
				$out[(int)$callId] = $tracks;
			}
		}

		return $out;
	}

	private function diffSeconds(mixed $start, mixed $end): ?int
	{
		if (!$start instanceof DateTime || !$end instanceof DateTime)
		{
			return null;
		}
		$delta = $end->getTimestamp() - $start->getTimestamp();

		return $delta > 0 ? $delta : 0;
	}

	/**
	 * @param array<string,bool> $selectedPaths
	 * @return string[]  outcome type values
	 */
	private function collectOutcomeTypes(array $selectedPaths): array
	{
		$types = [];
		foreach ($selectedPaths as $path => $_)
		{
			if (!isset(self::OUTCOME_PATHS[$path]))
			{
				continue;
			}
			$types[self::OUTCOME_PATHS[$path]->value] = true;
		}

		return array_keys($types);
	}

	/**
	 * @param int[] $callIds
	 * @param string[] $types
	 * @return array<int, OutcomeEntity[]>
	 */
	private function loadOutcomesByCallId(array $callIds, array $types): array
	{
		$collection = OutcomeCollection::getOutcomesByCallIds($callIds, $types);
		$byCall = [];
		foreach ($collection as $outcome)
		{
			$byCall[(int)$outcome->getCallId()][] = $outcome;
		}

		return $byCall;
	}

	/**
	 * Map a Call entity onto the meta-row shape.
	 *
	 * @return array<string, mixed>
	 */
	private function mapCallToRow(Call $call): array
	{
		return [
			'ID' => $call->getId(),
			'TYPE' => $call->getType(),
			'INITIATOR_ID' => $call->getInitiatorId(),
			'START_DATE' => $call->getStartDate(),
			'END_DATE' => $call->getEndDate(),
			'UUID' => $call->getUuid(),
		];
	}

	/**
	 * @param array<string, mixed> $row
	 * @param ParticipantDto[] $participants
	 * @param TrackDto[]|null $tracks
	 * @param OutcomeEntity[]|null $outcomes
	 * @param array{paths: array<string,bool>, hasAi: bool, heavy: bool, metaOnly: bool} $select
	 */
	private function buildListItem(array $row, array $participants, ?array $tracks, ?array $outcomes, array $select): FollowUpDto
	{
		$dto = new FollowUpDto();
		$paths = $select['paths'];

		if (isset($paths[self::FIELD_CALL_ID]))
		{
			$dto->callId = (int)$row['ID'];
		}
		if (isset($paths[self::FIELD_CALL_TYPE]))
		{
			$dto->callType = (int)$row['TYPE'];
		}
		if (isset($paths[self::FIELD_INITIATOR_ID]))
		{
			$dto->initiatorId = (int)$row['INITIATOR_ID'];
		}
		if (isset($paths[self::FIELD_START_DATE]))
		{
			$dto->startDate = $this->formatDateTime($row['START_DATE'] ?? null);
		}
		if (isset($paths[self::FIELD_END_DATE]))
		{
			$dto->endDate = $this->formatDateTime($row['END_DATE'] ?? null);
		}
		if (isset($paths[self::FIELD_DURATION]))
		{
			$dto->durationSeconds = $this->diffSeconds(
				$row['START_DATE'] instanceof DateTime ? $row['START_DATE'] : null,
				$row['END_DATE'] instanceof DateTime ? $row['END_DATE'] : null,
			);
		}
		if (isset($paths[self::FIELD_UUID]))
		{
			$dto->uuid = $row['UUID'] !== null ? (string)$row['UUID'] : null;
		}
		if (isset($paths[self::FIELD_PARTICIPANTS]))
		{
			$dto->participants = $participants;
		}
		if (isset($paths[self::FIELD_TRACKS]))
		{
			$dto->tracks = $tracks ?? [];
		}

		$wantMetaFromOutcomes = isset($paths[self::FIELD_OUTCOMES])
			|| isset($paths[self::FIELD_LANGUAGE])
			|| isset($paths[self::FIELD_VERSION])
			|| isset($paths[self::FIELD_CREATED_AT]);
		if ($outcomes === null && $wantMetaFromOutcomes)
		{
			$outcomes = iterator_to_array(OutcomeCollection::getOutcomesByCallId((int)$row['ID']));
		}
		if ($outcomes !== null)
		{
			if (isset($paths[self::FIELD_OUTCOMES]))
			{
				$dto->outcomes = $this->collectAvailableOutcomeTypesFromArray($outcomes);
			}
			if (isset($paths[self::FIELD_LANGUAGE]))
			{
				$dto->language = $this->extractLanguageFromArray($outcomes);
			}
			if (isset($paths[self::FIELD_VERSION]))
			{
				$dto->version = $this->extractVersionFromArray($outcomes);
			}
			if (isset($paths[self::FIELD_CREATED_AT]))
			{
				$dto->createdAt = $this->extractCreatedAtFromArray($outcomes);
			}
			$transcription = $this->findTranscriptionContent($outcomes);
			// Same invariant as fillAllAi: apply only the newest contentful outcome per type.
			// $outcomes is an ID-DESC ordered array here; a transient collection preserves that
			// order so mapLatestContentfulByType() picks the newest contentful outcome per type.
			$collection = new OutcomeCollection();
			foreach ($outcomes as $outcome)
			{
				$collection->add($outcome);
			}
			foreach ($collection->mapLatestContentfulByType() as $outcome)
			{
				$this->applyOutcome($dto, $outcome, $paths, $transcription);
			}
		}

		// Map `select` paths → explicit-field markers so requested-but-null fields
		// still appear in the response (rather than being dropped by NullCompactArrayTrait).
		$this->applyExplicitOutputFields($dto, $paths);

		return $dto;
	}

	private function fillMeta(FollowUpDto $dto, Call $call, array $participants, array $tracks, OutcomeCollection $outcomes): void
	{
		$dto->callId = $call->getId();
		$dto->callType = $call->getType();
		$dto->initiatorId = $call->getInitiatorId();
		$dto->startDate = $this->formatDateTime($call->getStartDate());
		$dto->endDate = $this->formatDateTime($call->getEndDate());
		$dto->durationSeconds = $this->diffSeconds($call->getStartDate(), $call->getEndDate());
		$dto->uuid = (string)$call->getUuid() ?: null;
		$dto->participants = $participants;
		$dto->tracks = $tracks;
		$dto->language = $this->extractLanguage($outcomes);
		$dto->version = $this->extractVersion($outcomes);
		$dto->outcomes = $this->collectAvailableOutcomeTypes($outcomes);
		$dto->createdAt = $this->extractCreatedAt($outcomes);
	}

	private function fillAllAi(FollowUpDto $dto, OutcomeCollection $outcomes): void
	{
		// Exactly one outcome per type reaches the DTO — the newest contentful one.
		$transcription = $this->findTranscriptionContent(iterator_to_array($outcomes));
		foreach ($outcomes->mapLatestContentfulByType() as $outcome)
		{
			$this->applyOutcome($dto, $outcome, null, $transcription);
		}
	}

	/**
	 * Used by `get`: every DTO in the tree keeps all nulls in toArray() so the
	 * client sees the complete shape of FollowUpDto regardless of stored data.
	 */
	private function markDtoFullyExplicit(FollowUpDto $dto): void
	{
		$this->keepNullsOn($dto);
		foreach (['transcription', 'overview', 'summary', 'insights', 'evaluation'] as $block)
		{
			$this->keepNullsOn($dto->{$block} ?? null);
		}
		foreach ($dto->participants ?? [] as $participant)
		{
			$this->keepNullsOn($participant);
		}
		foreach ($dto->tracks ?? [] as $track)
		{
			$this->keepNullsOn($track);
		}
	}

	/**
	 * Used by `list`: derive per-DTO explicit-field markers from the normalized
	 * `select` paths so requested-but-null fields still appear in the response.
	 *
	 * Rules:
	 *  - Top-level: every prefix in `paths` becomes a kept field on FollowUpDto.
	 *  - A block listed whole (e.g. `select: ['overview']`) → keepAllNulls on
	 *    that nested DTO.
	 *  - Dotted sub-paths (e.g. `select: ['overview.topic']`) → those subfields
	 *    are explicit on the nested DTO; everything else stays filtered.
	 *  - `participants` / `tracks` arrays: when the collection is requested,
	 *    each element keeps its full shape (no inner null-filtering).
	 *
	 * @param array<string, bool> $paths
	 */
	private function applyExplicitOutputFields(FollowUpDto $dto, array $paths): void
	{
		$dto->setExplicitFields($this->topLevelPathKeys($paths));

		$blockMap = [
			'transcription' => TranscriptionDto::class,
			'overview' => OverviewDto::class,
			'summary' => SummaryDto::class,
			'insights' => InsightsDto::class,
			'evaluation' => EvaluationDto::class,
		];
		foreach ($blockMap as $block => $dtoClass)
		{
			$blockDto = $dto->{$block} ?? null;
			$sub = $this->subPathKeys($paths, $block);
			$blockRequested = isset($paths[$block]) || $sub !== [];

			if ($blockDto === null && $blockRequested)
			{
				$blockDto = new $dtoClass();
				$dto->{$block} = $blockDto;
			}
			if ($blockDto === null || !method_exists($blockDto, 'setExplicitFields'))
			{
				continue;
			}
			if (isset($paths[$block]))
			{
				$blockDto->keepAllNulls();
			}
			elseif ($sub !== [])
			{
				$blockDto->setExplicitFields($sub);
			}
		}

		if (isset($paths[self::FIELD_PARTICIPANTS]) && is_array($dto->participants))
		{
			foreach ($dto->participants as $participant)
			{
				$this->keepNullsOn($participant);
			}
		}
		if (isset($paths[self::FIELD_TRACKS]) && is_array($dto->tracks))
		{
			foreach ($dto->tracks as $track)
			{
				$this->keepNullsOn($track);
			}
		}
	}

	private function keepNullsOn(mixed $obj): void
	{
		if ($obj !== null && method_exists($obj, 'keepAllNulls'))
		{
			$obj->keepAllNulls();
		}
	}

	/**
	 * Unique prefixes (top-level segments) of the paths array.
	 *   ['callId' => true, 'overview.topic' => true, 'participants' => true]
	 *       → ['callId', 'overview', 'participants']
	 *
	 * @param array<string, bool> $paths
	 * @return string[]
	 */
	private function topLevelPathKeys(array $paths): array
	{
		$out = [];
		foreach (array_keys($paths) as $p)
		{
			$out[explode('.', $p, 2)[0]] = true;
		}

		return array_keys($out);
	}

	/**
	 * Subfields under `prefix.*` in the paths.
	 *   subPathKeys(['overview.topic' => true, 'overview.agenda' => true], 'overview')
	 *       → ['topic', 'agenda']
	 *
	 * @param array<string, bool> $paths
	 * @return string[]
	 */
	private function subPathKeys(array $paths, string $prefix): array
	{
		$needle = $prefix . '.';
		$out = [];
		foreach (array_keys($paths) as $p)
		{
			if (str_starts_with($p, $needle))
			{
				$out[] = substr($p, strlen($needle));
			}
		}

		return $out;
	}

	/**
	 * The newest contentful transcription in an ID-DESC ordered outcome list.
	 *
	 * Skips empty later retries so speaker enrichment (talkPercentage) and the transcription
	 * block resolve to the same transcription that mapLatestContentfulByType() selects.
	 *
	 * @param OutcomeEntity[] $outcomes
	 */
	private function findTranscriptionContent(array $outcomes): ?Transcription
	{
		foreach ($outcomes as $outcome)
		{
			if ((string)$outcome->getType() !== SenseType::TRANSCRIBE->value)
			{
				continue;
			}
			$content = $outcome->getSenseContent();
			if ($content instanceof Transcription && $content->hasContent())
			{
				return $content;
			}
		}

		return null;
	}

	/**
	 * @param array<string,bool>|null $paths  null = fill the whole block (for `get`).
	 */
	private function applyOutcome(FollowUpDto $dto, OutcomeEntity $outcome, ?array $paths, ?Transcription $transcription): void
	{
		$content = $outcome->getSenseContent();
		if (!$content)
		{
			return;
		}
		$type = (string)$outcome->getType();

		if ($content instanceof Transcription && $type === SenseType::TRANSCRIBE->value)
		{
			if ($paths === null || $this->touches($paths, 'transcription'))
			{
				$dto->transcription = $this->buildTranscription($content, $paths);
			}
		}
		elseif ($content instanceof Overview && $type === SenseType::OVERVIEW->value)
		{
			if ($paths === null || $this->touches($paths, 'overview'))
			{
				$dto->overview = $this->buildOverview($content, $paths);
			}
		}
		elseif ($content instanceof Summary && $type === SenseType::SUMMARY->value)
		{
			if ($paths === null || $this->touches($paths, 'summary'))
			{
				$dto->summary = $this->buildSummary($content);
			}
		}
		elseif ($content instanceof Insights && $type === SenseType::INSIGHTS->value)
		{
			if ($paths === null || $this->touches($paths, 'insights'))
			{
				$dto->insights = $this->buildInsights($content, $paths, $transcription);
			}
		}
		elseif ($content instanceof Evaluation && $type === SenseType::EVALUATION->value)
		{
			if ($paths === null || $this->touches($paths, 'evaluation'))
			{
				$dto->evaluation = $this->buildEvaluation($content, $paths);
			}
		}
	}

	/**
	 * @param array<string,bool> $paths
	 */
	private function touches(array $paths, string $prefix): bool
	{
		if (isset($paths[$prefix]))
		{
			return true;
		}
		$needle = $prefix . '.';
		foreach (array_keys($paths) as $p)
		{
			if (str_starts_with($p, $needle))
			{
				return true;
			}
		}

		return false;
	}

	private function buildTranscription(Transcription $content, ?array $paths): TranscriptionDto
	{
		$dto = new TranscriptionDto();
		$wantLanguage = $paths === null || isset($paths['transcription']) || isset($paths['transcription.language']);
		$wantSegments = $paths === null || isset($paths['transcription']) || isset($paths['transcription.segments']);

		if ($wantLanguage)
		{
			$dto->language = $content->language ?: null;
		}
		if ($wantSegments)
		{
			$segments = [];
			foreach ($content->toRestFormat($this->mentionFormat) as $row)
			{
				$segment = new TranscriptionSegmentDto();
				$segment->userId = isset($row['userId']) ? (int)$row['userId'] : null;
				$segment->userName = isset($row['user']) ? (string)$row['user'] : null;
				$segment->start = isset($row['start']) ? (string)$row['start'] : null;
				$segment->end = isset($row['end']) ? (string)$row['end'] : null;
				$segment->text = isset($row['text']) ? (string)$row['text'] : null;
				$segments[] = $segment;
			}
			$dto->segments = $segments;
		}

		return $dto;
	}

	private function buildOverview(Overview $content, ?array $paths): OverviewDto
	{
		$rest = $content->toRestFormat($this->mentionFormat);
		$dto = new OverviewDto();
		$wantAll = $paths === null || isset($paths['overview']);

		$assign = static function (string $key, callable $set) use ($paths, $wantAll): void
		{
			if ($wantAll || isset($paths['overview.' . $key]))
			{
				$set();
			}
		};

		$assign('topic', static fn () => $dto->topic = $rest['topic'] ?? null);
		$assign('detailedTakeaways', static fn () => $dto->detailedTakeaways = $rest['detailedTakeaways'] ?? null);
		$assign('meetingType', static fn () => $dto->meetingType = $rest['meetingType'] ?? null);
		$assign('agenda', static fn () => $dto->agenda = $rest['agenda'] ?? null);
		$assign('agreements', static fn () => $dto->agreements = $rest['agreements'] ?? null);
		$assign('actionItems', static fn () => $dto->actionItems = $rest['actionItems'] ?? null);
		$assign('meetings', static fn () => $dto->meetings = $rest['meetings'] ?? null);

		return $dto;
	}

	private function buildSummary(Summary $content): SummaryDto
	{
		$dto = new SummaryDto();
		$rest = $content->toRestFormat($this->mentionFormat);
		$segments = [];
		foreach ($rest as $row)
		{
			$segment = new SummarySegmentDto();
			$segment->start = isset($row['start']) ? (string)$row['start'] : null;
			$segment->end = isset($row['end']) ? (string)$row['end'] : null;
			$segment->title = isset($row['title']) ? (string)$row['title'] : null;
			$segment->summary = isset($row['summary']) ? (string)$row['summary'] : null;
			$segments[] = $segment;
		}
		$dto->segments = $segments;

		return $dto;
	}

	/**
	 * Build Insights DTO. When a Transcription is available and speaker evaluation is enabled,
	 * each speakerAnalysis entry is enriched with talkPercentage/duration/durationFormat
	 * and the array is sorted by (talkPercentage DESC, efficiencyValue DESC) — matching the
	 * legacy `call.FollowUp.outcome` shape.
	 */
	private function buildInsights(Insights $content, ?array $paths, ?Transcription $transcription): InsightsDto
	{
		$rest = $content->toRestFormat($this->mentionFormat);
		$dto = new InsightsDto();
		$wantAll = $paths === null || isset($paths['insights']);
		$speakerEvaluationAvailable = (bool)($rest['speakerEvaluationAvailable'] ?? false);

		$speakerAnalysis = $rest['speakerAnalysis'] ?? null;
		if (
			$speakerEvaluationAvailable
			&& is_array($speakerAnalysis)
			&& $speakerAnalysis
			&& $transcription !== null
		)
		{
			$speakerAnalysis = $this->enrichSpeakerAnalysis($speakerAnalysis, $transcription);
		}

		$assign = static function (string $key, callable $set) use ($paths, $wantAll): void
		{
			if ($wantAll || isset($paths['insights.' . $key]))
			{
				$set();
			}
		};

		$assign('speakerEvaluationAvailable', static fn () => $dto->speakerEvaluationAvailable = $speakerEvaluationAvailable);
		$assign('speakerAnalysis', static fn () => $dto->speakerAnalysis = $speakerAnalysis);
		$assign('meetingStrengths', static fn () => $dto->meetingStrengths = $rest['meetingStrengths'] ?? null);
		$assign('meetingWeaknesses', static fn () => $dto->meetingWeaknesses = $rest['meetingWeaknesses'] ?? null);
		$assign('speechStyleInfluence', static fn () => $dto->speechStyleInfluence = $rest['speechStyleInfluence'] ?? null);
		$assign('engagementLevel', static fn () => $dto->engagementLevel = $rest['engagementLevel'] ?? null);
		$assign('areasOfResponsibility', static fn () => $dto->areasOfResponsibility = $rest['areasOfResponsibility'] ?? null);
		$assign('finalRecommendations', static fn () => $dto->finalRecommendations = $rest['finalRecommendations'] ?? null);

		return $dto;
	}

	/**
	 * Augment each speakerAnalysis entry with talkPercentage / duration / durationFormat
	 * from the Transcription, and sort by (talkPercentage DESC, efficiencyValue DESC).
	 *
	 * Mirrors legacy logic in {@see \Bitrix\Call\Controller\FollowUp::outcomeAction}.
	 *
	 * @param array<int, array> $speakerAnalysis
	 * @return array<int, array>
	 */
	private function enrichSpeakerAnalysis(array $speakerAnalysis, Transcription $transcription): array
	{
		$speakerList = $transcription->prepareSpeakersList(0);
		$enriched = [];
		foreach ($speakerAnalysis as $speaker)
		{
			$userId = (int)($speaker['userId'] ?? 0);
			if ($userId <= 0 || !isset($speakerList[$userId]))
			{
				continue;
			}
			$row = $speakerList[$userId];
			$speaker['talkPercentage'] = $row['talkPercentage'] ?? null;
			$speaker['duration'] = (int)($row['duration'] ?? 0);
			$speaker['durationFormat'] = $this->formatLength((int)($row['duration'] ?? 0));
			$enriched[] = $speaker;
		}

		\Bitrix\Main\Type\Collection::sortByColumn(
			array: $enriched,
			columns: [
				'talkPercentage' => \SORT_DESC,
				'efficiencyValue' => \SORT_DESC,
			],
		);

		return $enriched;
	}

	private function formatLength(int $duration): string
	{
		$hours = $minutes = $seconds = 0;
		if ($duration < 60)
		{
			$seconds = $duration;
		}
		else
		{
			$hours = (int)floor($duration / 3600);
			if ($hours > 0)
			{
				$duration -= $hours * 3600;
			}
			$minutes = (int)floor($duration / 60);
		}

		Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/call/lib/Integration/Chat.php');

		$parts = [];
		if ($hours > 0)
		{
			$parts[] = Loc::getMessage('IM_CALL_INTEGRATION_CHAT_CALL_DURATION_HOURS', ['#HOURS#' => $hours]);
		}
		if ($minutes > 0)
		{
			$parts[] = Loc::getMessage('IM_CALL_INTEGRATION_CHAT_CALL_DURATION_MINUTES', ['#MINUTES#' => $minutes]);
		}
		if ($seconds > 0 && $hours <= 0)
		{
			$parts[] = Loc::getMessage('IM_CALL_INTEGRATION_CHAT_CALL_DURATION_SECONDS', ['#SECONDS#' => $seconds]);
		}

		return implode(' ', array_filter($parts));
	}

	private function buildEvaluation(Evaluation $content, ?array $paths): EvaluationDto
	{
		$rest = $content->toRestFormat($this->mentionFormat);
		$dto = new EvaluationDto();
		$wantAll = $paths === null || isset($paths['evaluation']);

		if ($wantAll || isset($paths['evaluation.efficiencyValue']))
		{
			$dto->efficiencyValue = isset($rest['efficiencyValue']) ? (int)$rest['efficiencyValue'] : null;
		}
		if ($wantAll || isset($paths['evaluation.calendar']))
		{
			$dto->calendar = $rest['calendar'] ?? null;
		}
		if ($wantAll || isset($paths['evaluation.criteria']))
		{
			// Evaluation::toRestFormat puts efficiencyValue + calendar + dynamic criteria
			// keys (agenda_clearly_stated, agenda_items_covered, …) at the same level.
			// Each criterion has shape { value: bool, criteria: string, thoughts: string, title: string }.
			// Strip the two known meta-keys and expose the rest as a map keyed by criterion code.
			$criteria = $rest;
			unset($criteria['efficiencyValue'], $criteria['calendar']);
			$dto->criteria = $criteria ?: null;
		}

		return $dto;
	}

	/**
	 * @return string[]
	 */
	private function collectAvailableOutcomeTypes(OutcomeCollection $outcomes): array
	{
		return $this->collectAvailableOutcomeTypesFromArray(iterator_to_array($outcomes));
	}

	/**
	 * @param OutcomeEntity[] $outcomes
	 * @return string[]
	 */
	private function collectAvailableOutcomeTypesFromArray(array $outcomes): array
	{
		$types = [];
		foreach ($outcomes as $outcome)
		{
			$raw = (string)$outcome->getType();
			$name = self::OUTCOME_TYPE_NAME[$raw] ?? $raw;
			$types[$name] = true;
		}

		return array_keys($types);
	}

	private function extractLanguage(OutcomeCollection $outcomes): ?string
	{
		$transcription = $outcomes->getOutcomeByType(SenseType::TRANSCRIBE->value);
		$content = $transcription?->getSenseContent();
		if ($content instanceof Transcription && $content->language !== '')
		{
			return $content->language;
		}

		return null;
	}

	/**
	 * @param OutcomeEntity[] $outcomes
	 */
	private function extractLanguageFromArray(array $outcomes): ?string
	{
		foreach ($outcomes as $outcome)
		{
			if ((string)$outcome->getType() !== SenseType::TRANSCRIBE->value)
			{
				continue;
			}
			$content = $outcome->getSenseContent();
			if ($content instanceof Transcription && $content->language !== '')
			{
				return $content->language;
			}
		}

		return null;
	}

	private function extractVersion(OutcomeCollection $outcomes): ?int
	{
		return $this->extractVersionFromArray(iterator_to_array($outcomes));
	}

	/**
	 * @param OutcomeEntity[] $outcomes
	 */
	private function extractVersionFromArray(array $outcomes): ?int
	{
		$max = 0;
		foreach ($outcomes as $outcome)
		{
			$content = $outcome->getSenseContent();
			if ($content && method_exists($content, 'getVersion'))
			{
				$v = (int)$content->getVersion();
				if ($v > $max)
				{
					$max = $v;
				}
			}
		}

		return $max > 0 ? $max : null;
	}

	private function extractCreatedAt(OutcomeCollection $outcomes): ?string
	{
		return $this->extractCreatedAtFromArray(iterator_to_array($outcomes));
	}

	/**
	 * @param OutcomeEntity[] $outcomes
	 */
	private function extractCreatedAtFromArray(array $outcomes): ?string
	{
		$latest = null;
		foreach ($outcomes as $outcome)
		{
			$date = $outcome->getDateCreate();
			if ($date instanceof DateTime
				&& ($latest === null || $date->getTimestamp() > $latest->getTimestamp())
			)
			{
				$latest = $date;
			}
		}

		return $this->formatDateTime($latest);
	}

	/**
	 * Strict ISO 8601 parser for filter.startDate.{from,to} and pagination
	 * afterCursor.startDate. The public contract advertises ISO 8601; the old
	 * strtotime-based implementation silently accepted human-readable strings and
	 * normalized non-existent dates (e.g. "2026-02-31"), which could shift query
	 * boundaries and corrupt cursors. We now accept only:
	 *
	 *   YYYY-MM-DDTHH:MM:SS±HH:MM   (e.g. 2026-01-15T10:00:00+00:00)
	 *   YYYY-MM-DDTHH:MM:SS.uuuuuu±HH:MM
	 *
	 * The trailing `Z` is normalized to `+00:00` so the format strings stay tidy.
	 * Any extra warnings/errors from PHP's strict parser disqualify the input.
	 */
	private function parseDateTime(?string $raw): ?DateTime
	{
		if (!is_string($raw) || $raw === '')
		{
			return null;
		}

		$normalizedRaw = str_ends_with($raw, 'Z')
			? substr($raw, 0, -1) . '+00:00'
			: $raw;

		foreach (['Y-m-d\TH:i:sP', self::CURSOR_DATE_FORMAT] as $format)
		{
			$date = \DateTime::createFromFormat($format, $normalizedRaw);
			$errors = \DateTime::getLastErrors() ?: ['warning_count' => 0, 'error_count' => 0];
			if ($date !== false && $errors['warning_count'] === 0 && $errors['error_count'] === 0)
			{
				return DateTime::createFromPhp($date);
			}
		}

		return null;
	}

	/**
	 * Serialize a datetime to UTC ISO 8601. Defaults to second-granular DATE_ATOM for
	 * public display fields; pass {@see CURSOR_DATE_FORMAT} for the precision-preserving
	 * pagination cursor.
	 */
	private function formatDateTime(mixed $value, string $format = DATE_ATOM): ?string
	{
		if ($value instanceof DateTime)
		{
			$copy = clone $value;
			$copy->setTimeZone(new \DateTimeZone('UTC'));

			return $copy->format($format);
		}
		if (is_string($value) && $value !== '')
		{
			$ts = strtotime($value);
			if ($ts !== false && $ts > 0)
			{
				return $this->formatDateTime(DateTime::createFromTimestamp($ts), $format);
			}
		}

		return null;
	}
}
