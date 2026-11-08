<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Rest\V3\Controller\Project;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Documentation\Attributes\Hidden;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\Internal\InternalException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Socialnetwork\V2\Infrastructure\Rest\V3\Dto\ProjectMeetingItemDto;
use Bitrix\Socialnetwork\V2\Infrastructure\Rest\V3\Request\ListProjectMeetingsRequest;
use Bitrix\Socialnetwork\V2\Internal\Access\Service\LegacyGroupAccessService;
use Bitrix\Socialnetwork\V2\Internal\Access\Service\ProjectFeatureAccessService;
use Bitrix\Socialnetwork\V2\Internal\Exceptions\InvalidMeetingPeriodException;
use Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Dto\ProjectMeetingDto;
use Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Service\ProjectMeetingResponseMapper;
use Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Service\ProjectMeetingsService;

/**
 * Returns finished project meetings with call overviews.
 *
 * @see \Bitrix\Socialnetwork\V2\Internal\Integration\AiAssistant\Tool\GetProjectMeetingsTool
 */
#[DtoType(ProjectMeetingItemDto::class)]
class Meeting extends RestController
{
	private const CALENDAR_FEATURE = 'calendar';

	private ?ProjectMeetingsService $meetingsService = null;
	private ?ProjectMeetingResponseMapper $responseMapper = null;
	private ?LegacyGroupAccessService $groupAccessService = null;
	private ?ProjectFeatureAccessService $featureAccessService = null;

	/**
	 * @restMethod socialnetwork.project.meeting.list
	 */
	#[Hidden]
	public function listAction(ListProjectMeetingsRequest $request): ListResponse
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		$projectId = $request->projectId;

		$this->checkReadAccess($userId, $projectId);

		$items = new DtoCollection(ProjectMeetingItemDto::class);
		foreach ($this->readMeetings($projectId, $request, $userId) as $meeting)
		{
			$items->add($this->mapMeeting($meeting));
		}

		return new ListResponse($items);
	}

	private function checkReadAccess(int $userId, int $projectId): void
	{
		if ($userId <= 0 || !$this->isAvailableInThisBuild())
		{
			throw new AccessDeniedException();
		}

		if (!$this->getGroupAccessService()->canRead($userId, $projectId))
		{
			throw new AccessDeniedException();
		}

		if (!$this->getFeatureAccessService()->canViewFeature($userId, $projectId, self::CALENDAR_FEATURE))
		{
			throw new AccessDeniedException();
		}
	}

	protected function isAvailableInThisBuild(): bool
	{
		return Loader::includeModule('intranet')
			&& Loader::includeModule('calendar')
			&& Loader::includeModule('call')
			&& \CBXFeatures::IsFeatureEditable(self::CALENDAR_FEATURE)
		;
	}

	/**
	 * @return list<ProjectMeetingDto>
	 * @throws RequestValidationException
	 * @throws InternalException
	 */
	private function readMeetings(int $projectId, ListProjectMeetingsRequest $request, int $userId): array
	{
		try
		{
			return $this->getMeetingsService()->getProjectMeetings(
				$projectId,
				$request->from,
				$request->to,
				$request->limit,
				$userId,
			);
		}
		catch (InvalidMeetingPeriodException $exception)
		{
			throw new RequestValidationException([new Error($exception->getMessage())]);
		}
		catch (\Throwable $exception)
		{
			throw new InternalException($exception);
		}
	}

	private function mapMeeting(ProjectMeetingDto $meeting): ProjectMeetingItemDto
	{
		$fields = $this->getResponseMapper()->map($meeting->meeting, $meeting->overview);

		$item = new ProjectMeetingItemDto();
		$item->meetingId = $fields['meetingId'];
		$item->name = $fields['name'];
		$item->date = $fields['date'];
		$item->topic = $fields['topic'] ?? null;
		$item->agenda = $fields['agenda'] ?? null;
		$item->agreements = $fields['agreements'] ?? null;
		$item->actionItems = $fields['actionItems'] ?? null;
		$item->meetings = $fields['meetings'] ?? null;
		$item->detailedTakeaways = $fields['detailedTakeaways'] ?? null;

		return $item;
	}

	/**
	 * Resolves the service lazily because its constructor depends on the optional call module.
	 */
	protected function getMeetingsService(): ProjectMeetingsService
	{
		return $this->meetingsService ??= ServiceLocator::getInstance()->get(ProjectMeetingsService::class);
	}

	protected function getResponseMapper(): ProjectMeetingResponseMapper
	{
		return $this->responseMapper ??= new ProjectMeetingResponseMapper();
	}

	protected function getGroupAccessService(): LegacyGroupAccessService
	{
		return $this->groupAccessService ??= ServiceLocator::getInstance()->get(LegacyGroupAccessService::class);
	}

	protected function getFeatureAccessService(): ProjectFeatureAccessService
	{
		return $this->featureAccessService ??= ServiceLocator::getInstance()->get(ProjectFeatureAccessService::class);
	}
}
