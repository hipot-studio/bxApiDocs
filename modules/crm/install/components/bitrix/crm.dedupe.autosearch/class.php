<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Crm\Merger;
use Bitrix\Crm\Integrity;
use Bitrix\Crm\Integrity\AutoSearchUserSettings;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Error;

if (!\Bitrix\Main\Loader::includeModule('crm'))
{
	ShowError(GetMessage('CRM_MODULE_NOT_INSTALLED'));
	return;
}

class CCrmDedupeAutosearchComponent extends CBitrixComponent implements \Bitrix\Main\Engine\Contract\Controllerable, \Bitrix\Main\Errorable
{
	/** @var $userSettings AutoSearchUserSettings */
	protected $userSettings;
	/** @var  ErrorCollection */
	protected $errorCollection;

	public function getStatisticAction()
	{
		$userSettings = $this->getUserSettings();
		if ($userSettings === null)
		{
			return [];
		}
		if ($userSettings->getStatusId() !== AutoSearchUserSettings::STATUS_CONFLICTS_RESOLVING)
		{
			$this->errorCollection->setError(new Error('Wrong status', 'AUTOSEARCH_WRONG_STATUS'));
			return [];
		}
		// explicit request from the settings popup: recalculate instead of the cached value
		[$successCount, $conflictsCount] = $this->getSuccessAndConflictCount($userSettings, true);

		return [
			'successCount' => $successCount,
			'conflictsCount' => $conflictsCount
		];
	}

	public function mergeAction(string $mergeId): array
	{
		$result = [];
		$userSettings = $this->getUserSettings();
		if ($userSettings === null)
		{
			return [];
		}
		if ($userSettings->getStatusId() !== AutoSearchUserSettings::STATUS_MERGING)
		{
			$this->errorCollection->setError(new Error('Wrong status', 'AUTOSEARCH_WRONG_STATUS'));
			return [];
		}
		$userSettings->tryToSetMergeId($mergeId);
		$result['MERGE_ID'] = $userSettings->getMergeId();

		if ($result['MERGE_ID'] !== $mergeId)
		{
			return $result;
		}

		$entityTypeId = $userSettings->getEntityTypeId();
		$userId = $userSettings->getUserId();
		$progressData = $userSettings->getProgressData();
		$typeIDs = $progressData['TYPE_IDS'];
		$scope = $progressData['CURRENT_SCOPE'];

		if(empty($typeIDs))
		{
			$this->errorCollection->setError(new Error('Index types are not defined or invalid.'));
			return $result;
		}

		$enablePermissionCheck = !\CCrmPerms::IsAdmin($userId);
		$list = new Integrity\AutomaticDuplicateList(
			Integrity\DuplicateIndexType::joinType($typeIDs),
			$entityTypeId,
			$userId,
			$enablePermissionCheck
		);

		$list->setScope($scope);
		$list->setStatusIDs([ Integrity\DuplicateStatus::PENDING ]);

		// One group per step and the user sees only progress: take items in index order
		// instead of sorting all remaining items by name on every step.
		$list->enableIndexOrder(true);

		$isMergeDataStored = (bool)($progressData['MERGE_DATA'] ?? null);
		if (!$isMergeDataStored)
		{
			$progressData['MERGE_DATA'] = [
				'TOTAL' => $list->getRootItemCount(),
				'SUCCESS_STEP' => 0,
				'SUCCESS' => 0,
				'CONFLICT' => 0,
				'ERROR' => 0,
				'PREV_IDS' => []
			];
		}

		$item = $this->getNextMergeItem($list, $progressData['MERGE_DATA']['CURSOR'] ?? null);
		if ($item === null)
		{
			unset($progressData['MERGE_DATA']['CURSOR']);

			if (
				$progressData['MERGE_DATA']['CONFLICT'] > 0 ||
				$progressData['MERGE_DATA']['ERROR'] > 0
			)
			{
				$userSettings->setStatusId(AutoSearchUserSettings::STATUS_CONFLICTS_RESOLVING);
			}
			else
			{
				$userSettings->setStatusId(AutoSearchUserSettings::STATUS_NEW);
			}

			$list->setStatusIDs([
				Integrity\DuplicateStatus::CONFLICT,
				Integrity\DuplicateStatus::POSTPONED,
				Integrity\DuplicateStatus::ERROR
			]);
			$progressData['MERGE_DATA']['SUCCESS'] = $progressData['MERGE_DATA']['TOTAL'] - $list->getRootItemCount();
			Integrity\AutoSearchConflictCountCache::clean($entityTypeId, $userId);
			$userSettings->setProgressData($progressData);

			$userSettings->calcAndSetNextExecTime();
			$userSettings->setMergeId(null);
			$userSettings->save();

			if (Loader::includeModule('pull'))
			{
				\Bitrix\Pull\Event::add($userId, [
					'module_id' => 'crm',
					'command' => 'dedupe.autosearch.mergeComplete',
					'params' => [
						'entityTypeId' => $this->arParams['ENTITY_TYPE_ID'],
						'data' => [
							'SUCCESS_COUNT' => $progressData['MERGE_DATA']['SUCCESS'],
							'CONFLICT_COUNT' => $progressData['MERGE_DATA']['CONFLICT'] + $progressData['MERGE_DATA']['ERROR']
						]
					]
				]);
			}

			$result['STATUS'] = 'COMPLETED';
			return $result;
		}
		$result['STATUS'] = 'SUCCESS';

		$progressData['MERGE_DATA']['CURSOR'] = [
			'TYPE_ID' => (int)$item->getCriterion()->getIndexTypeID(),
			'ID' => (int)$item->getQueueId(),
		];

		$rootEntityID = $item->getRootEntityID();
		$criterion = $item->getCriterion();
		$entityIDs = $criterion->getEntityIDs($entityTypeId, $rootEntityID, $userId, $enablePermissionCheck, ['limit' => 50	]);
		$entityIDs = array_unique($entityIDs);

		$needRemoveIndex = empty($entityIDs);

		if (!$needRemoveIndex)
		{
			$idsToMerge = array_merge([$rootEntityID], $entityIDs);
			sort($idsToMerge, SORT_NUMERIC);
			if ($progressData['MERGE_DATA']['PREV_IDS'] == $idsToMerge)
			{
				$needRemoveIndex = true;
			}
		}

		if($needRemoveIndex)
		{
			//Remove item if merge is not required
			Integrity\Entity\AutomaticDuplicateIndexTable::deleteByFilter(
				array(
					'USER_ID' => $userId,
					'ENTITY_TYPE_ID' => $entityTypeId,
					'TYPE_ID' => $criterion->getIndexTypeID(),
					'=MATCH_HASH' => $criterion->getMatchHash()
				)
			);

			// merge data is first stored by a step that merges, so TOTAL is counted as before
			if ($isMergeDataStored)
			{
				$userSettings->setProgressData($progressData);
			}
			$userSettings
				->setMergeActivityDate(new \Bitrix\Main\Type\DateTime())
				->save();

			return $result;
		}

		$merger = Merger\EntityMerger::create($entityTypeId, $userId, $enablePermissionCheck);
		$merger->setConflictResolutionMode(Merger\ConflictResolutionMode::ASK_USER);
		$merger->setIsAutomatic(true);

		try
		{
			$progressData['MERGE_DATA']['PREV_IDS'] = $idsToMerge;
			$merger->mergeBatch($entityIDs, $rootEntityID, $criterion);
			$progressData['MERGE_DATA']['SUCCESS_STEP']++;
		}
		catch(Merger\EntityMergerException $e)
		{
			if($e->getCode() === Merger\EntityMergerException::CONFLICT_OCCURRED)
			{
				$result['STATUS'] = 'CONFLICT';

				\Bitrix\Crm\Integrity\AutomaticDuplicateIndexBuilder::setStatusID(
					$userId,
					$entityTypeId,
					$criterion->getIndexTypeID(),
					$criterion->getMatchHash(),
					$scope,
					Integrity\DuplicateStatus::CONFLICT
				);
				$progressData['MERGE_DATA']['CONFLICT']++;
			}
			else
			{
				$result['STATUS'] = 'ERROR';
				$result['MESSAGE'] = $e->getLocalizedMessage();

				\Bitrix\Crm\Integrity\AutomaticDuplicateIndexBuilder::setStatusID(
					$userId,
					$entityTypeId,
					$criterion->getIndexTypeID(),
					$criterion->getMatchHash(),
					$scope,
					Integrity\DuplicateStatus::ERROR
				);

				$progressData['MERGE_DATA']['ERROR']++;
			}
		}
		catch(Exception $e)
		{
			$this->errorCollection->setError(new Error($e->getMessage()));
			$progressData['MERGE_DATA']['ERROR']++;
			$result['STATUS'] = 'ERROR';
		}

		$userSettings
			->setProgressData($progressData)
			->setMergeActivityDate(new \Bitrix\Main\Type\DateTime())
			->save();

		return $result;
	}

	public function setExecIntervalAction(int $interval)
	{
		$intervals = $this->getIntervals();
		if (!isset($intervals[$interval]))
		{
			$this->errorCollection->setError(new Error('Wrong interval value'));
			return [];
		}
		$userSettings = $this->getUserSettings();
		if ($userSettings === null)
		{
			return [];
		}
		$userSettings->setExecInterval($interval);
		if ($interval > 0)
		{
			$userSettings->setIsMergeEnabled(true);
			if ($userSettings->getStatusId() === AutoSearchUserSettings::STATUS_READY_TO_MERGE)
			{
				$userSettings->setStatusId(AutoSearchUserSettings::STATUS_MERGING);
				if (Loader::includeModule('pull'))
				{
					\Bitrix\Pull\Event::add($userSettings->getUserId(), [
						'module_id' => 'crm',
						'command' => 'dedupe.autosearch.startMerge',
						'params' => [
							'status' => 'MERGING',
							'entityTypeId' => $userSettings->getEntityTypeId()
						]
					]);
				}
			}
		}
		$userSettings->save();
		return [];
	}

	public function configureActions()
	{
		return [];
	}

	protected function listKeysSignedParameters()
	{
		return ['ENTITY_TYPE_ID'];
	}

	public function onPrepareComponentParams($arParams)
	{
		$this->errorCollection = new ErrorCollection();

		$arParams = parent::onPrepareComponentParams($arParams);
		$arParams['ENTITY_TYPE_ID'] = (int)($arParams['ENTITY_TYPE_ID'] ?? CCrmOwnerType::Undefined);
		$arParams['PATH_TO_MERGE'] = (string)($arParams['PATH_TO_MERGE'] ?? '');
		$arParams['PATH_TO_DEDUPELIST'] = (string)($arParams['PATH_TO_DEDUPELIST'] ?? '');
		return $arParams;
	}

	protected function isEntityTypeSupported(int $entityTypeId): bool
	{
		return (in_array($entityTypeId, [
			CCrmOwnerType::Lead,
			CCrmOwnerType::Contact,
			CCrmOwnerType::Company
		], true));
	}

	protected function getUserSettings(): ?AutoSearchUserSettings
	{
		if (!AutoSearchUserSettings::isEnabled())
		{
			return null;
		}

		$entityTypeId = $this->arParams['ENTITY_TYPE_ID'];
		if (!$this->isEntityTypeSupported($entityTypeId))
		{
			$this->errorCollection->setError(new Error('Wrong entity type'));
			return null;
		}
		if (!AutoSearchUserSettings::hasAccess($entityTypeId))
		{
			$this->errorCollection->setError(new Error('Access is denied'));
			return null;
		}

		return AutoSearchUserSettings::getForUserByEntityType($entityTypeId);
	}

	protected function getIntervals(): array
	{
		return [
			'1' => Loc::getMessage('CRM_DP_AUTOSEARCH_INTERVAL_1'),
			'7' => Loc::getMessage('CRM_DP_AUTOSEARCH_INTERVAL_7'),
			'14' => Loc::getMessage('CRM_DP_AUTOSEARCH_INTERVAL_14'),
			'30' => Loc::getMessage('CRM_DP_AUTOSEARCH_INTERVAL_30'),
			'182' => Loc::getMessage('CRM_DP_AUTOSEARCH_INTERVAL_182'),
			'0' => Loc::getMessage('CRM_DP_AUTOSEARCH_INTERVAL_0'),
		];
	}

	public function executeComponent()
	{
		$this->userSettings = $this->getUserSettings();
		if ($this->userSettings === null)
		{
			return;
		}
		if ($this->userSettings->getEntityTypeId() === CCrmOwnerType::Lead)
		{
			$this->userSettings->createDisabledIfNotExists();
		}
		else
		{
			$this->userSettings->createIfNotExists();
		}
		$intervals = $this->getIntervals();
		$this->arResult['INTERVALS'] = [];
		foreach ($intervals as $value => $title)
		{
			$this->arResult['INTERVALS'][] = [
				'value' => $value,
				'title' => $title
			];
		}
		$this->arResult['SELECTED_INTERVAL'] = (string)($this->userSettings->getExecInterval());
		$statusId = $this->userSettings->getStatusId();
		if ($statusId === AutoSearchUserSettings::STATUS_READY_TO_MERGE)
		{
			$progressData = $this->userSettings->getProgressData();
			$this->arResult['STATUS'] = 'READY_TO_MERGE';
			$this->arResult['PROGRESS_DATA'] = [
				'TOTAL_ENTITIES' => (int)$progressData['TOTAL_ENTITIES'],
				'FOUND_ITEMS' => (int)$progressData['FOUND_ITEMS'],
				'SHOW_NOTIFICATION' => $this->userSettings->canShowNotification()
			];
		}
		if ($statusId === AutoSearchUserSettings::STATUS_MERGING)
		{
			$this->arResult['STATUS'] = 'MERGING';
		}
		if ($statusId === AutoSearchUserSettings::STATUS_CONFLICTS_RESOLVING)
		{
			[$successCount, $conflictCount] = $this->getSuccessAndConflictCount($this->userSettings);

			if ($conflictCount == 0)
			{
				$this->userSettings
					->setStatusId(AutoSearchUserSettings::STATUS_NEW)
					->save();
			}
			else
			{
				$this->arResult['STATUS'] = 'CONFLICTS_RESOLVING';
				$this->arResult['PROGRESS_DATA'] = [
					'SUCCESS_COUNT' => $successCount,
					'CONFLICT_COUNT' => $conflictCount,
					'SHOW_NOTIFICATION' => $this->userSettings->canShowNotification()
				];
			}
		}

		$this->includeComponentTemplate();
	}

	public function getErrors()
	{
		return $this->errorCollection->toArray();
	}

	public function getErrorByCode($code)
	{
		return $this->errorCollection->getErrorByCode($code);
	}

	/**
	 * Conflicts stay in the index and would precede pending groups on every step, so the step
	 * resumes from the group taken last time. Inclusive: a group left pending comes back and
	 * PREV_IDS catches it. Before completing, one scan from the start picks up groups that became
	 * available behind the cursor (root reassigned, access granted).
	 */
	private function getNextMergeItem(Integrity\AutomaticDuplicateList $list, ?array $cursor): ?Integrity\Duplicate
	{
		if ($cursor !== null)
		{
			$list->setIndexOrderStart((int)$cursor['TYPE_ID'], (int)$cursor['ID']);
			$items = $list->getRootItems(0, 1);
			$list->resetIndexOrderStart();
			if (!empty($items))
			{
				return $items[0];
			}
		}

		return $list->getRootItems(0, 1)[0] ?? null;
	}

	protected function getSuccessAndConflictCount($userSettings, bool $forceRecalculate = false): array
	{
		$progressData = $userSettings->getProgressData();
		$successCount = (int)$progressData['MERGE_DATA']['SUCCESS'];
		$conflictCount = (int)$progressData['MERGE_DATA']['CONFLICT'];
		$errorCount = (int)$progressData['MERGE_DATA']['ERROR'];

		if ($conflictCount > 0 || $errorCount > 0)
		{
			$typeIds = $progressData['TYPE_IDS'];
			$scope = $progressData['CURRENT_SCOPE'];

			if(!empty($typeIds) && Integrity\DuplicateIndexType::checkScopeValue($scope))
			{
				$list = new Integrity\AutomaticDuplicateList(
					Integrity\DuplicateIndexType::joinType($typeIds),
					$this->arParams['ENTITY_TYPE_ID'],
					$userSettings->getUserId(),
					!\CCrmPerms::IsAdmin($userSettings->getUserId())
				);

				$list->setScope($scope);
				$list->setStatusIDs([
					Integrity\DuplicateStatus::CONFLICT,
					Integrity\DuplicateStatus::POSTPONED,
					Integrity\DuplicateStatus::ERROR
				]);

				// Runs on every CRM list page while conflicts are being resolved: the exact COUNT
				// is cached and dropped by the resolution paths (see AutoSearchConflictCountCache).
				$conflictCount = Integrity\AutoSearchConflictCountCache::get(
					(int)$userSettings->getEntityTypeId(),
					(int)$userSettings->getUserId(),
					static fn() => max(0, (int)$list->getRootItemCount()),
					$forceRecalculate
				);
			}
			else
			{
				$conflictCount = 0;
			}
		}
		return [$successCount, $conflictCount];
	}
}
