<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\AiAssistant\Facade\TracedLogger;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Validation\ValidationService;
use Bitrix\Socialnetwork\V2\Internal\Access\Service\LegacyGroupAccessService;
use Bitrix\Socialnetwork\V2\Internal\Access\Service\ProjectFeatureAccessService;
use Bitrix\Socialnetwork\V2\Internal\Exceptions\InvalidMeetingPeriodException;
use Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Dto\GetProjectMeetingsDto;
use Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Dto\ProjectMeetingDto;
use Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Service\ProjectMeetingResponseMapper;
use Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Service\ProjectMeetingsService;

/**
 * @see \Bitrix\Socialnetwork\V2\Infrastructure\Rest\V3\Controller\Project\Meeting
 */
class GetProjectMeetingsTool extends BaseTool
{
	public const NAME = 'get_project_meetings';

	private const CALENDAR_FEATURE = 'calendar';

	// ProjectMeetingsService is resolved lazily: its constructor is typed with classes of
	// the call module, while the aiassistant framework constructs tools before the canRun()
	// gate, so eager autowiring would fail on portals without the call/calendar modules.
	public function __construct(
		private readonly LegacyGroupAccessService $groupAccessService,
		private readonly ProjectFeatureAccessService $featureAccessService,
		private readonly ProjectMeetingResponseMapper $responseMapper,
		ValidationService $validationService,
		TracedLogger $tracedLogger,
		private ?ProjectMeetingsService $meetingsService = null,
	)
	{
		parent::__construct($validationService, $tracedLogger);
	}

	public function getName(): string
	{
		return self::NAME;
	}

	public function canList(int $userId): bool
	{
		// The calendar feature of a project is registered only with intranet installed and the
		// calendar editable in this edition (\CSocNetAllowed::addAllowedFeature is gated by both),
		// and without the feature the permission check below denies everyone. Such a build must
		// not publish the tool.
		return parent::canList($userId)
			&& Loader::includeModule('intranet')
			&& Loader::includeModule('calendar')
			&& Loader::includeModule('call')
			&& \CBXFeatures::IsFeatureEditable(self::CALENDAR_FEATURE);
	}

	public function canRun(int $userId): bool
	{
		return parent::canRun($userId) && $this->canList($userId);
	}

	public function getDescription(): string
	{
		return
			'Returns an object with a "meetings" array containing finished (already ended) '
			. 'meetings of a project (group), newest first, '
			. 'each enriched with the AI-generated overview of the meeting call when available: '
			. 'meetingId, name, date, plus overview fields '
			. '(topic, agenda, agreements, actionItems, meetings, detailedTakeaways). '
			. 'PREREQ: requires projectId. When projectId is unknown, call '
			. FindProjectByNameTool::NAME . ' first to resolve it. '
			. 'FILTERS (optional): "from"/"to" - meeting date bounds in ISO 8601 '
			. '("YYYY-MM-DD" or "YYYY-MM-DDTHH:MM:SS", optionally with a timezone offset; '
			. 'UTC is assumed when omitted). '
			. 'PERIOD RULE: when the request contains any resolvable date or period, pass "from", '
			. '"to", and "limit": 50 together in the same call; never use the default or a smaller '
			. 'limit for a period. '
			. 'SIZE: "limit" (1..50, default 10) - size of the returned sample. '
			. 'ORDER RULE: preserve the returned root "meetings" array order exactly. It is already '
			. 'newest first; prompt mention order, technical IDs, and AI overview availability must '
			. 'not reorder it. '
			. 'PERIOD DEFAULT: without "from" only the last 30 days are examined; '
			. 'pass "from" explicitly to reach older meetings. '
			. 'PERIOD WIDTH: one call examines at most the last 31 days of the requested period. '
			. 'A wider "from".."to" range is narrowed to the 31 days ending at "to", so meetings '
			. 'earlier in that range are NOT examined. To cover a long period, walk it with '
			. 'several calls of at most 31 days each, moving "to" backwards. '
			. 'WARNING: returns only a BOUNDED SAMPLE of the most recent finished meetings '
			. '(limit, default 10, max 50) - NOT the full meeting history. '
			. 'Calendar lookup examines at most 500 ordinary source events and 100 recurring series '
			. 'of the examined period; '
			. 'additional matching meetings may be omitted when a safety cap is reached. '
			. 'Call overview enrichment examines at most 500 accessible finished calls of that '
			. 'period; an older overview may be missing when this safety cap is reached. '
			. 'NEVER claim project-wide absence based on this result; '
			. 'qualify with "among the meetings I reviewed" or skip the absence claim. '
			. 'NOTE: overview fields are an enrichment and may be missing for a meeting '
			. '(no call took place, no access to the call, or no AI overview was generated). '
			. 'When all overview fields are absent, say only that the AI report is unavailable; '
			. 'do not claim that it was not generated and do not infer a cause. '
			. 'An empty "meetings" array means no finished meetings were found within the bounded scan '
			. 'and filters; it is not an error and does not prove project-wide absence. '
			. 'DOES NOT return scheduled or ongoing meetings. '
			. 'DOES NOT modify anything.'
		;
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'projectId' => [
					'type' => 'integer',
					'description' => 'Identifier of the project whose meetings to return',
					'minimum' => 1,
				],
				'limit' => [
					'type' => ['integer', 'null'],
					'description' => 'Maximum number of meetings to return (default 10; use 50 with any date or period)',
					'minimum' => GetProjectMeetingsDto::LIMIT_MIN,
					'maximum' => GetProjectMeetingsDto::LIMIT_MAX,
				],
				'from' => [
					'type' => ['string', 'null'],
					'description' => 'Lower bound of the meeting date, ISO 8601 (e.g. "2026-06-01" or "2026-06-01T10:00:00"). Defaults to 30 days ago; a range wider than 31 days is narrowed to the 31 days ending at "to"',
				],
				'to' => [
					'type' => ['string', 'null'],
					'description' => 'Upper bound of the meeting date, ISO 8601 (a date-only value is inclusive)',
				],
			],
			'required' => ['projectId'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		$dto = GetProjectMeetingsDto::fromArray($args);

		$this->validate($dto);

		$projectId = (int)$dto->projectId;

		if (!$this->groupAccessService->canRead($userId, $projectId))
		{
			throw new McpException("Access denied to project {$projectId}");
		}

		// Reading the project does not imply reading its calendar: feature permissions are a
		// separate subsystem and may hide the calendar even from a member of the project.
		if (!$this->featureAccessService->canViewFeature($userId, $projectId, self::CALENDAR_FEATURE))
		{
			throw new McpException("Access denied to the calendar of project {$projectId}");
		}

		return ['meetings' => $this->readMeetings($projectId, $dto, $userId)];
	}

	/**
	 * @return list<array<string, mixed>>
	 * @throws McpException
	 */
	private function readMeetings(int $projectId, GetProjectMeetingsDto $dto, int $userId): array
	{
		try
		{
			$meetings = $this->getMeetingsService()->getProjectMeetings(
				$projectId,
				$dto->from,
				$dto->to,
				$dto->limit,
				$userId,
			);

			return array_map(
				fn (ProjectMeetingDto $item): array => $this->responseMapper->map($item->meeting, $item->overview),
				$meetings,
			);
		}
		catch (InvalidMeetingPeriodException $exception)
		{
			throw new McpException($exception->getMessage());
		}
		catch (\Throwable $exception)
		{
			$this->tracedLogger->error('Tool {toolName} failed to read meetings: {message}', [
				'toolName' => self::NAME,
				'message' => $exception->getMessage(),
				'projectId' => $projectId,
				'userId' => $userId,
			]);

			throw new McpException("Could not read the meetings of project {$projectId}");
		}
	}

	private function getMeetingsService(): ProjectMeetingsService
	{
		return $this->meetingsService ??= ServiceLocator::getInstance()->get(ProjectMeetingsService::class);
	}
}
