<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Internals\Notification\UseCase;

use Bitrix\Tasks\Internals\Notification\EntityCode;
use Bitrix\Tasks\Internals\Notification\EntityOperation;
use Bitrix\Tasks\Internals\Notification\Message;
use Bitrix\Tasks\Internals\Notification\Metadata;
use Bitrix\Tasks\V2\Internal\Service\Task\Role;
use CTaskLog;

class TaskUpdatedV2 extends AbstractCase
{
	public function execute(array $newFields, array $previousFields, array $params = []): bool
	{
		$changes = CTaskLog::GetChanges($previousFields, $newFields);

		$recipientActionMap = $this->makeRecipientActionMap($newFields, $previousFields);
		$this->createDictionary([
			'options' => $params,
			'recipients' => array_keys($recipientActionMap),
		]);

		foreach ($this->providers as $provider) {
			$sender = $this->getCurrentSender();
			if (is_null($sender))
			{
				continue;
			}

			$recipients = $this->getCurrentRecipients();
			foreach ($recipients as $recipient) {
				if ($sender->getId() === $recipient->getId())
				{
					continue;
				}

				$action = $recipientActionMap[$recipient->getId()] ?? null;

				if (!$action)
				{
					continue;
				}

				$provider->addMessage(new Message(
					$sender,
					$recipient,
					new Metadata(
						EntityCode::CODE_TASK,
						EntityOperation::UPDATE,
						[
							'task' => $this->task,
							'previous_fields' => $previousFields,
							'changes' => $changes, // keep this for save provider interface
							'user_repository' => $this->userRepository,
							'user_params' => $params,
							'update_action' => $action,
						]
					)
				));
			}

			$this->buffer->addProvider($provider);
		}

		return true;
	}

	private function makeRecipientActionMap(array $newFields, array $previousFields): array
	{
		$oldParticipants = $this->getParticipantRoleMap($previousFields);
		$currentParticipants = $this->getParticipantRoleMap($newFields, $previousFields);

		$removedUserIdMap = (array_diff_key($oldParticipants, $currentParticipants));
		$addUserIdMap = (array_diff_key($currentParticipants, $oldParticipants));

		// one user = one notify
		// order of priority: low in top, highest to down
		$recipientIdActionMap = [];

		// notify add user in task
		foreach ($addUserIdMap as $userId => $role) {
			$action = match ($role)
			{
				Role::Auditor => TaskUpdatedV2Action::AddAsAuditor,
				Role::Accomplice => TaskUpdatedV2Action::AddAsAccomplice,
				default => null,
			};

			if ($action)
			{
				$recipientIdActionMap[$userId] = $action;
			}
		}

		// notify remove user from task
		foreach ($removedUserIdMap as $userId => $role) {
			$recipientIdActionMap[$userId] = TaskUpdatedV2Action::RemoveUser;
		}

		return $recipientIdActionMap;
	}

	private function getParticipantRoleMap(array $fieldMap, array $prevMap = []): array
	{
		$map = [];

		$auditors = $fieldMap['AUDITORS'] ?? $prevMap['AUDITORS'] ?? null;
		if (isset($auditors) && is_array($auditors))
		{
			foreach ($auditors as $userId) {
				$map[(int)$userId] = Role::Auditor;
			}
		}

		$accomplices = $fieldMap['ACCOMPLICES'] ?? $prevMap['ACCOMPLICES'] ?? null;
		if (isset($accomplices) && is_array($accomplices))
		{
			foreach ($accomplices as $userId) {
				$map[(int)$userId] = Role::Accomplice;
			}
		}

		if (isset($fieldMap['RESPONSIBLE_ID']))
		{
			$map[(int)$fieldMap['RESPONSIBLE_ID']] = Role::Responsible;
		}
		elseif (isset($prevMap['RESPONSIBLE_ID']))
		{
			$map[(int)$prevMap['RESPONSIBLE_ID']] = Role::Responsible;
		}

		if (isset($fieldMap['CREATED_BY']))
		{
			$map[(int)$fieldMap['CREATED_BY']] = Role::Creator;
		}
		elseif (isset($prevMap['CREATED_BY']))
		{
			$map[(int)$prevMap['CREATED_BY']] = Role::Creator;
		}

		return $map;
	}
}
