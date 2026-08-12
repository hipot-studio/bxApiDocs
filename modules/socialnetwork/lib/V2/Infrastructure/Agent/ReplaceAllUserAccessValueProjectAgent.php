<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Agent;

use Bitrix\Main\Application;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Socialnetwork\Collab\Control\Log\LogEntryService;
use Bitrix\Socialnetwork\Collab\Log\CollabLogEntryCollection;
use Bitrix\Socialnetwork\Collab\Log\Entry\UpdateCollabLogEntry;
use Bitrix\Socialnetwork\FeaturePermTable;
use Bitrix\Socialnetwork\FeatureTable;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\FeatureDictionary;
use Bitrix\Socialnetwork\WorkgroupTable;
use CSocNetFeaturesPerms;
use Throwable;

class ReplaceAllUserAccessValueProjectAgent
{
	private const LIMIT_PROJECT_COUNT = 10;
	private const ACCESS_ROLE_REPLACE_FROM = SONET_ROLES_ALL;
	private const ACCESS_ROLE_REPLACE_TO = SONET_ROLES_AUTHORIZED;
	private const FEATURE_LIST = [
		FeatureDictionary::Tasks->value,
		FeatureDictionary::Blog->value,
	];
	private const GROUP_TYPE_LIST = [
		'collab',
		'group',
		'project',
	];

	private int $lastProjectId = 0;

	private LogEntryService $logService;

	public function __construct()
	{
		$this->logService = ServiceLocator::getInstance()->get('socialnetwork.collab.log.service');
	}

	public static function execute($lastId = 0): string
	{
		$agent = new self();
		$needsRerun = $agent->run($lastId);

		return $needsRerun ? '\\' . self::class . '::execute(' . $agent->lastProjectId . ');' : '';
	}

	public function run(int $lastId): bool
	{
		$projectIds = $this->getProjectsForReplacePermissions($lastId, self::LIMIT_PROJECT_COUNT);

		if (!$projectIds && $this->lastProjectId <= $lastId)
		{
			return false;
		}

		$logEntryCollection = new CollabLogEntryCollection();

		foreach ($projectIds as $projectId)
		{
			try
			{
				$this->replaceForProject($projectId, $logEntryCollection);
			}
			catch (Throwable $e)
			{
				$this->logError($projectId, 0, '', 'Catch error: ' . $e->getMessage());
			}
		}

		if (!$logEntryCollection->isEmpty())
		{
			$this->logService->saveCollection($logEntryCollection);
		}

		return true;
	}

	private function getProjectsForReplacePermissions(int $lastId, int $limit): array
	{
		$query = FeatureTable::query();
		$subQuery = FeaturePermTable::query()
			->setSelect(['ROLE'])
			->where('FEATURE_ID', new SqlExpression('?#.?#', $query->getInitAlias(), 'ID'))
			->where('ROLE', self::ACCESS_ROLE_REPLACE_FROM)
		;

		$query
			->setSelect(['ENTITY_ID'])
			->setDistinct()
			->where('ENTITY_TYPE', FeatureTable::FEATURE_ENTITY_TYPE_GROUP)
			->whereIn('FEATURE', self::FEATURE_LIST)
			->where('ENTITY_ID', '>', $lastId)
			->whereExists($subQuery)
			->setLimit($limit)
			->setOrder(['ENTITY_ID' => 'ASC'])
		;

		$result = $query->exec();

		$projectIds = [];
		while ($row = $result->fetch())
		{
			$projectIds[] = (int)$row['ENTITY_ID'];
		}

		if (!$projectIds)
		{
			return [];
		}

		$this->lastProjectId = max($projectIds);

		$filteredByType = WorkgroupTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $projectIds)
			->whereIn('TYPE', self::GROUP_TYPE_LIST)
			->fetchAll()
		;

		return array_map(
			static fn ($row): int => (int)$row['ID'],
			$filteredByType,
		);
	}

	private function replaceForProject(int $projectId, CollabLogEntryCollection $logEntryCollection): void
	{
		$permissions = $this->getPermissionsToReplace($projectId);
		if (!$permissions)
		{
			return;
		}

		$userId = $this->getCurrentUserId();

		foreach ($permissions as $permission)
		{
			if (!$this->replacePermission($projectId, $permission))
			{
				continue;
			}

			$logEntry = $this->createLogEntry($projectId, $userId, $permission);
			if ($logEntry !== null)
			{
				$logEntryCollection->add($logEntry);
			}
		}
	}

	private function getPermissionsToReplace(int $projectId): array
	{
		return FeaturePermTable::query()
			->setSelect([
				'FEATURE_ID',
				'OPERATION_ID',
				'ROLE',
				'FEATURE_NAME' => 'FEATURE.FEATURE',
			])
			->where('FEATURE.ENTITY_TYPE', FeatureTable::FEATURE_ENTITY_TYPE_GROUP)
			->where('FEATURE.ENTITY_ID', $projectId)
			->where('ROLE', self::ACCESS_ROLE_REPLACE_FROM)
			->whereIn('FEATURE.FEATURE', self::FEATURE_LIST)
			->fetchAll()
		;
	}

	private function replacePermission(int $projectId, array $permission): bool
	{
		$featureId = (int)($permission['FEATURE_ID'] ?? 0);
		$operationId = (string)($permission['OPERATION_ID'] ?? '');
		$permissionRole = (string)($permission['ROLE'] ?? '');

		if ($featureId <= 0 || $operationId === '' || $permissionRole !== self::ACCESS_ROLE_REPLACE_FROM)
		{
			return false;
		}

		try
		{
			$result = CSocNetFeaturesPerms::SetPerm(
				$featureId,
				$operationId,
				self::ACCESS_ROLE_REPLACE_TO,
			);
		}
		catch (Throwable $exception)
		{
			$this->logError($projectId, $featureId, $operationId, $exception->getMessage());

			return false;
		}

		if (!$result)
		{
			$this->logError($projectId, $featureId, $operationId, 'Replace access role is not success');

			return false;
		}

		return true;
	}

	private function createLogEntry(int $projectId, int $userId, array $permission): ?UpdateCollabLogEntry
	{
		$featureName = (string)($permission['FEATURE_NAME'] ?? '');
		$operationId = (string)($permission['OPERATION_ID'] ?? '');

		if ($featureName === '' || $operationId === '')
		{
			return null;
		}

		$fieldName = UpdateCollabLogEntry::PERMISSION_FIELD_PREFIX . '_' . $featureName . '_' . $operationId;

		return (new UpdateCollabLogEntry(
			userId: $userId,
			collabId: $projectId,
		))
			->setFieldName($fieldName)
			->setPreviousValue(self::ACCESS_ROLE_REPLACE_FROM)
			->setCurrentValue(self::ACCESS_ROLE_REPLACE_TO)
		;
	}

	private function getCurrentUserId(): int
	{
		$currentUserId = (int)CurrentUser::get()->getId();

		return $currentUserId > 0 ? $currentUserId : 1;
	}

	private function logError(int $projectId, int $featureId, string $operationId, string $message): void
	{
		$message = \sprintf(
			'failed to replace access value in project %d, feature %d, operation %s: %s',
			$projectId,
			$featureId,
			$operationId,
			$message,
		);

		Application::getInstance()
			->getExceptionHandler()
			->writeToLog(new \RuntimeException($message))
		;
	}
}
