<?php

namespace Bitrix\Mobile\Internal\Services\Project;

use Bitrix\Main\Localization\Loc;
use Bitrix\Mobile\Internal\Dto\Project\ProjectCreateDto;

Loc::loadMessages(__FILE__);

final class ProjectSettingsValidator
{
	public function validateCreateDto(ProjectCreateDto $dto): void
	{
		$this->validateChatSettings(
			$dto->messageWriters,
			$dto->showHistory,
			$dto->messagesAutoDeleteDelay,
		);

		$this->validateTaskPermissions(
			$dto->taskViewAll,
			$dto->taskSort,
			$dto->taskCreateTasks,
			$dto->taskEditTasks,
			$dto->taskDeleteTasks,
		);

		$this->validateKnowledgePermissions(
			$dto->knowledgeRead,
			$dto->knowledgeEdit,
			$dto->knowledgeSettings,
			$dto->knowledgeDelete,
		);

		$this->validateDateRange(
			$this->parseDate($dto->dateStart),
			$this->parseDate($dto->dateFinish),
		);
	}

	public function validateChatSettings(
		?string $messageWriters = null,
		?string $showHistory = null,
		?int $messagesAutoDeleteDelay = null,
	): void
	{
		if ($messageWriters !== null && $this->mapGroupRoleToChatRight($messageWriters) === null)
		{
			throw new \RuntimeException(Loc::getMessage('MOBILE_PROJECT_SETTINGS_VALIDATOR_INCORRECT_MESSAGE_WRITERS'));
		}

		if ($showHistory !== null && !in_array($showHistory, ['Y', 'N'], true))
		{
			throw new \RuntimeException(Loc::getMessage('MOBILE_PROJECT_SETTINGS_VALIDATOR_INCORRECT_SHOW_HISTORY'));
		}

		if ($messagesAutoDeleteDelay !== null && !$this->isAllowedMessagesAutoDeleteDelay($messagesAutoDeleteDelay))
		{
			throw new \RuntimeException(Loc::getMessage('MOBILE_PROJECT_SETTINGS_VALIDATOR_INCORRECT_AUTO_DELETE_DELAY'));
		}
	}

	public function validateTaskPermissions(
		string $viewAll,
		string $sort,
		string $createTasks,
		string $editTasks,
		string $deleteTasks,
	): void
	{
		$this->validateRolePermissions(
			[$viewAll, $sort, $createTasks, $editTasks, $deleteTasks],
			Loc::getMessage('MOBILE_PROJECT_SETTINGS_VALIDATOR_INCORRECT_TASK_PERMISSION'),
		);
	}

	public function validateKnowledgePermissions(
		string $read,
		string $edit,
		string $settings,
		string $delete,
	): void
	{
		$this->validateRolePermissions(
			[$read, $edit, $settings, $delete],
			Loc::getMessage('MOBILE_PROJECT_SETTINGS_VALIDATOR_INCORRECT_KNOWLEDGE_PERMISSION'),
		);
	}

	public function mapGroupRoleToChatRight(string $role): ?string
	{
		return match ($role)
		{
			SONET_ROLES_OWNER => 'OWNER',
			SONET_ROLES_MODERATOR => 'MANAGER',
			SONET_ROLES_USER => 'MEMBER',
			default => null,
		};
	}

	public function mapChatRightToGroupRole(string $right): ?string
	{
		return match (mb_strtoupper($right))
		{
			'OWNER' => SONET_ROLES_OWNER,
			'MANAGER' => SONET_ROLES_MODERATOR,
			'MEMBER' => SONET_ROLES_USER,
			default => null,
		};
	}

	/**
	 * Reads a project date bound. The write mapper reuses this method, so a value accepted here
	 * always reaches the command as the very same timestamp.
	 *
	 * @return int|null null clears the bound
	 * @throws \RuntimeException when the value cannot be read as a date
	 */
	public function parseDate(?string $date): ?int
	{
		if ($date === null || $date === '')
		{
			return null;
		}

		// getEditSettings returns both bounds as unix timestamps and reports a missing one as 0.
		if ($this->isUnixTimestamp($date))
		{
			$timestamp = (int)$date;

			return $timestamp === 0 ? null : $timestamp;
		}

		$timestamp = strtotime($date);
		if ($timestamp === false)
		{
			throw new \RuntimeException(Loc::getMessage('MOBILE_PROJECT_SETTINGS_VALIDATOR_INCORRECT_DATE'));
		}

		return $timestamp;
	}

	public function validateDateRange(?int $startTs, ?int $finishTs): void
	{
		if ($startTs !== null && $finishTs !== null && $finishTs < $startTs)
		{
			throw new \RuntimeException(Loc::getMessage('MOBILE_PROJECT_SETTINGS_VALIDATOR_INCORRECT_DATE'));
		}
	}

	private function isUnixTimestamp(string $date): bool
	{
		// ctype is not installed on portals, so the digit check goes through preg_match.
		return preg_match('/^-?[0-9]+$/D', $date) === 1;
	}

	private function isAllowedMessagesAutoDeleteDelay(int $delay): bool
	{
		return in_array($delay, [0, 1, 24, 168, 720], true);
	}

	private function validateRolePermissions(array $permissions, string $errorMessage): void
	{
		$allowedRoles = [SONET_ROLES_OWNER, SONET_ROLES_MODERATOR, SONET_ROLES_USER];

		foreach ($permissions as $permission)
		{
			if (!in_array($permission, $allowedRoles, true))
			{
				throw new \RuntimeException($errorMessage);
			}
		}
	}
}
