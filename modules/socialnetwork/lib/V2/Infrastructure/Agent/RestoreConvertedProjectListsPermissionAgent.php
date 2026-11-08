<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Agent;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Update\Stepper;
use Bitrix\Socialnetwork\Collab\Internals\CollabOptionTable;
use Bitrix\Socialnetwork\FeaturePermTable;
use Bitrix\Socialnetwork\FeatureTable;
use Bitrix\Socialnetwork\Log\Logger;
use Bitrix\Socialnetwork\V2\Internal\Cache\DeferredTagCleaner;
use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertStatus;
use Bitrix\Socialnetwork\V2\Internal\Entity\RoleType;
use Bitrix\Socialnetwork\V2\Internal\Repository\Mapper\ConvertProgressMapper;
use Bitrix\Socialnetwork\V2\Internal\Service\Project\FeatureDictionary;
use Bitrix\Socialnetwork\WorkgroupTable;
use CSocNetAllowed;
use CSocNetFeaturesPerms;
use CPHPCache;
use RuntimeException;
use Throwable;

/**
 * Conversion to Project 2.0 used to lock lists with write=None. The tool is switchable now, so the projects
 * converted before that fix have to get their permission back — once per project and without touching anything
 * the owner chose afterwards.
 *
 * Only the value conversion itself could have written is restored: the permission is changed while it still
 * equals None, and the project is marked as processed in the same transaction, so an interrupted run repeats
 * the batch instead of restoring a project twice.
 *
 * The walk happens once per portal. None is reachable after this migration too — the owner may switch lists off
 * himself — and nothing tells such a None from the one conversion wrote, so a second pass would undo the owner's
 * choice. The updater binds the stepper on every module update, therefore the completed walk is remembered in an
 * option of its own and any later run stops at once.
 */
class RestoreConvertedProjectListsPermissionAgent extends Stepper
{
	/** Release moment fixed by the updater: projects created later are not part of the converted cohort. */
	public const CUTOFF_OPTION_NAME = 'lists_permission_restore_cutoff';

	/** Portal-wide flag: the walk has reached its end, the migration must never run again. */
	public const COMPLETED_OPTION_NAME = 'lists_permission_restore_completed';

	public const COMPLETED_OPTION_VALUE = 'Y';

	/** Per-project marker: the project has been through this migration. */
	public const MARKER_OPTION_NAME = 'LISTS_PERMISSION_RESTORED';

	public const MARKER_OPTION_VALUE = 'Y';

	/** A conversion run by the fixed code is outside the legacy restore cohort. */
	public const NEW_CONVERSION_OPTION_NAME = 'LISTS_PERMISSION_NEW_CONVERSION';

	protected static $moduleId = 'socialnetwork';

	private const LIMIT = 50;
	private const OPERATION_ID = 'write';
	private const DEFAULT_WRITE_ROLE = RoleType::Moderator;

	/**
	 * How many runs a single project gets before the walk leaves it behind: a temporary database failure is worth
	 * repeating, a permanent one must not hold the cursor forever.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Statuses of a project that was converted rather than created as a Project 2.0: only such a project could
	 * have got its lists permission from conversion.
	 */
	private const CONVERTED_STATUSES = [
		ConvertStatus::CompletedFromGroup->value,
		ConvertStatus::CompletedFromCollab->value,
	];

	public function execute(array &$option): bool
	{
		try
		{
			return $this->executeBatch($option);
		}
		finally
		{
			// The stepper is a long-running agent; its background jobs are not run after each batch.
			DeferredTagCleaner::getInstance()->flush();
		}
	}

	private function executeBatch(array &$option): bool
	{
		if ($this->isCompleted())
		{
			return self::FINISH_EXECUTION;
		}

		$cutoff = $this->getCutoffTimestamp();
		if ($cutoff <= 0)
		{
			// The walk has not happened: the updater has not fixed the cutoff yet, so the migration stays pending.
			return self::FINISH_EXECUTION;
		}

		$lastId = (int)($option['lastId'] ?? 0);

		$candidates = $this->getCandidates($lastId, self::LIMIT);
		if ($candidates === [])
		{
			$this->markCompleted();

			return self::FINISH_EXECUTION;
		}

		$restorableIds = $this->getRestorableProjectIds(
			array_column($candidates, 'GROUP_ID'),
			$cutoff,
		);
		$standardRole = $this->getStandardWriteRole();
		$attempts = $this->getAttempts($option);

		foreach ($candidates as $candidate)
		{
			$projectId = $candidate['GROUP_ID'];

			if (
				!isset($restorableIds[$projectId])
				|| $candidate['ROLE'] !== RoleType::None->value
			)
			{
				$lastId = max($lastId, $projectId);

				continue;
			}

			$failure = $this->restore($projectId, $candidate['FEATURE_ID'], $standardRole);
			if ($failure === null)
			{
				unset($attempts[$projectId]);
				$lastId = max($lastId, $projectId);

				continue;
			}

			$attempt = (int)($attempts[$projectId] ?? 0) + 1;
			if ($attempt < self::MAX_ATTEMPTS)
			{
				// The cursor stays before the project, so the next run starts with it again.
				$attempts[$projectId] = $attempt;

				break;
			}

			// The failure repeats: the project is left as it is, loudly, and the walk goes on — it has to end.
			unset($attempts[$projectId]);
			$this->logGivenUp($projectId, $attempt, $failure);
			$lastId = max($lastId, $projectId);
		}

		$option['lastId'] = $lastId;
		$option['attempts'] = $attempts;

		return self::CONTINUE_EXECUTION;
	}

	/**
	 * Writes the permission and the marker as one transaction: a project is either restored and remembered,
	 * or left exactly as it was.
	 *
	 * @return string|null the failure message, null when the project is restored
	 */
	private function restore(int $projectId, int $featureId, string $role): ?string
	{
		$connection = $this->getConnection();
		$permissionRowId = null;
		$connection->startTransaction();

		try
		{
			$permissionRowId = $this->lockPermissionIfNone($featureId);
			if ($permissionRowId !== null && !$this->setPermission($featureId, $role))
			{
				throw new RuntimeException(sprintf(
					'unable to set %s permission for feature %d',
					self::OPERATION_ID,
					$featureId,
				));
			}

			$this->saveMarker($projectId);

			$connection->commitTransaction();
		}
		catch (Throwable $exception)
		{
			$connection->rollbackTransaction();
			$this->clearPermissionRowCache($permissionRowId, $projectId);

			$this->logError($projectId, $exception->getMessage());

			return $exception->getMessage();
		}
		$this->clearPermissionRowCache($permissionRowId, $projectId);

		return null;
	}

	/**
	 * Attempts spent on the projects the walk is still stuck at; they live in the stepper option, so they survive
	 * between the runs exactly as long as the walk itself.
	 *
	 * @return array<int, int> projectId => attempts
	 */
	private function getAttempts(array $option): array
	{
		$attempts = [];
		foreach ((array)($option['attempts'] ?? []) as $projectId => $count)
		{
			$attempts[(int)$projectId] = (int)$count;
		}

		return $attempts;
	}

	/**
	 * @param int[] $projectIds
	 *
	 * @return array<int, true> projectId => true for the projects of the pre-release converted cohort
	 *   that have not been restored yet
	 */
	private function getRestorableProjectIds(array $projectIds, int $cutoff): array
	{
		if ($projectIds === [])
		{
			return [];
		}

		$statuses = $this->getConvertStatuses($projectIds);
		$converted = [];
		foreach ($statuses as $projectId => $status)
		{
			if (in_array($status, self::CONVERTED_STATUSES, true))
			{
				$converted[] = $projectId;
			}
		}

		if ($converted === [])
		{
			return [];
		}

		$restorable = array_intersect(
			$converted,
			$this->getProjectIdsCreatedBefore($converted, $cutoff),
		);

		$restorable = array_diff($restorable, $this->getRestoredProjectIds($converted));

		return array_fill_keys($restorable, true);
	}

	/**
	 * Lists permissions still holding the value conversion wrote, ordered by project so the cursor can move on.
	 *
	 * @return array<int, array{GROUP_ID: int, FEATURE_ID: int, ROLE: string}>
	 */
	protected function getCandidates(int $lastId, int $limit): array
	{
		$rows = FeaturePermTable::query()
			->setSelect([
				'FEATURE_ID',
				'ROLE',
				'GROUP_ID' => 'FEATURE.ENTITY_ID',
			])
			->where('FEATURE.ENTITY_TYPE', FeatureTable::FEATURE_ENTITY_TYPE_GROUP)
			->where('FEATURE.FEATURE', FeatureDictionary::GroupLists->value)
			->where('OPERATION_ID', self::OPERATION_ID)
			->where('ROLE', RoleType::None->value)
			->where('FEATURE.ENTITY_ID', '>', $lastId)
			->setOrder(['FEATURE.ENTITY_ID' => 'ASC'])
			->setLimit($limit)
			->fetchAll()
		;

		return array_map(
			static fn (array $row): array => [
				'GROUP_ID' => (int)$row['GROUP_ID'],
				'FEATURE_ID' => (int)$row['FEATURE_ID'],
				'ROLE' => (string)$row['ROLE'],
			],
			$rows,
		);
	}

	/**
	 * @param int[] $projectIds
	 *
	 * @return array<int, string> projectId => convert status
	 */
	protected function getConvertStatuses(array $projectIds): array
	{
		$rows = CollabOptionTable::query()
			->setSelect(['COLLAB_ID', 'VALUE'])
			->whereIn('COLLAB_ID', $projectIds)
			->where('NAME', ConvertProgressMapper::CONVERT_STATUS)
			->fetchAll()
		;

		$statuses = [];
		foreach ($rows as $row)
		{
			$statuses[(int)$row['COLLAB_ID']] = (string)$row['VALUE'];
		}

		return $statuses;
	}

	/**
	 * @param int[] $projectIds
	 *
	 * @return int[]
	 */
	protected function getRestoredProjectIds(array $projectIds): array
	{
		$rows = CollabOptionTable::query()
			->setSelect(['COLLAB_ID'])
			->whereIn('COLLAB_ID', $projectIds)
			->whereIn('NAME', [self::MARKER_OPTION_NAME, self::NEW_CONVERSION_OPTION_NAME])
			->fetchAll()
		;

		return array_map(
			static fn (array $row): int => (int)$row['COLLAB_ID'],
			$rows,
		);
	}

	/**
	 * @param int[] $projectIds
	 *
	 * @return int[]
	 */
	protected function getProjectIdsCreatedBefore(array $projectIds, int $timestamp): array
	{
		$rows = WorkgroupTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $projectIds)
			->where('DATE_CREATE', '<', DateTime::createFromTimestamp($timestamp))
			->fetchAll()
		;

		return array_map(
			static fn (array $row): int => (int)$row['ID'],
			$rows,
		);
	}

	protected function getCutoffTimestamp(): int
	{
		return (int)Option::get(self::$moduleId, self::CUTOFF_OPTION_NAME, '0');
	}

	protected function isCompleted(): bool
	{
		return Option::get(self::$moduleId, self::COMPLETED_OPTION_NAME, '') === self::COMPLETED_OPTION_VALUE;
	}

	protected function markCompleted(): void
	{
		Option::set(self::$moduleId, self::COMPLETED_OPTION_NAME, self::COMPLETED_OPTION_VALUE);
	}

	/**
	 * The value a project that never went through conversion has: taken from the tool itself, so the migration
	 * cannot invent a permission of its own.
	 */
	protected function getStandardWriteRole(): string
	{
		$featureSettings = CSocNetAllowed::GetAllowedFeatures();
		$role = $featureSettings[FeatureDictionary::GroupLists->value]['operations'][self::OPERATION_ID][FeatureTable::FEATURE_ENTITY_TYPE_GROUP] ?? null;

		return is_string($role) && $role !== '' ? $role : self::DEFAULT_WRITE_ROLE->value;
	}

	protected function setPermission(int $featureId, string $role): bool
	{
		return (bool)CSocNetFeaturesPerms::SetPerm(
			$featureId,
			self::OPERATION_ID,
			$role,
			['deferCacheClear' => true],
		);
	}

	/** Hold the current permission row until SetPerm finishes, then honor a role changed since selection. */
	protected function lockPermissionIfNone(int $featureId): ?int
	{
		$row = $this->getConnection()->query(
			'SELECT ID, ROLE FROM b_sonet_features2perms'
			. ' WHERE FEATURE_ID = ' . $featureId
			. " AND OPERATION_ID = '" . self::OPERATION_ID . "' FOR UPDATE",
		)->fetch();

		return ($row['ROLE'] ?? null) === RoleType::None->value ? (int)$row['ID'] : null;
	}

	protected function clearPermissionRowCache(?int $permissionRowId, int $projectId): void
	{
		if ($permissionRowId === null)
		{
			return;
		}
		unset($GLOBALS['arSonetFeaturesPermsCache'][FeatureTable::FEATURE_ENTITY_TYPE_GROUP . '_' . $projectId]);

		if (defined('BX_COMP_MANAGED_CACHE'))
		{
			Application::getInstance()->getTaggedCache()->clearByTag('sonet_features2perms_' . $permissionRowId);

			return;
		}

		$cache = new CPHPCache();
		$cache->CleanDir(
			'/sonet/features_perms/' . FeatureTable::FEATURE_ENTITY_TYPE_GROUP
			. '/' . (int)($projectId / 1000) . '/' . $projectId . '/',
		);
	}

	protected function saveMarker(int $projectId): void
	{
		CollabOptionTable::merge(
			insertFields: [
				'COLLAB_ID' => $projectId,
				'NAME' => self::MARKER_OPTION_NAME,
				'VALUE' => self::MARKER_OPTION_VALUE,
			],
			updateFields: [
				'VALUE' => self::MARKER_OPTION_VALUE,
			],
			uniqueFields: [
				'COLLAB_ID',
				'NAME',
			],
		);
	}

	protected function getConnection(): Connection
	{
		return Application::getConnection();
	}

	protected function logError(int $projectId, string $message): void
	{
		Logger::log(
			[
				'projectId' => $projectId,
				'message' => sprintf(
					'RestoreConvertedProjectListsPermissionAgent: failed to restore lists permission for project [%d]: %s',
					$projectId,
					$message,
				),
			],
			'PROJECT_LISTS_PERMISSION_RESTORE',
		);
	}

	/**
	 * The project the walk has left behind: it stays with the permission conversion wrote, and only this record
	 * tells about it, so it is written apart from the ordinary attempt failures.
	 */
	protected function logGivenUp(int $projectId, int $attempts, string $message): void
	{
		Logger::log(
			[
				'projectId' => $projectId,
				'attempts' => $attempts,
				'message' => sprintf(
					'RestoreConvertedProjectListsPermissionAgent: GIVEN UP on project [%d] after %d attempts,'
					. ' lists permission left unrestored: %s',
					$projectId,
					$attempts,
					$message,
				),
			],
			'PROJECT_LISTS_PERMISSION_RESTORE_GIVEN_UP',
		);
	}
}
