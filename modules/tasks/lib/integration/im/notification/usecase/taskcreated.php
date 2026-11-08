<?php

namespace Bitrix\Tasks\Integration\IM\Notification\UseCase;

use Bitrix\Main\Localization\Loc;
use Bitrix\Tasks\Flow\Provider\OptionProvider;
use Bitrix\Tasks\Integration\IM\Notification;
use Bitrix\Tasks\Internals\Notification\Message;
use Bitrix\Tasks\Internals\Notification\User;
use Bitrix\Tasks\Internals\Notification\UserRepositoryInterface;
use Bitrix\Tasks\Internals\TaskObject;
use Bitrix\Tasks\Util\DateTime\RecipientDateTimeFormatter;

class TaskCreated
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

		// do not send task created notification to the flow distributor
		$flowManualDistributorId = null;
		if ($task->onFlow())
		{
			$flowManualDistributorId = (new OptionProvider())->getManualDistributorId($task->getFlowId());
		}

		$recepient = $message->getRecepient();
		if ($flowManualDistributorId === $recepient->getId())
		{
			return null;
		}

		$responsible = $this->getResponsible($metadata->getTask(), $metadata->getUserRepository());

		$locKey = 'TASKS_NEW_TASK_MESSAGE';
		$nameTemplate = $metadata->getParams()['user_params']['NAME_TEMPLATE'] ?? null;

		$description = '';

		if ($responsible instanceof User)
		{
			$description .= "\r\n" . Loc::getMessage('TASKS_MESSAGE_ASSIGNEE_ID', null, $recepient->getLang());
			$description .= ': '. $responsible->toString($nameTemplate) . "\r\n";
		}

		$accomplices = $this->getCommaSeparatedUserNames($userRepository, $task->getAccompliceMembersIds(), $nameTemplate);
		if ($accomplices)
		{
			$description .= Loc::getMessage('TASKS_MESSAGE_ACCOMPLICES', null, $recepient->getLang());
			$description .= ': ' . $accomplices . "\r\n";
		}

		$auditors = $this->getCommaSeparatedUserNames($userRepository, $task->getAuditorMembersIds(), $nameTemplate);
		if ($auditors)
		{
			$description .= Loc::getMessage('TASKS_MESSAGE_AUDITORS', null, $recepient->getLang());
			$description .= ': ' . $auditors . "\r\n";
		}

		if ($task->getDeadline())
		{
			$deadlineAsString = RecipientDateTimeFormatter::formatForRecipient(
				$task->getDeadline()->getTimestamp(),
				$recepient->getId(),
				$recepient->getLang(),
				'^' . \Bitrix\Tasks\UI::getDateTimeFormat(),
				true,
			);

			$description .= Loc::getMessage('TASKS_MESSAGE_DEADLINE', null, $recepient->getLang());
			$description .= ': ' . $deadlineAsString . "\r\n";
		}

		$notification = new Notification(
			$locKey,
			$message
		);
		$title = new Notification\Task\Title($task);
		$notification->addTemplate(new Notification\Template('#TASK_TITLE#', $title->getFormatted($recepient->getLang())));
		$notification->addTemplate(new Notification\Template('#TASK_EXTRA#', $description));

		return $notification;
	}

	private function getResponsible(TaskObject $task, UserRepositoryInterface $userRepository): ?User
	{
		return ($task->getResponsibleId())
			? $userRepository->getUserById($task->getResponsibleId())
			: null;
	}

	private function getCommaSeparatedUserNames(UserRepositoryInterface $userRepository, array $usersIds, ?string $nameTemplate): string
	{
		$users = [];

		foreach ($usersIds as $userId)
		{
			$user = $userRepository->getUserById($userId);
			if ($user instanceof User)
			{
				$users[] = $user->toString($nameTemplate);
			}
		}

		return implode(', ', $users);
	}
}
