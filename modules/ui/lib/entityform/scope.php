<?php

namespace Bitrix\Ui\EntityForm;

use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Service\Container;
use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ORM\Data\DeleteResult;
use Bitrix\Main\ORM\Data\UpdateResult;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\Text\HtmlFilter;
use Bitrix\Main\UI\AccessRights\DataProvider;
use Bitrix\Socialnetwork\UserToGroupTable;
use Bitrix\Ui\EntityForm\Dto\EntityEditorConfigDto;
use Bitrix\UI\Form\EntityEditorConfigScope;
use CAccess;
use CUserOptions;

/**
 * Class Scope
 * @package Bitrix\Ui\EntityForm
 */
class Scope
{
	protected const CODE_USER = 'U';
	protected const CODE_PROJECT = 'SG';
	protected const CODE_DEPARTMENT   = 'DR';
	protected const CODE_STRUCTURE_DEPARTMENT = 'SNDR';

	protected const TYPE_USER = 'user';
	protected const TYPE_PROJECT = 'project';
	protected const TYPE_PROJECT_ACCESS_CODES = 'project-access-codes';
	protected const TYPE_SOCNETGROUPS = AccessCode::TYPE_SOCNETGROUP;
	protected const TYPE_DEPARTMENT = 'department';
	protected const TYPE_STRUCTURE_NODE = 'structure-node';
	protected const TYPE_ACCESS_USER = AccessCode::TYPE_USER;
	protected const TYPE_ACCESS_DEPARTMENT = AccessCode::TYPE_DEPARTMENT;
	protected const TYPE_ACCESS_STRUCTURE_DEPARTMENT = AccessCode::TYPE_STRUCTURE_DEPARTMENT;

	protected static array $instances = [];
	private static array $userScopeIdsCache = [];

	public function __construct(
		private readonly int $userId,
	)
	{
	}

	public static function getInstance(?int $userId = null): static
	{
		if ($userId === null || $userId <= 0)
		{
			$userId = (int)CurrentUser::get()->getId();
		}

		if (!isset(static::$instances[$userId]))
		{
			Loader::includeModule('ui');
			static::$instances[$userId] = new static($userId);
		}

		return static::$instances[$userId];
	}

	/**
	 * @param string $entityTypeId
	 * @param string|null $moduleId
	 * @return array
	 */
	public function getUserScopes(string $entityTypeId, ?string $moduleId = null, bool $loadMetadata = true): array
	{
		return $this->getScopes($entityTypeId, $moduleId, $loadMetadata);
	}

	public function getAllUserScopes(string $entityTypeId, ?string $moduleId = null, bool $loadMetadata = true): array
	{
		return $this->getScopes($entityTypeId, $moduleId, false, $loadMetadata);
	}

	/**
	 * Returns visible scopes keyed by ID, with access codes but without member metadata.
	 *
	 * @param string $entityTypeId
	 * @param string|null $moduleId
	 * @param bool $excludeEmptyAccessCode
	 * @param int $limit
	 * @param int $offset
	 * @param bool|null $onAdd
	 * @param bool|null $onUpdate
	 * @param bool $exactEntityTypeId
	 * @return array
	 */
	public function getUserScopesPage(
		string $entityTypeId,
		?string $moduleId,
		bool $excludeEmptyAccessCode,
		int $limit,
		int $offset,
		?bool $onAdd = null,
		?bool $onUpdate = null,
		bool $exactEntityTypeId = false,
	): array
	{
		$isAdminForEntity = $this->isAdminForEntity($entityTypeId, $moduleId);
		$filter = $exactEntityTypeId
			? ['=ENTITY_TYPE_ID' => $entityTypeId]
			: ['@ENTITY_TYPE_ID' => ($this->getEntityTypeIdMap()[$entityTypeId] ?? [$entityTypeId])]
		;
		if ($excludeEmptyAccessCode)
		{
			$filter['!=ACCESS_CODE'] = '';
		}
		if (!$isAdminForEntity)
		{
			$accessCodes = $this->getCurrentUserAccessCodes();
			$this->prepareAccessCodes($accessCodes);
			if ($accessCodes === [])
			{
				return [];
			}

			$filter['@ACCESS_CODE'] = $accessCodes;
		}

		if ($moduleId !== null)
		{
			$filter['=CATEGORY'] = $moduleId;
		}
		if ($onAdd !== null)
		{
			$filter[] = ['=ON_ADD' => $onAdd ? 'Y' : 'N'];
		}
		if ($onUpdate !== null)
		{
			$filter[] = ['=ON_UPDATE' => $onUpdate ? 'Y' : 'N'];
		}

		$scopeIds = EntityFormConfigTable::query()
			->setSelect(['ID'])
			->registerRuntimeField(new ExpressionField(
				'ACCESS_CODE',
				'%s',
				['\\Bitrix\\Ui\\EntityForm\\EntityFormConfigAcTable:CONFIG.ACCESS_CODE'],
			))
			->setFilter($filter)
			->setDistinct(true)
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->setOffset($offset)
			->fetchAll()
		;
		$scopeIds = array_column($scopeIds, 'ID');
		if (empty($scopeIds))
		{
			return [];
		}

		$scopes = EntityFormConfigTable::query()
			->setSelect([
				'ID',
				'NAME',
				'CONFIG',
				'CATEGORY',
				'AUTO_APPLY_SCOPE',
				'ON_ADD',
				'ON_UPDATE',
				'ENTITY_TYPE_ID',
			])
			->whereIn('ID', $scopeIds)
			->exec()
		;
		$scopesById = [];
		while ($scope = $scopes->fetch())
		{
			$scopesById[$scope['ID']] = [
				'NAME' => $scope['NAME'],
				'CONFIG' => $scope['CONFIG'],
				'CATEGORY' => $scope['CATEGORY'],
				'AUTO_APPLY_SCOPE' => $scope['AUTO_APPLY_SCOPE'],
				'ON_ADD' => $scope['ON_ADD'],
				'ON_UPDATE' => $scope['ON_UPDATE'],
				'ENTITY_TYPE_ID' => $scope['ENTITY_TYPE_ID'],
			];
		}

		$accessCodes = EntityFormConfigAcTable::query()
			->setSelect(['CONFIG_ID', 'ACCESS_CODE'])
			->whereIn('CONFIG_ID', $scopeIds)
			->exec()
		;
		while ($accessCode = $accessCodes->fetch())
		{
			$scopeId = $accessCode['CONFIG_ID'];
			if (isset($scopesById[$scopeId]))
			{
				$scopesById[$scopeId]['ACCESS_CODES'][$accessCode['ACCESS_CODE']] = $accessCode['ACCESS_CODE'];
			}
		}

		$result = [];
		foreach ($scopeIds as $scopeId)
		{
			if (isset($scopesById[$scopeId]))
			{
				$result[$scopeId] = $scopesById[$scopeId];
			}
		}

		return $result;
	}

	public function getUserScopeConfigs(string $entityTypeId, string $moduleId): EO_EntityFormConfig_Collection
	{
		$isAdminForEntity = $this->isAdminForEntity($entityTypeId, $moduleId);
		$filter = ScopeListFilter
			::getInstance($moduleId)
			->prepareFilter($entityTypeId, $isAdminForEntity, true, $this)
		;
		if (!$isAdminForEntity && empty($filter['@ID']))
		{
			return EntityFormConfigTable::createCollection();
		}

		return EntityFormConfigTable::query()
			->setSelect([
				'ID',
				'NAME',
				'CONFIG',
				'ACCESS_CODE' => '\Bitrix\Ui\EntityForm\EntityFormConfigAcTable:CONFIG.ACCESS_CODE',
			])
			->setFilter($filter)
			->fetchCollection()
		;
	}

	private function getScopes(
		string $entityTypeId,
		?string $moduleId = null,
		bool $excludeEmptyAccessCode = true,
		bool $loadMetadata = true,
	): array
	{
		static $results = [];
		$key = $entityTypeId . '-' . $moduleId . '-' . ($loadMetadata ? 'Y' : 'N');

		if (!isset($results[$key]))
		{
			$result = [];
			$isAdminForEntity = $this->isAdminForEntity($entityTypeId, $moduleId);

			$filter = ScopeListFilter::getInstance($moduleId)->prepareFilter($entityTypeId, $isAdminForEntity, $excludeEmptyAccessCode, $this);

			if ($isAdminForEntity || !empty($filter['@ID']))
			{
				$scopes = EntityFormConfigTable::getList([
					'select' => [
						'ID',
						'ENTITY_TYPE_ID',
						'NAME',
						'AUTO_APPLY_SCOPE',
						'ACCESS_CODE' => '\Bitrix\Ui\EntityForm\EntityFormConfigAcTable:CONFIG.ACCESS_CODE',
						'ON_ADD',
						'ON_UPDATE',
					],
					'filter' => $filter,
				]);

				foreach ($scopes as $scope)
				{
					$result[$scope['ID']]['NAME'] = HtmlFilter::encode($scope['NAME']);
					$result[$scope['ID']]['AUTO_APPLY_SCOPE'] = $scope['AUTO_APPLY_SCOPE'];
					$result[$scope['ID']]['ON_ADD'] = $scope['ON_ADD'];
					$result[$scope['ID']]['ON_UPDATE'] = $scope['ON_UPDATE'];
					$result[$scope['ID']]['ENTITY_TYPE_ID'] = $scope['ENTITY_TYPE_ID'];
					if ($loadMetadata)
					{
						$result[$scope['ID']]['MEMBERS'] ??= [];
						$result[$scope['ID']]['ACCESS_CODES'] ??= [];
					}
					if (
						$loadMetadata
						&& !isset($result[$scope['ID']]['ACCESS_CODES'][$scope['ACCESS_CODE']])
						&& isset($scope['ACCESS_CODE'])
						&& AccessCode::isValid((string)$scope['ACCESS_CODE'])
					)
					{
						$accessCode = new AccessCode($scope['ACCESS_CODE']);
						$member = (new DataProvider())->getEntity(
							$accessCode->getEntityType(),
							$accessCode->getEntityId(),
						);
						$metadata = $member->getMetaData();
						$metadata['accessCode'] = $scope['ACCESS_CODE'];

						$result[$scope['ID']]['ACCESS_CODES'][$scope['ACCESS_CODE']] = $scope['ACCESS_CODE'];
						$result[$scope['ID']]['MEMBERS'][$scope['ACCESS_CODE']] = $metadata;
					}
				}
			}

			$results[$key] = $result;
		}

		return $results[$key];
	}

	/**
	 * This method must return entityTypeId values that correspond to a single CRM entity only.
	 */
	public function getEntityTypeIdMap(): array
	{
		return [
			'lead_details' => ['lead_details', 'returning_lead_details'],
			'returning_lead_details' => ['lead_details', 'returning_lead_details'],
		];
	}

	/**
	 * @param int $scopeId
	 * @return bool
	 */
	public function isHasScope(int $scopeId): bool
	{
		return in_array($scopeId, $this->getScopesIdByUser());
	}

	protected function getUserId(): int
	{
		return $this->userId;
	}

	public function getScopesIdByUser(): array
	{
		if (isset(self::$userScopeIdsCache[$this->getUserId()]))
		{
			return self::$userScopeIdsCache[$this->getUserId()];
		}

		$accessCodes = $this->getCurrentUserAccessCodes();
		$this->prepareAccessCodes($accessCodes);

		$params = [
			'select' => [
				'CONFIG_ID',
			],
			'filter' => [
				'@ACCESS_CODE' => $accessCodes,
			],
		];

		$scopes = EntityFormConfigAcTable::getList($params)->fetchAll();
		$scopesIds = array_unique(array_column($scopes, 'CONFIG_ID'));

		self::$userScopeIdsCache[$this->getUserId()] = $scopesIds;

		return self::$userScopeIdsCache[$this->getUserId()];
	}

	protected function prepareAccessCodes(array &$accessCodes): void
	{
		$result = [];
		foreach ($accessCodes as $accessCode)
		{
			if (mb_strpos($accessCode, 'CHAT') === 0)
			{
				continue;
			}

			$matches = [];
			if (preg_match('/^(SG[1-9][0-9]*?)_([AEKM])$/D', $accessCode, $matches) === 1)
			{
				$result[] = $matches[1];
				if (in_array($matches[2], ['A', 'E', 'K'], true))
				{
					$result[] = $accessCode;
				}

				continue;
			}

			$result[] = $accessCode;
		}

		$accessCodes = array_values(array_unique($result));
	}

	/**
	 * @param int $scopeId
	 * @return array|null
	 */
	public function getScopeById(int $scopeId): ?array
	{
		if ($row = EntityFormConfigTable::getRowById($scopeId))
		{
			return is_array($row['CONFIG']) ? $row['CONFIG'] : null;
		}

		return null;
	}

	/**
	 * @param int $scopeId
	 * @return array|null
	 */
	public function getById(int $scopeId): ?array
	{
		return EntityFormConfigTable::getRowById($scopeId);
	}

	/**
	 * @param iterable $ids
	 * @throws \Exception
	 */
	public function removeByIds(iterable $ids): void
	{
		foreach ($ids as $id)
		{
			$this->removeById($id);
		}
	}

	/**
	 * @param int $id
	 * @return DeleteResult
	 */
	private function removeById(int $id): DeleteResult
	{
		$scopeObject = EntityFormConfigTable::getById($id)->fetchObject();
		if (!$scopeObject)
		{
			return (new DeleteResult())->addError(new Error('Configuration not found'));
		}

		$this->removeScopeMembers($id);

		$scopeName = $this->getScopeName(EntityEditorConfigDto::fromEntityFormConfig($scopeObject));

		$this->removeUsersScopeOptions($id, $scopeObject->getOptionCategory(), $scopeName);

		return EntityFormConfigTable::delete($id);
	}

	/**
	 * Set user option with config scope type and scopeId if selected custom scope
	 * @param string $categoryName
	 * @param string $guid
	 * @param string $configScopeType
	 * @param int $userScopeId
	 */
	public function setScope(string $categoryName, string $guid, string $configScopeType, int $userScopeId = 0, ?int $userId = null): void
	{
		$this->setScopeWithName($categoryName, $guid, $configScopeType, $userScopeId, $userId);
	}

	public function setCreateScope(string $moduleId, string $categoryName, string $guid, string $configScopeType, int $userScopeId = 0, ?int $userId = null): void
	{
		$scopeName = self::getScopeNameOnAdd($moduleId, $guid);

		$this->setScopeWithName($categoryName, $guid, $configScopeType, $userScopeId, $userId, $scopeName);
	}

	public function setEditScope(string $moduleId, string $categoryName, string $guid, string $configScopeType, int $userScopeId = 0, ?int $userId = null): void
	{
		$scopeName = self::getScopeNameOnUpdate($moduleId, $guid);

		$this->setScopeWithName($categoryName, $guid, $configScopeType, $userScopeId, $userId, $scopeName);
	}

	private function setScopeWithName(
		string $categoryName,
		string $guid,
		string $configScopeType,
		int $userScopeId = 0,
		?int $userId = null,
		?string $scopeName = null,
	): void
	{
		$scopeObject = EntityFormConfigTable::getById($userScopeId)->fetchObject();
		if (!$scopeObject && $configScopeType === EntityEditorConfigScope::CUSTOM)
		{
			return;
		}

		$this->setScopeToUser(
			new EntityEditorConfigDto(
				$categoryName,
				$guid,
				$configScopeType,
				$userScopeId,
				$userId,
				(bool)$scopeObject?->getOnAdd(),
				(bool)$scopeObject?->getOnUpdate(),
			),
			$scopeName,
		);
	}

	public function setScopeConfig(
		string $moduleId,
		string $entityTypeId,
		string $name,
		array $accessCodes,
		array $config,
		array $params = [],
		bool $useBatchAccessCodeWrite = false,
	) {
		$createdConfigId = null;

		return $this->setScopeConfigInternal(
			$moduleId,
			$entityTypeId,
			$name,
			$accessCodes,
			$config,
			$params,
			$useBatchAccessCodeWrite,
			$createdConfigId,
			false,
		);
	}

	public function setScopeConfigWithCreatedId(
		string $moduleId,
		string $entityTypeId,
		string $name,
		array $accessCodes,
		array $config,
		array $params,
		bool $useBatchAccessCodeWrite,
		?int &$createdConfigId,
	) {
		return $this->setScopeConfigInternal(
			$moduleId,
			$entityTypeId,
			$name,
			$accessCodes,
			$config,
			$params,
			$useBatchAccessCodeWrite,
			$createdConfigId,
			true,
		);
	}

	private function setScopeConfigInternal(
		string $moduleId,
		string $entityTypeId,
		string $name,
		array $accessCodes,
		array $config,
		array $params,
		bool $useBatchAccessCodeWrite,
		?int &$createdConfigId,
		bool $returnBatchAccessCodeErrors,
	) {
		$createdConfigId = null;

		if ($name === '')
		{
			$errors['name'] = new Error(Loc::getMessage('FIELD_REQUIRED'));
		}
		if (empty($accessCodes))
		{
			$errors['accessCodes'] = new Error(Loc::getMessage('FIELD_REQUIRED'));
		}
		if (empty($params['categoryName']))
		{
			$errors['categoryName'] = new Error(Loc::getMessage('FIELD_REQUIRED'));
		}
		if (!empty($errors))
		{
			return $errors;
		}

		$this->formatAccessCodes($accessCodes);

		$canUseOnAddOunUpdateSegregation = ScopeAccess::getInstance($moduleId)->canUseOnAddOnUpdateSegregation();

		$forceSetToUsers = ($params['forceSetToUsers'] ?? 'N') === 'Y';
		$availableOnAdd = (!$canUseOnAddOunUpdateSegregation || ($params['availableOnAdd'] ?? 'N') === 'Y');
		$availableOnUpdate = (!$canUseOnAddOunUpdateSegregation || ($params['availableOnUpdate'] ?? 'N') === 'Y');

		$result = EntityFormConfigTable::add([
			'CATEGORY' => $moduleId,
			'ENTITY_TYPE_ID' => $entityTypeId,
			'NAME' => $name,
			'CONFIG' => $config,
			'COMMON' => ($params['common'] ?? 'Y'),
			'AUTO_APPLY_SCOPE' => $forceSetToUsers,
			'OPTION_CATEGORY' => $params['categoryName'],
			'ON_ADD' => $availableOnAdd,
			'ON_UPDATE' => $availableOnUpdate,
		]);

		if ($result->isSuccess())
		{
			$configId = $result->getId();
			$createdConfigId = $configId;
			if ($useBatchAccessCodeWrite)
			{
				$accessCodeMap = [];
				foreach ($accessCodes as $ac)
				{
					$accessCodeMap[$ac['id']] = $ac['entityId'];
				}
				$accessCodeResult = $this->addAccessCodes($configId, $accessCodeMap);
				if ($returnBatchAccessCodeErrors && !$accessCodeResult->isSuccess())
				{
					return $accessCodeResult->getErrors();
				}
			}
			else
			{
				foreach ($accessCodes as $ac)
				{
					EntityFormConfigAcTable::add([
						'ACCESS_CODE' => $ac['id'],
						'CONFIG_ID' => $configId,
					]);
				}
			}

			if ($forceSetToUsers)
			{
				Application::getInstance()->addBackgroundJob(
					static fn() => Scope::getInstance()->forceSetScopeToUsers(
						new EntityEditorConfigDto(
							$params['categoryName'] ?? '',
							$entityTypeId,
							EntityEditorConfigScope::CUSTOM,
							$result->getId(),
							null,
							$availableOnAdd,
							$availableOnUpdate,
						),
						true,
						$accessCodes,
					),
				);
			}

			return $configId;
		}

		return $result->getErrors();
	}

	/**
	 * @param array $accessCodes
	 */
	protected function formatAccessCodes(array &$accessCodes): void
	{
		foreach ($accessCodes as $key => $item)
		{
			$accessCode = $this->normalizeAccessCodeItem($item);
			if ($accessCode === null)
			{
				unset($accessCodes[$key]);

				continue;
			}

			$accessCodes[$key]['id'] = $accessCode;
		}
	}

	private function normalizeAccessCodeItem(array $item): ?string
	{
		$entityId = $item['entityId'] ?? $item['type'] ?? null;
		$id = $item['id'] ?? null;
		if (!is_string($entityId) || (!is_int($id) && !is_string($id)))
		{
			return null;
		}

		$id = trim((string)$id);

		return match ($entityId)
		{
			self::TYPE_ACCESS_USER,
			self::TYPE_USER => $this->normalizeSimpleAccessCode(self::CODE_USER, $id),
			self::TYPE_ACCESS_DEPARTMENT,
			self::TYPE_DEPARTMENT => $this->normalizeDepartmentAccessCode($id, 'D', self::CODE_DEPARTMENT),
			self::TYPE_ACCESS_STRUCTURE_DEPARTMENT,
			self::TYPE_STRUCTURE_NODE => $this->normalizeDepartmentAccessCode($id, 'SND', self::CODE_STRUCTURE_DEPARTMENT),
			self::TYPE_PROJECT => $this->normalizeProjectAccessCode($id),
			self::TYPE_PROJECT_ACCESS_CODES,
			self::TYPE_SOCNETGROUPS => $this->normalizeProjectAccessCode($id, true),
			default => null,
		};
	}

	private function normalizeSimpleAccessCode(string $prefix, string $id): ?string
	{
		if (preg_match('/^' . preg_quote($prefix, '/') . '([1-9][0-9]*)$/D', $id) === 1)
		{
			return $id;
		}

		if (preg_match('/^[1-9][0-9]*$/D', $id) === 1)
		{
			return $prefix . $id;
		}

		return null;
	}

	private function normalizeDepartmentAccessCode(string $id, string $directPrefix, string $recursivePrefix): ?string
	{
		foreach ([$directPrefix, $recursivePrefix] as $prefix)
		{
			if (preg_match('/^' . preg_quote($prefix, '/') . '([1-9][0-9]*)$/D', $id) === 1)
			{
				return $id;
			}
		}

		$matches = [];
		if (preg_match('/^([1-9][0-9]*):F$/D', $id, $matches) === 1)
		{
			return $directPrefix . $matches[1];
		}

		if (preg_match('/^[1-9][0-9]*$/D', $id) === 1)
		{
			return $recursivePrefix . $id;
		}

		return null;
	}

	private function normalizeProjectAccessCode(string $id, bool $preserveRole = false): ?string
	{
		$matches = [];
		if (preg_match('/^' . self::CODE_PROJECT . '([1-9][0-9]*)(_[AEK])?$/D', $id, $matches) === 1)
		{
			return self::CODE_PROJECT . $matches[1] . ($preserveRole ? ($matches[2] ?? '') : '');
		}

		if (preg_match('/^[1-9][0-9]*$/D', $id) === 1)
		{
			return self::CODE_PROJECT . $id;
		}

		return null;
	}

	protected function forceSetScopeToUsers(EntityEditorConfigDto $config, bool $forceSetToUsers, array $accessCodes = []): void
	{
		if ($forceSetToUsers && $config->getCategoryName())
		{
			$codes = [];
			foreach ($accessCodes as $ac)
			{
				$codes[] = $ac['id'];
			}
			$this->setScopeByAccessCodes($config, $codes);
		}
	}

	protected function setScopeToUser(EntityEditorConfigDto $config, ?string $scopeName = null): void
	{
		$scope = $config->getConfigScopeType() !== null ? strtoupper($config->getConfigScopeType()) : EntityEditorConfigScope::UNDEFINED;

		if (!EntityEditorConfigScope::isDefined($scope))
		{
			return;
		}

		if ($scope === EntityEditorConfigScope::CUSTOM && $config->getUserScopeId())
		{
			$value = $config->getOptionValue();
		}
		else
		{
			$value = $scope;
		}

		$scopeNamesToApply = [];
		if ($scopeName)
		{
			$scopeNamesToApply[] = $scopeName;
		}
		else
		{
			if ($config->hasOnAdd())
			{
				$scopeNamesToApply[] = self::getScopeNameOnAdd($config->getModuleIdFromCategory(), $config->getEntityTypeId());
			}
			if ($config->hasOnUpdate())
			{
				$scopeNamesToApply[] = self::getScopeNameOnUpdate($config->getModuleIdFromCategory(), $config->getEntityTypeId());
			}
		}
		$scopeNamesToApply = array_unique($scopeNamesToApply);

		$userId = !is_null($config->getUserId()) ? $config->getUserId() : false;

		foreach ($scopeNamesToApply as $scopeNameToApply)
		{
			CUserOptions::SetOption($config->getCategoryName(), $scopeNameToApply, $value, false, $userId);
		}
	}

	public function updateScopeConfig(int $id, array $config)
	{
		return EntityFormConfigTable::update($id, [
			'CONFIG' => $config,
		]);
	}

	public function updateScope(int $scopeId, array $fields): UpdateResult
	{
		$scopeObject = EntityFormConfigTable::getById($scopeId)->fetchObject();
		if (!$scopeObject)
		{
			return (new UpdateResult())->addError(new Error('Configuration not found'));
		}

		$oldScopeName = $this->getScopeName(EntityEditorConfigDto::fromEntityFormConfig($scopeObject));

		$canUseOnAddOunUpdateSegregation = ScopeAccess::getInstance($scopeObject->getCategory())->canUseOnAddOnUpdateSegregation();
		if (isset($fields['ON_ADD']) && !$canUseOnAddOunUpdateSegregation)
		{
			$fields['ON_ADD'] = 'Y';
		}
		if (isset($fields['ON_UPDATE']) && !$canUseOnAddOunUpdateSegregation)
		{
			$fields['ON_UPDATE'] = 'Y';
		}

		$result =  EntityFormConfigTable::update($scopeId, $fields);

		$optionCategory = $scopeObject->getOptionCategory();
		$onAdd = array_key_exists('ON_ADD', $fields)
			? $fields['ON_ADD'] === 'Y'
			: $scopeObject->getOnAdd()
		;
		$onUpdate = array_key_exists('ON_UPDATE', $fields)
			? $fields['ON_UPDATE'] === 'Y'
			: $scopeObject->getOnUpdate()
		;
		$autoApplyScope = array_key_exists('AUTO_APPLY_SCOPE', $fields)
			? $fields['AUTO_APPLY_SCOPE'] === 'Y'
			: $scopeObject->getAutoApplyScope()
		;

		$newScopeName = $this->getScopeName(
			new EntityEditorConfigDto(
				$optionCategory,
				$scopeObject->getEntityTypeId(),
				EntityEditorConfigScope::CUSTOM,
				$scopeObject->getId(),
				null,
				$onAdd,
				$onUpdate,
			),
		);

		if ($autoApplyScope)
		{
			Application::getInstance()->addBackgroundJob(static function() use ($scopeId, $optionCategory, $oldScopeName, $newScopeName) {
				if ($oldScopeName !== $newScopeName)
				{
					Scope::getInstance()->removeUsersScopeOptions($scopeId, $optionCategory, $oldScopeName);
				}
				Scope::getInstance()->setScopeForEligibleUsers($scopeId);
			});
		}

		return $result;
	}

	public function updateScopeAccessCodes(int $configId, array $accessCodes = []): array
	{
		$scopeObject = EntityFormConfigTable::getById($configId)->fetchObject();

		if (!$scopeObject)
		{
			return [];
		}

		$accessCodes = $this->normalizeAccessCodeMap($accessCodes);
		if ($accessCodes === [])
		{
			return $this->getScopeMembers($configId);
		}

		$this->removeScopeMembers($configId);
		$this->addAccessCodes($configId, $accessCodes);

		$scopeMembers = $this->getScopeMembers($configId);
		if ($scopeObject->getAutoApplyScope())
		{
			Application::getInstance()->addBackgroundJob(
				static fn() => Scope::getInstance()->setScopeByAccessCodes(
					EntityEditorConfigDto::fromEntityFormConfig($scopeObject),
					array_keys($accessCodes),
				),
			);
		}

		return $scopeMembers;
	}

	private function normalizeAccessCodeMap(array $accessCodes): array
	{
		$result = [];
		foreach ($accessCodes as $accessCode => $item)
		{
			$normalizedAccessCode = $this->normalizeAccessCodeKey($accessCode);
			$normalizedAccessCode ??= is_array($item)
				? $this->normalizeAccessCodeItem($item)
				: $this->normalizeAccessCodeItem([
					'entityId' => $item,
					'id' => $accessCode,
				])
			;
			if ($normalizedAccessCode === null)
			{
				continue;
			}

			$result[$normalizedAccessCode] = (new AccessCode($normalizedAccessCode))->getEntityType();
		}

		return $result;
	}

	private function normalizeAccessCodeKey(mixed $accessCode): ?string
	{
		if (!is_string($accessCode))
		{
			return null;
		}

		$accessCode = trim($accessCode);
		if ($accessCode === '' || !$this->isSupportedAccessCode($accessCode))
		{
			return null;
		}

		return $accessCode;
	}

	private function isSupportedAccessCode(string $accessCode): bool
	{
		return preg_match('/^(?:(?:U|D|DR|SND|SNDR)[1-9][0-9]*|SG[1-9][0-9]*(?:_[AEK])?)$/D', $accessCode) === 1;
	}

	public function replaceScopeAccessCodes(int $configId, array $accessCodes = []): Result
	{
		if (!EntityFormConfigTable::getById($configId)->fetchObject())
		{
			return (new Result())->addError(new Error('Configuration not found'));
		}

		$this->removeScopeMembers($configId);

		return $this->addAccessCodes($configId, $accessCodes);
	}

	public function addAccessCodes(int $configId, array $accessCodes): Result
	{
		$accessCodeCollection = EntityFormConfigAcTable::createCollection();
		foreach ($accessCodes as $accessCode => $type)
		{
			$accessCodeItem = EntityFormConfigAcTable::createObject()
				->setAccessCode($accessCode)
				->setConfigId($configId)
			;

			$accessCodeCollection->add($accessCodeItem);
		}

		return $accessCodeCollection->save(true);
	}

	/**
	 * @param int $configId
	 * @return array
	 */
	public function getScopeMembers(int $configId): array
	{
		$accessCodes = EntityFormConfigAcTable::getList([
			'select' => ['ACCESS_CODE'],
			'filter' => ['=CONFIG_ID' => $configId],
		])->fetchAll();
		$result = [];
		if (count($accessCodes))
		{
			foreach ($accessCodes as $accessCodeEntity)
			{
				if (!AccessCode::isValid((string)$accessCodeEntity['ACCESS_CODE']))
				{
					continue;
				}

				$accessCode = new AccessCode($accessCodeEntity['ACCESS_CODE']);
				$member = (new DataProvider())->getEntity($accessCode->getEntityType(), $accessCode->getEntityId());
				$metadata = $member->getMetaData();
				$metadata['accessCode'] = $accessCodeEntity['ACCESS_CODE'];

				$result[$accessCodeEntity['ACCESS_CODE']] = $metadata;
			}
		}

		return $result;
	}

	/**
	 * @param int $configId
	 */
	private function removeScopeMembers(int $configId): void
	{
		$entity = EntityFormConfigAcTable::getEntity();
		$connection = $entity->getConnection();

		$filter = ['CONFIG_ID' => $configId];

		$connection->query(sprintf(
			'DELETE FROM %s WHERE %s',
			$connection->getSqlHelper()->quote($entity->getDBTableName()),
			Query::buildFilterSql($entity, $filter),
		));
	}

	private function setScopeToDepartment(EntityEditorConfigDto $config, int $departmentId, bool $withAllChildNodes = true): void
	{
		$userIds = $this->getUserIdsByDepartment($departmentId, $withAllChildNodes);
		foreach ($userIds as $userId)
		{
			$config->setUserId($userId);
			$this->setScopeToUser($config);
		}
	}

	private function setScopeToSocialGroup(
		EntityEditorConfigDto $config,
		int $socialGroupId,
		?string $accessRole = null,
	): void
	{
		$userIds = $this->getUserIdsBySocialGroup($socialGroupId, $accessRole);
		foreach ($userIds as $userId)
		{
			$config->setUserId($userId);
			$this->setScopeToUser($config);
		}
	}

	private function setScopeToStructureDepartment(EntityEditorConfigDto $config, mixed $structureDepartmentId, bool $withAllChildNodes = true): void
	{
		$userIds = $this->getUserIdsByStructureDepartment((int)$structureDepartmentId, $withAllChildNodes);
		foreach ($userIds as $userId)
		{
			$config->setUserId($userId);
			$this->setScopeToUser($config);
		}
	}

	public static function handleMemberAddedToDepartment(Event $event): void
	{
		Application::getInstance()->addBackgroundJob(static function() use ($event) {
			$member = $event->getParameter('member');

			$memberId = $member->entityId;
			$departmentId = $member->nodeId;
			$scopeType = EntityEditorConfigScope::CUSTOM;
			$scopes = Scope::getInstance()->getScopesByDepartment($departmentId, true);

			self::applyScopesToMember($scopes, $scopeType, $memberId);
		});
	}

	public static function handleMemberAddedToSocialGroup(int $id, array $fields): void
	{
		Application::getInstance()->addBackgroundJob(static function() use ($id, $fields) {
			if (!\Bitrix\Main\Loader::includeModule('socialnetwork'))
			{
				return;
			}

			$memberRole = is_string($fields['ROLE'] ?? null) ? $fields['ROLE'] : null;
			if ($memberRole === null)
			{
				return;
			}

			if (empty($fields['USER_ID']) || empty($fields['GROUP_ID']))
			{
				$userToGroup = UserToGroupTable::getById($id)->fetchObject();

				if (!$userToGroup)
				{
					return;
				}

				$memberId = empty($fields['USER_ID']) ? (int)$userToGroup->getUserId() : (int)$fields['USER_ID'];
				$socialGroupId = empty($fields['GROUP_ID']) ? (int)$userToGroup->getGroupId() : (int)$fields['GROUP_ID'];
			}
			else
			{
				$memberId = (int)$fields['USER_ID'];
				$socialGroupId = (int)$fields['GROUP_ID'];
			}

			if (!in_array($memberRole, UserToGroupTable::getRolesMember(), true))
			{
				return;
			}

			$scopeType = EntityEditorConfigScope::CUSTOM;
			$scopes = Scope::getInstance()->getScopesBySocialGroupId($socialGroupId, true, $memberRole);

			self::applyScopesToMember($scopes, $scopeType, $memberId);
		});
	}

	private function getScopesByDepartment(int $departmentId, bool $onlyAutoApplyView = false): array
	{
		$accessCodes = [];
		$nodeRepository = Container::getNodeRepository();
		$node = $nodeRepository->getById($departmentId);
		if (!$node)
		{
			return $accessCodes;
		}

		$parentNodes = $nodeRepository->getParentOf($node, DepthLevel::FULL);
		foreach ($parentNodes as $node)
		{
			$accessCode = str_replace('D', 'DR', $node->accessCode);
			$accessCodes = array_merge($accessCodes, $this->getScopesByAccessCode($accessCode, $onlyAutoApplyView));
		}

		return $accessCodes;
	}

	private function getScopesBySocialGroupId(
		int $socialGroupId,
		bool $onlyAutoApplyView = false,
		?string $memberRole = null,
	): array
	{
		$accessCodes = ['SG' . $socialGroupId];
		foreach ($this->getSocialGroupAccessRolesByMemberRole($memberRole) as $accessRole)
		{
			$accessCodes[] = 'SG' . $socialGroupId . '_' . $accessRole;
		}

		return $this->getScopesByAccessCodes($accessCodes, $onlyAutoApplyView);
	}

	private function getScopesByAccessCode(string $accessCode, bool $onlyAutoApplyView = false)
	{
		return $this->getScopesByAccessCodes([$accessCode], $onlyAutoApplyView);
	}

	private function getScopesByAccessCodes(array $accessCodes, bool $onlyAutoApplyView = false)
	{
		$filter = ['@ACCESS_CODE' => array_values(array_unique($accessCodes))];
		if ($onlyAutoApplyView)
		{
			$filter['=CONFIG.AUTO_APPLY_SCOPE'] = 'Y';
		}

		$scopes = EntityFormConfigAcTable::query()
			->setSelect(['ACCESS_CODE', 'CONFIG'])
			->setFilter($filter)
			->setOrder(['CONFIG.ID' => 'DESC'])
			->fetchCollection()
		;

		return $scopes->getConfigList();
	}

	private function getSocialGroupAccessRolesByMemberRole(?string $memberRole): array
	{
		return match ($memberRole)
		{
			UserToGroupTable::ROLE_OWNER => [
				UserToGroupTable::ROLE_OWNER,
				UserToGroupTable::ROLE_MODERATOR,
				UserToGroupTable::ROLE_USER,
			],
			UserToGroupTable::ROLE_MODERATOR => [
				UserToGroupTable::ROLE_MODERATOR,
				UserToGroupTable::ROLE_USER,
			],
			UserToGroupTable::ROLE_USER => [
				UserToGroupTable::ROLE_USER,
			],
			default => [],
		};
	}

	public function setScopeForEligibleUsers(int $scopeId): void
	{
		$scopeObject = EntityFormConfigTable::getById($scopeId)->fetchObject();

		if (!$scopeObject)
		{
			return;
		}

		$accessCodes = $this->getScopeAccessCodesByScopeId($scopeId);

		$this->setScopeByAccessCodes(EntityEditorConfigDto::fromEntityFormConfig($scopeObject), $accessCodes);
	}

	public function getScopeAccessCodesByScopeId(int $scopeId): array
	{
		$accessCodes = EntityFormConfigAcTable::query()
			->setSelect(['ACCESS_CODE'])
			->setFilter(['=CONFIG_ID' => $scopeId])
			->fetchCollection()
		;
		$result = [];
		foreach ($accessCodes as $code)
		{
			$result[] = $code->getAccessCode();
		}

		return $result;
	}

	public static function getScopeNameOnAdd(string $moduleId, string $entityTypeId): string
	{
		return self::getScopeNameWithSuffix($moduleId, $entityTypeId, '_on_add');
	}

	public static function getScopeNameOnUpdate(string $moduleId, string $entityTypeId): string
	{
		return self::getScopeNameWithSuffix($moduleId, $entityTypeId, '_on_update');
	}

	private static function getScopeNameWithSuffix(string $moduleId, string $entityTypeId, string $suffix): string
	{
		$scopeName = $entityTypeId . '_scope';

		try
		{
			if (!ScopeAccess::getInstance($moduleId)->canUseOnAddOnUpdateSegregation())
			{
				return $scopeName;
			}
		}
		catch (ObjectNotFoundException $e)
		{
			return $scopeName;
		}

		return $scopeName . $suffix;
	}

	private function setScopeByAccessCodes(EntityEditorConfigDto $config, array $accessCodes): void
	{
		foreach ($accessCodes as $accessCode)
		{
			$matches = [];
			if (preg_match('/'. AccessCode::AC_USER . '/', $accessCode, $matches))
			{
				$config->setUserId($matches[2]);
				$this->setScopeToUser($config);
			}
			elseif (preg_match('/'. AccessCode::AC_DEPARTMENT . '/', $accessCode, $matches))
			{
				$this->setScopeToDepartment($config, $matches[2], false);
			}
			elseif (preg_match('/'. AccessCode::AC_ALL_DEPARTMENT . '/', $accessCode, $matches))
			{
				$this->setScopeToDepartment($config, $matches[2]);
			}
			elseif (preg_match('/'. AccessCode::AC_SOCNETGROUP . '/', $accessCode, $matches))
			{
				$this->setScopeToSocialGroup($config, $matches[2], isset($matches[3]) ? trim($matches[3], '_') : null);
			}
			elseif (preg_match('/'. AccessCode::AC_ALL_STRUCTURE_DEPARTMENT . '/', $accessCode, $matches))
			{
				$this->setScopeToStructureDepartment($config, $matches[2]);
			}
			elseif (preg_match('/'. AccessCode::AC_STRUCTURE_DEPARTMENT . '/', $accessCode, $matches))
			{
				$this->setScopeToStructureDepartment($config, $matches[2], false);
			}
		}
	}

	private function getUserIdsBySocialGroup(int $socialGroupId, ?string $accessRole = null): array
	{
		if (!\Bitrix\Main\Loader::includeModule('socialnetwork'))
		{
			return [];
		}

		$roles = $this->getSocialGroupMemberRolesByAccessRole($accessRole);
		if ($roles === [])
		{
			return [];
		}

		$userCollection = UserToGroupTable::query()
			->setSelect(['USER_ID'])
			->setFilter([
				'=GROUP_ID' => $socialGroupId,
				'@ROLE' => $roles,
			])
			->fetchCollection()
		;

		$userIds = [];
		foreach ($userCollection as $user)
		{
			$userIds[] = $user->getUserId();
		}

		return $userIds;
	}

	private function getSocialGroupMemberRolesByAccessRole(?string $accessRole): array
	{
		return match ($accessRole)
		{
			UserToGroupTable::ROLE_OWNER => [
				UserToGroupTable::ROLE_OWNER,
			],
			UserToGroupTable::ROLE_MODERATOR => [
				UserToGroupTable::ROLE_OWNER,
				UserToGroupTable::ROLE_MODERATOR,
			],
			UserToGroupTable::ROLE_USER,
			null => [
				UserToGroupTable::ROLE_OWNER,
				UserToGroupTable::ROLE_MODERATOR,
				UserToGroupTable::ROLE_USER,
			],
			default => [],
		};
	}

	private function getUserIdsByDepartment(int $departmentId, bool $withAllChildNodes = true): array
	{
		return $this->getUserIdsByAccessCode($departmentId, 'D', $withAllChildNodes);
	}

	private function getUserIdsByStructureDepartment(int $departmentId, bool $withAllChildNodes = true): array
	{
		return $this->getUserIdsByAccessCode($departmentId, 'SND', $withAllChildNodes);
	}

	private function getUserIdsByAccessCode(int $departmentId, string $code, bool $withAllChildNodes = true): array
	{
		$userIds = [];
		if (!\Bitrix\Main\Loader::includeModule('humanresources') || !in_array($code, ['D', 'SND'], true))
		{
			return $userIds;
		}

		$hrServiceLocator = Container::instance();
		$accessCode = $code . $departmentId;
		$node = $hrServiceLocator::getNodeRepository()->getByAccessCode($accessCode);
		if (!$node)
		{
			return $userIds;
		}

		$allEmp = $hrServiceLocator::getNodeMemberService()->getAllEmployees($node->id, $withAllChildNodes);
		foreach ($allEmp->getIterator() as $emp)
		{
			$userIds[] = $emp->entityId;
		}

		return $userIds;
	}

	private function getCurrentUserAccessCodes(): array
	{
		return CAccess::GetUserCodesArray($this->userId);
	}

	private static function applyScopesToMember(array $scopes, string $scopeType, $memberId): void
	{
		$appliedEntities = [];
		/** @var EO_EntityFormConfig $scope */
		foreach ($scopes as $scope)
		{
			if (in_array($scope->getEntityTypeId(), $appliedEntities, true))
			{
				continue;
			}

			$appliedEntities[] = $scope->getEntityTypeId();
			self::getInstance()->setScopeToUser(
				new EntityEditorConfigDto(
					$scope->getOptionCategory(),
					$scope->getEntityTypeId(),
					$scopeType,
					$scope->getId(),
					$memberId,
					$scope->getOnAdd(),
					$scope->getOnUpdate(),
				),
			);
		}
	}

	private function getScopeName(EntityEditorConfigDto $config): string
	{
		$scopeName = "{$config->getEntityTypeId()}_scope";

		if ($config->hasOnAdd() && !$config->hasOnUpdate())
		{
			return self::getScopeNameOnAdd($config->getModuleIdFromCategory(), $config->getEntityTypeId());
		}

		if (!$config->hasOnAdd() && $config->hasOnUpdate())
		{
			return self::getScopeNameOnUpdate($config->getModuleIdFromCategory(), $config->getEntityTypeId());
		}

		if (!$config->hasOnAdd() && !$config->hasOnUpdate())
		{
			return "{$scopeName}_disabled";
		}

		return $scopeName;
	}

	private function removeUsersScopeOptions(int $configId, string $optionCategory, string $scopeName): void
	{
		$accessCodes = $this->getScopeAccessCodesByScopeId($configId);

		$userIds = [];
		foreach ($accessCodes as $accessCode)
		{
			$matches = [];
			if (preg_match('/'. AccessCode::AC_USER . '/', $accessCode, $matches))
			{
				$userIds[] = (int)$matches[2];
			}
			elseif (preg_match('/'. AccessCode::AC_DEPARTMENT . '/', $accessCode, $matches))
			{
				array_push($userIds, ...$this->getUserIdsByDepartment((int)$matches[2], false));
			}
			elseif (preg_match('/'. AccessCode::AC_ALL_DEPARTMENT . '/', $accessCode, $matches))
			{
				array_push($userIds, ...$this->getUserIdsByDepartment((int)$matches[2]));
			}
			elseif (preg_match('/'. AccessCode::AC_SOCNETGROUP . '/', $accessCode, $matches))
			{
				array_push($userIds, ...$this->getUserIdsBySocialGroup((int)$matches[2], isset($matches[3]) ? trim($matches[3], '_') : null));
			}
			elseif (preg_match('/'. AccessCode::AC_STRUCTURE_DEPARTMENT . '/', $accessCode, $matches))
			{
				array_push($userIds, ...$this->getUserIdsByStructureDepartment((int)$matches[2], false));
			}
			elseif (preg_match('/'. AccessCode::AC_ALL_STRUCTURE_DEPARTMENT . '/', $accessCode, $matches))
			{
				array_push($userIds, ...$this->getUserIdsByStructureDepartment((int)$matches[2]));
			}
		}

		$userIds = array_unique($userIds);

		foreach ($userIds as $userId)
		{
			CUserOptions::DeleteOption($optionCategory, $scopeName, false, $userId);
		}
	}

	public function copyScope(int $scopeId, string $entityTypeId): null|int|array
	{
		$scope = $this->getById($scopeId);
		if (!$scope)
		{
			return null;
		}

		$name = Loc::getMessage('UI_ENTITY_FORM_SCOPE_COPY', ['#NAME#' => $scope['NAME']]);

		$params = [
			'common' => $scope['COMMON'],
			'forceSetToUsers' => $scope['AUTO_APPLY_SCOPE'],
			'categoryName' => $scope['OPTION_CATEGORY'],
			'availableOnAdd' => $scope['ON_ADD'],
			'availableOnUpdate' => $scope['ON_UPDATE'],
		];

		$accessCodes = $this->getScopeAccessCodesByScopeId($scopeId);

		$newAccessCodes = [];
		foreach ($accessCodes as $code)
		{
			$accessCode = $this->unpackAccessCode($code);
			if ($accessCode !== null)
			{
				$newAccessCodes[] = $accessCode;
			}
		}

		return $this->setScopeConfig(
			$scope['CATEGORY'],
			$entityTypeId,
			$name,
			$newAccessCodes,
			$scope['CONFIG'],
			$params,
		);
	}

	private function unpackAccessCode(string $accessCode): ?array
	{
		if (str_starts_with($accessCode, self::CODE_USER))
		{
			return [
				'entityId' => self::TYPE_USER,
				'id' => str_replace(self::CODE_USER, '', $accessCode),
			];
		}
		if (preg_match('/^SG[1-9][0-9]*_[AEK]$/D', $accessCode) === 1)
		{
			return [
				'entityId' => self::TYPE_PROJECT_ACCESS_CODES,
				'id' => $accessCode,
			];
		}
		if (str_starts_with($accessCode, self::CODE_PROJECT))
		{
			return [
				'entityId' => self::TYPE_PROJECT,
				'id' => str_replace(self::CODE_PROJECT, '', $accessCode),
			];
		}
		$matches = [];
		if (preg_match('/^D([1-9][0-9]*)$/D', $accessCode, $matches) === 1)
		{
			return [
				'entityId' => self::TYPE_DEPARTMENT,
				'id' => $matches[1] . ':F',
			];
		}
		if (preg_match('/^DR([1-9][0-9]*)$/D', $accessCode, $matches) === 1)
		{
			return [
				'entityId' => self::TYPE_DEPARTMENT,
				'id' => $matches[1],
			];
		}
		if (preg_match('/^SND([1-9][0-9]*)$/D', $accessCode, $matches) === 1)
		{
			return [
				'entityId' => self::TYPE_STRUCTURE_NODE,
				'id' => $matches[1] . ':F',
			];
		}
		if (preg_match('/^SNDR([1-9][0-9]*)$/D', $accessCode, $matches) === 1)
		{
			return [
				'entityId' => self::TYPE_STRUCTURE_NODE,
				'id' => $matches[1],
			];
		}

		return null;
	}

	public function getUserScopesEntityEditor(string $entityTypeId, ?string $moduleId): array
	{
		$result = [];

		$isAdminForEntity = $this->isAdminForEntity($entityTypeId, $moduleId);

		$filter = ScopeListFilter::getInstance($moduleId)->prepareEntityEditorFilter($entityTypeId, $isAdminForEntity, $this);

		if (!$isAdminForEntity && empty($filter['@ID']))
		{
			return $result;
		}

		$scopes = EntityFormConfigTable::getList([
			'select' => [
				'ID',
				'ENTITY_TYPE_ID',
				'NAME',
				'ACCESS_CODE' => '\Bitrix\Ui\EntityForm\EntityFormConfigAcTable:CONFIG.ACCESS_CODE',
				'ON_ADD',
				'ON_UPDATE',
			],
			'filter' => $filter,
		]);

		foreach ($scopes as $scope)
		{
			$result[$scope['ID']] = [
				'NAME' => HtmlFilter::encode($scope['NAME']),
				'AUTO_APPLY_SCOPE' => $scope['AUTO_APPLY_SCOPE'] ?? null,
				'ON_ADD' => $scope['ON_ADD'],
				'ON_UPDATE' => $scope['ON_UPDATE'],
				'ENTITY_TYPE_ID' => $scope['ENTITY_TYPE_ID'],
			];
		}

		return $result;
	}

	private function isAdminForEntity(string $entityTypeId, ?string $moduleId = null): bool
	{
		return $moduleId
			&& (
				($scopeAccess = ScopeAccess::getInstance($moduleId, $this->userId))
				&& $scopeAccess->isAdminForEntityTypeId($entityTypeId)
			);
	}
}
