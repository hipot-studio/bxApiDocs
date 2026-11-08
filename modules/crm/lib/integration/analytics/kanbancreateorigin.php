<?php

namespace Bitrix\Crm\Integration\Analytics;

use Bitrix\Crm\Kanban\Entity\Deadlines\DeadlinesStageManager;
use Bitrix\Crm\Kanban\Entity\EntityActivities;
use Bitrix\Crm\Kanban\ViewMode;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Security\Sign\Signer;

final class KanbanCreateOrigin
{
	private const SALT = 'crm.kanban.create.origin';

	public static function issue(
		int $userId,
		int $entityTypeId,
		int $categoryId,
		string $columnId,
		string $viewMode = ViewMode::MODE_STAGES,
	): ?string
	{
		if (
			$userId <= 0
			|| $entityTypeId <= 0
			|| $categoryId < 0
			|| $columnId === ''
			|| !in_array($viewMode, ViewMode::getAll(), true)
		)
		{
			return null;
		}

		$payload = json_encode([
			'userId' => $userId,
			'entityTypeId' => $entityTypeId,
			'categoryId' => $categoryId,
			'columnId' => $columnId,
			'viewMode' => $viewMode,
			'operation' => 'add',
		], JSON_THROW_ON_ERROR);

		return (new Signer())->sign(base64_encode($payload), self::SALT);
	}

	public static function verify(
		mixed $token,
		int $userId,
		int $entityTypeId,
		int $categoryId,
		string $columnId,
		string $viewMode = ViewMode::MODE_STAGES,
	): bool
	{
		if (!is_string($token) || $token === '' || $userId <= 0 || $columnId === '')
		{
			return false;
		}

		try
		{
			$encodedPayload = (new Signer())->unsign($token, self::SALT);
			$payload = json_decode(base64_decode($encodedPayload, true) ?: '', true, 512, JSON_THROW_ON_ERROR);
		}
		catch (BadSignatureException | \JsonException $exception)
		{
			return false;
		}

		return is_array($payload)
			&& ($payload['userId'] ?? null) === $userId
			&& ($payload['entityTypeId'] ?? null) === $entityTypeId
			&& ($payload['categoryId'] ?? null) === $categoryId
			&& ($payload['columnId'] ?? null) === $columnId
			&& ($payload['viewMode'] ?? null) === $viewMode
			&& ($payload['operation'] ?? null) === 'add'
		;
	}

	public static function verifyForSave(
		mixed $token,
		int $userId,
		int $entityTypeId,
		int $categoryId,
		string $columnId,
		bool $isNew,
		bool $isCopy,
		bool $isConversion,
		string $viewMode = ViewMode::MODE_STAGES,
	): bool
	{
		return $isNew
			&& !$isCopy
			&& !$isConversion
			&& self::verify($token, $userId, $entityTypeId, $categoryId, $columnId, $viewMode)
		;
	}

	public static function isValidColumn(
		int $entityTypeId,
		int $categoryId,
		string $columnId,
		string $viewMode = ViewMode::MODE_STAGES,
	): bool
	{
		if ($columnId === '' || $categoryId < 0 || !in_array($viewMode, ViewMode::getAll(), true))
		{
			return false;
		}

		if ($viewMode === ViewMode::MODE_ACTIVITIES)
		{
			$columnIds = array_column(
				(new EntityActivities($entityTypeId, $categoryId))->getStagesList($categoryId),
				'STATUS_ID',
			);

			return in_array($columnId, $columnIds, true);
		}

		if ($viewMode === ViewMode::MODE_DEADLINES)
		{
			return DeadlinesStageManager::isEntitySupportDeadlines($entityTypeId)
				&& in_array($columnId, [
					DeadlinesStageManager::STAGE_TODAY,
					DeadlinesStageManager::STAGE_THIS_WEEK,
					DeadlinesStageManager::STAGE_NEXT_WEEK,
					DeadlinesStageManager::STAGE_LATER,
				], true)
			;
		}

		if ($entityTypeId === \CCrmOwnerType::Lead)
		{
			return $categoryId === 0 && isset(\CCrmStatus::GetStatusList('STATUS')[$columnId]);
		}

		if ($entityTypeId === \CCrmOwnerType::Deal)
		{
			return \CCrmDeal::IsStageExists($columnId, $categoryId);
		}

		$factory = Container::getInstance()->getFactory($entityTypeId);

		return $factory?->getStageFromCategory($categoryId, $columnId) !== null;
	}

	public static function resolveColumnForSave(string $viewMode, string $stageId, array $params): string
	{
		if ($viewMode === ViewMode::MODE_STAGES)
		{
			return $stageId;
		}

		$columnId = $params['KANBAN_CREATE_COLUMN_ID'] ?? null;
		if (!is_string($columnId))
		{
			return '';
		}

		if ($viewMode === ViewMode::MODE_ACTIVITIES)
		{
			$activityStageId = $params[EntityActivities::ACTIVITY_STAGE_ID] ?? null;
			if ($activityStageId !== null && $activityStageId !== $columnId)
			{
				return '';
			}
		}

		return $columnId;
	}

	public static function appendLegacyEditorController(
		array $controllers,
		array $postFormAnalytics,
		string $origin,
	): array
	{
		$controllers[] = [
			'name' => 'ANALYTICS_CONTROLLER',
			'type' => 'analytics_controller',
			'config' => [
				'postFormAnalytics' => $postFormAnalytics,
				'kanbanCreateOrigin' => $origin,
				'appendParamsFromCurrentUrl' => true,
			],
		];

		return $controllers;
	}
}
