<?php

namespace Bitrix\Mobile\Tab\Preset\Service;

use Bitrix\HumanResources\Compatibility\Utils\DepartmentBackwardAccessCode;
use Bitrix\HumanResources\Public\Service\Container as HumanResourcesContainer;
use Bitrix\Main\UserTable;
use Bitrix\Mobile\Tab\Preset\Enum\AssignmentTargetType;

final class AssignmentRecipientFormatter
{
	private readonly \Closure $userRecipientLoader;
	private readonly \Closure $departmentRecipientLoader;

	public function __construct(
		private readonly PresetAccessPolicy $accessPolicy = new PresetAccessPolicy(),
		?\Closure $userRecipientLoader = null,
		?\Closure $departmentRecipientLoader = null,
	)
	{
		$this->userRecipientLoader = $userRecipientLoader ?? $this->loadUserRecipients(...);
		$this->departmentRecipientLoader = $departmentRecipientLoader ?? $this->loadDepartmentRecipients(...);
	}

	/**
	 * Formats only assignment targets that have already been filtered for the current viewer.
	 * The formatter deliberately does not perform access checks before loading user data.
	 */
	public function format(array $targetsByPresetId): array
	{
		$userIds = [];
		$departmentIds = [];
		foreach ($targetsByPresetId as $targets)
		{
			foreach ($targets as $target)
			{
				$targetType = $target['type'] ?? null;
				$targetId = (int)($target['id'] ?? 0);
				if (!$targetType instanceof AssignmentTargetType || $targetId <= 0)
				{
					continue;
				}

				if ($targetType === AssignmentTargetType::User)
				{
					$userIds[] = $targetId;
				}
				elseif ($targetType->isDepartment())
				{
					$departmentIds[] = $targetId;
				}
			}
		}

		$userIds = $this->normalizeIds($userIds);
		$departmentIds = $this->normalizeIds($departmentIds);
		$userRecipients = empty($userIds) ? [] : ($this->userRecipientLoader)($userIds);
		$departmentRecipients = empty($departmentIds) ? [] : ($this->departmentRecipientLoader)($departmentIds);

		$result = [];
		foreach ($targetsByPresetId as $presetId => $targets)
		{
			$result[$presetId] = [];
			foreach ($targets as $target)
			{
				$targetType = $target['type'] ?? null;
				$targetId = (int)($target['id'] ?? 0);
				if (!$targetType instanceof AssignmentTargetType || $targetId <= 0)
				{
					continue;
				}

				$recipient = match ($targetType)
				{
					AssignmentTargetType::User => $userRecipients[$targetId] ?? null,
					AssignmentTargetType::HrNode,
					AssignmentTargetType::HrNodeFlat => $this->formatDepartmentRecipient(
						$departmentRecipients[$targetId] ?? null,
						$targetType,
					),
				};
				if ($recipient !== null)
				{
					$result[$presetId][] = $recipient;
				}
			}
		}

		return $result;
	}

	private function loadUserRecipients(array $userIds): array
	{
		if (empty($userIds))
		{
			return [];
		}

		$recipients = [];
		foreach (array_chunk($userIds, 300) as $chunk)
		{
			$result = UserTable::query()
				->setSelect(['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'PERSONAL_PHOTO', 'WORK_POSITION'])
				->whereIn('ID', $chunk)
				->exec()
			;
			while ($user = $result->fetch())
			{
				$userId = (int)$user['ID'];
				$photoId = (int)($user['PERSONAL_PHOTO'] ?? 0);
				$recipients[$userId] = [
					'id' => $userId,
					'targetId' => $userId,
					'type' => 'user',
					'title' => \CUser::FormatName(\CSite::GetNameFormat(), $user, true, false),
					'subtitle' => (string)($user['WORK_POSITION'] ?? ''),
					'imageUrl' => $photoId > 0 ? (string)\CFile::GetPath($photoId) : '',
				];
			}
		}

		return $recipients;
	}

	private function loadDepartmentRecipients(array $departmentIds): array
	{
		if (empty($departmentIds) || !$this->accessPolicy->isHumanResourcesAvailable())
		{
			return [];
		}

		$recipients = [];
		foreach ($departmentIds as $departmentId)
		{
			try
			{
				$node = HumanResourcesContainer::getNodeService()->getById($departmentId);
			}
			catch (\Throwable)
			{
				continue;
			}

			if ($node === null)
			{
				continue;
			}

			$legacyDepartmentId = DepartmentBackwardAccessCode::extractIdFromCode($node->accessCode);
			if ($legacyDepartmentId === null || $legacyDepartmentId <= 0)
			{
				continue;
			}

			$recipients[$departmentId] = [
				'id' => $legacyDepartmentId,
				'type' => 'department',
				'title' => (string)$node->name,
				'subtitle' => '',
				'imageUrl' => '',
			];
		}

		return $recipients;
	}

	private function formatDepartmentRecipient(?array $recipient, AssignmentTargetType $targetType): ?array
	{
		if ($recipient === null)
		{
			return null;
		}

		$scope = $targetType === AssignmentTargetType::HrNodeFlat ? 'flat' : 'recursive';

		return $recipient + [
			'scope' => $scope,
			'selectorId' => $scope === 'flat' ? $recipient['id'] . ':F' : $recipient['id'],
		];
	}

	private function normalizeIds(array $ids): array
	{
		$ids = array_map('intval', $ids);
		$ids = array_filter($ids, static fn(int $id): bool => $id > 0);

		return array_values(array_unique($ids));
	}
}
