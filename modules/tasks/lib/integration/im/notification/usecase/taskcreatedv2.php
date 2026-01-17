<?php

namespace Bitrix\Tasks\Integration\IM\Notification\UseCase;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Flow\Provider\OptionProvider;
use Bitrix\Tasks\Integration\IM\Notification;
use Bitrix\Tasks\Internals\Notification\Message;
use Bitrix\Tasks\Internals\Notification\User;
use Bitrix\Tasks\Internals\Notification\UserRepositoryInterface;
use Bitrix\Tasks\Internals\TaskObject;

class TaskCreatedV2
{
	public function getNotification(Message $message): ?Notification
	{
		$metadata = $message->getMetaData();
		$task = $metadata->getTask();
		$userRepository = $metadata->getUserRepository();

		if ($task === null || $userRepository === null)
		{
			return null;
		}

		$recipient = $message->getRecepient();
		if ($task->getCreatedBy() === $recipient->getId())
		{
			return null;
		}

		if ($task->onFlow())
		{
			$flowManualDistributorId = (new OptionProvider())->getManualDistributorId($task->getFlowId());
			if ($flowManualDistributorId === $recipient->getId())
			{
				return null;
			}
		}

		$title = new Notification\Task\Title($task);
		$titleTemplate = new Notification\Template('#TASK_TITLE#', $title->getFormatted($recipient->getLang()));

		$notification = new Notification('TASKS_IM_NOTIFY_TASK_CREATED_MESSAGE', $message);
		$notification->addTemplate($titleTemplate);

		$subjectNotification = new Notification('TASKS_IM_NOTIFY_TASK_CREATED_SUBJECT', $message);
		$subjectNotification->addTemplate($titleTemplate);
		$subjectInstantNotification = new Notification\Task\InstantNotification($subjectNotification);
		$descriptionRows = $this->buildDescription($message);

		$notification->setParams([
			'PARAMS' => [
				'COMPONENT_ID' => 'DefaultEntity',
				'COMPONENT_PARAMS' => [
					'SUBJECT' => $subjectInstantNotification->getMessage(),
					'PLAIN_TEXT' => implode("\r\n", $descriptionRows),
				],
			],
		]);

		return $notification;
	}

	private function buildDescription(Message $message): array
	{
		$descriptionRows = [];
		$metadata = $message->getMetaData();
		$task = $metadata->getTask();
		$recipient = $message->getRecepient();
		$userRepository = $metadata->getUserRepository();
		$responsible = $this->getResponsible($metadata->getTask(), $metadata->getUserRepository());

		if ($responsible instanceof User)
		{
			$descriptionRows[] = Loc::getMessage(
				'TASKS_IM_NOTIFY_TASK_CREATED_ASSIGNEE',
				[
					'#USER_NAME#' => $responsible->toString()
				],
				$recipient->getLang()
			);
		}

		$accomplices = $this->getCommaSeparatedUserNames($userRepository, $task->getAccompliceMembersIds());
		if ($accomplices)
		{
			$descriptionRows[] = Loc::getMessage(
				'TASKS_MESSAGE_ACCOMPLICES',
				[
					'#USER_NAME_LIST#' => $accomplices,
				],
				$recipient->getLang()
			);
		}

		$auditors = $this->getCommaSeparatedUserNames($userRepository, $task->getAuditorMembersIds());
		if ($auditors)
		{
			$descriptionRows[] = Loc::getMessage(
				'TASKS_IM_NOTIFY_TASK_CREATED_AUDITORS',
				[
					'#USER_NAME_LIST#' => $auditors,
				],
				$recipient->getLang()
			);
		}

		if ($task->getDeadline())
		{
			// Get unix timestamp for DEADLINE
			$utsDeadline = $task->getDeadline()->getTimestamp();
			$recipientTimeZoneOffset = $userRepository->getUserTimeZoneOffset($recipient->getId(), true);

			// Make bitrix timestamp for given user
			$bitrixTsDeadline = $utsDeadline + $recipientTimeZoneOffset;
			$deadlineFormat = \Bitrix\Tasks\UI::getHumanDateTimeFormat($bitrixTsDeadline);
			$deadlineAsString = FormatDate($deadlineFormat, $bitrixTsDeadline, false, $recipient->getLang());

			$descriptionRows[] = Loc::getMessage(
				'TASKS_IM_NOTIFY_TASK_CREATED_DEADLINE',
				[
					'#DATE#' => $deadlineAsString,
				],
				$recipient->getLang()
			);
		}

		return $descriptionRows;
	}

	private function getResponsible(TaskObject $task, UserRepositoryInterface $userRepository): ?User
	{
		return ($task->getResponsibleId())
			? $userRepository->getUserById($task->getResponsibleId())
			: null;
	}

	private function getCommaSeparatedUserNames(UserRepositoryInterface $userRepository, array $usersIds): string
	{
		$users = [];

		foreach ($usersIds as $userId)
		{
			$user = $userRepository->getUserById($userId);
			if ($user instanceof User)
			{
				$users[] = $user->toString();
			}
		}

		return implode(', ', $users);
	}
}
