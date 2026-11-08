<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Tasks\Service\Transcription\Mapper;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ExtendedType;
use Bitrix\Im\V2\Integration\Tasks\Service\Transcription\ParticipantResolver;
use Bitrix\Im\V2\Link\Task\TaskService;
use Bitrix\Im\V2\Message;
use Bitrix\Main\Loader;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\FormV2Feature;
use Bitrix\Tasks\V2\Internal\Entity\Group;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\Priority;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use CTimeZone;

class TaskMapper
{
	private const DEADLINE_FORMAT = 'Y/m/d H:i';

	public function __construct(
		private readonly TaskService $taskService,
		private readonly ParticipantResolver $participantResolver,
	)
	{
	}

	public function convertFromTranscribedMessage(
		array $taskData,
		Message $message,
		int $initiatorId,
		bool $isMultipleMessages = false,
	): ?Task
	{
		if (!Loader::includeModule('tasks'))
		{
			return null;
		}

		$title = $taskData['title'] ?? '';
		if (empty($title))
		{
			return null;
		}

		$this->taskService->setContextUser($initiatorId);

		$creator = new User($initiatorId);

		$chat = $message->getChat();

		$responsible = $this->participantResolver->resolveResponsible($taskData['responsible'] ?? '', $chat, $initiatorId);

		return new Task(
			title: $title,
			description: $taskData['description'] ?? '',
			creator: $creator,
			responsible: $responsible,
			deadlineTs: $this->prepareDeadline($taskData['deadline'] ?? '', \Bitrix\Im\V2\Entity\User\User::getInstance($initiatorId)->getTimezone()),
			fileIds: !FormV2Feature::isOn() ? $this->taskService->getFileIdsByMessage($message) : [],
			group: $this->prepareGroup($chat),
			priority: ($taskData['importance'] ?? false) ? Priority::High : Priority::Average,
			accomplices: $this->participantResolver->resolveAccomplices($taskData['accomplices'] ?? [], $chat),
			auditors: $this->prepareAuditors($chat),
			dependsOn: $this->prepareRelatedTasks($chat),
			scenarios: $this->prepareScenarios($message, $isMultipleMessages),
		);
	}

	private function prepareDeadline(string $formattedDeadline, ?string $userTimeZone = null): ?int
	{
		if (empty($formattedDeadline))
		{
			return null;
		}

		try
		{
			$deadline = new DateTime($formattedDeadline, self::DEADLINE_FORMAT);
		}
		catch (ObjectException)
		{
			return 0;
		}

		$timeZoneOffset = CTimeZone::getTimezoneOffset($userTimeZone);

		return $deadline->getTimestamp() - $timeZoneOffset;
	}

	private function prepareGroup(Chat $chat): ?Group
	{
		if ($chat->getEntityType() !== ExtendedType::Sonet->value)
		{
			return null;
		}

		$groupId = (int)$chat->getEntityId();
		if ($groupId <= 0)
		{
			return null;
		}

		return new Group($groupId);
	}

	private function prepareAuditors(Chat $chat): ?UserCollection
	{
		if ($chat->getEntityType() === ExtendedType::Sonet->value)
		{
			return null;
		}

		$auditorIds = $this->taskService->getAuditors($chat);

		return UserCollection::mapFromIds($auditorIds);
	}

	private function prepareRelatedTasks(Chat $chat): ?array
	{
		if ($chat->getEntityType() !== \Bitrix\Tasks\V2\Internal\Integration\Im\Chat::ENTITY_TYPE)
		{
			return null;
		}

		$taskId = (int)$chat->getEntityId();
		if ($taskId <= 0)
		{
			return null;
		}

		return [$taskId];
	}

	private function prepareScenarios(Message $message, bool $isMultipleMessages = false): ?Task\ScenarioCollection
	{
		if ($isMultipleMessages)
		{
			// multiselect: the anchor message type must not mark the task as a voice/video one
			return null;
		}

		if ($message->isVoiceNote())
		{
			return new Task\ScenarioCollection(Task\Scenario::Voice);
		}
		else if ($message->isVideoNote())
		{
			return new Task\ScenarioCollection(Task\Scenario::Video);
		}

		return null;
	}
}
