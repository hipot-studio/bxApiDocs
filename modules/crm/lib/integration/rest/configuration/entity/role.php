<?php

namespace Bitrix\Crm\Integration\Rest\Configuration\Entity;

use Bitrix\Crm\Category\PermissionEntityTypeHelper;
use Bitrix\Crm\Integration\Rest\Configuration\Helper;
use Bitrix\Crm\Security\Role\GroupCodeGenerator;
use Bitrix\Crm\Security\Role\Manage\DTO\EntityDTO;
use Bitrix\Crm\Security\Role\Manage\DTO\PermissionModel;
use Bitrix\Crm\Security\Role\Manage\Entity\AutomatedSolutionConfig;
use Bitrix\Crm\Security\Role\Manage\Enum\Permission;
use Bitrix\Crm\Security\Role\Manage\PermissionEntityBuilder;
use Bitrix\Crm\Security\Role\Model\RolePermissionTable;
use Bitrix\Crm\Security\Role\Model\RoleTable;
use Bitrix\Crm\Security\Role\Repositories\PermissionRepository;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\SystemException;
use Bitrix\Rest\Configuration\Manifest;
use CCrmOwnerType;
use CCrmStatus;
use Exception;

/**
 * Class WebForm
 * @package Bitrix\Crm\Integration\Rest\Configuration\Entity
 */
class Role
{
	const ENTITY_CODE = 'CRM_ROLE';
	const OWNER_ENTITY_TYPE_ROLE = 'ROLE';
	const OWNER_ENTITY_TYPE_PERM_PREFIX = 'PERM_';

	private static ?Role $instance = null;

	private array $accessManifest = [
		'crm',
		'automated_solution',
	];

	private static bool $isDynamicTypeChecked = false;
	private static bool $isDynamicType = false;
	private static int $oldDynamicTypeId = 0;
	private static int $oldDynamicCategoryId = 0;

	private static bool $isAutomatedSolutionChecked = false;
	private static bool $isAutomatedSolution = false;
	private static int $oldAutomatedSolutionId = 0;
	private static int $newAutomatedSolutionId = 0;

	protected static function checkAutomatedSolution(array $importParams, bool $refresh = false): void
	{
		if (!static::$isAutomatedSolutionChecked || $refresh)
		{
			if ($refresh)
			{
				static::$oldAutomatedSolutionId = 0;
				static::$newAutomatedSolutionId = 0;
				static::$isAutomatedSolution = false;
			}

			$data = $importParams['CONTENT']['DATA'];


			$oldAutomatedSolutionId = AutomatedSolutionConfig::decodeAutomatedSolutionId($data['code']);
			if (
				$oldAutomatedSolutionId > 0
				&& isset($importParams['RATIO']['AUTOMATED_SOLUTION']["AS$oldAutomatedSolutionId"])
			)
			{
				static::$oldAutomatedSolutionId = $oldAutomatedSolutionId;
				static::$newAutomatedSolutionId =
					(int)$importParams['RATIO']['AUTOMATED_SOLUTION']["AS$oldAutomatedSolutionId"]
				;
				static::$isAutomatedSolution = true;
			}


			static::$isAutomatedSolutionChecked = true;
		}
	}

	protected static function getOldAutomatedSolutionId(array $importParams): int
	{
		if (!static::$isAutomatedSolutionChecked)
		{
			static::checkAutomatedSolution($importParams);
		}

		return static::$oldAutomatedSolutionId;
	}

	protected static function getNewAutomatedSolutionId(array $importParams): int
	{
		if (!static::$isAutomatedSolutionChecked)
		{
			static::checkAutomatedSolution($importParams);
		}

		return static::$newAutomatedSolutionId;
	}

	protected static function isAutomatedSolution(array $importParams): bool
	{
		if (!static::$isAutomatedSolutionChecked)
		{
			static::checkAutomatedSolution($importParams);
		}

		return static::$isAutomatedSolution;
	}

	protected static function checkDynamicType(array $importParams, bool $refresh = false): void
	{
		if (!static::$isDynamicTypeChecked || $refresh)
		{
			if ($refresh)
			{
				static::$oldDynamicTypeId = 0;
				static::$isDynamicType = false;
			}

			$data = $importParams['CONTENT']['DATA'];

			$matches = [];
			if (
				isset($data['code'])
				&& is_string($data['code'])
				&& preg_match('/^DYNAMIC_(\\d+)_C(\\d+)$/u', $data['code'], $matches)
				&& isset($importParams['RATIO']['CRM_DYNAMIC_TYPES']["DYNAMIC_$matches[1]"])
			)
			{
				$index = CCrmStatus::getDynamicEntityStatusPrefix((int)$matches[1], (int)$matches[2]);
				if (isset($importParams['RATIO']['CRM_STATUS'][$index]))
				{
					$entityTypeId = (int)$importParams['RATIO']['CRM_DYNAMIC_TYPES']["DYNAMIC_$matches[1]"];
					if (CCrmOwnerType::isPossibleDynamicTypeId($entityTypeId))
					{
						static::$oldDynamicTypeId = (int)$matches[1];
						static::$oldDynamicCategoryId = (int)$matches[2];
						static::$isDynamicType = true;
					}
				}
			}

			static::$isDynamicTypeChecked = true;
		}
	}

	protected static function isDynamicType(array $importParams): bool
	{
		if (!static::$isDynamicTypeChecked)
		{
			static::checkDynamicType($importParams);
		}

		return static::$isDynamicType;
	}

	protected static function getOldDynamicTypeId(array $importParams): int
	{
		if (!static::$isDynamicTypeChecked)
		{
			static::checkDynamicType($importParams);
		}

		return static::$oldDynamicTypeId;
	}

	protected static function getOldDynamicCategoryId(array $importParams): int
	{
		if (!static::$isDynamicTypeChecked)
		{
			static::checkDynamicType($importParams);
		}

		return static::$oldDynamicCategoryId;
	}

	protected function checkRoleGroupCode(array $role,  $automatedSolutionModeParams): bool
	{
		if (array_key_exists('GROUP_CODE', $role) && ($role['GROUP_CODE'] === null || is_string($role['GROUP_CODE'])))
		{
			return (
				(
					!$automatedSolutionModeParams['isAutomatedSolutionMode']
					&& ($role['GROUP_CODE'] === null
						|| (
							$role['GROUP_CODE'] === GroupCodeGenerator::getCrmFormGroupCode()
							|| $role['GROUP_CODE'] === GroupCodeGenerator::getWidgetGroupCode()
						)
					)
				)
				|| (
					$automatedSolutionModeParams['isAutomatedSolutionMode']
					&& is_string($role['GROUP_CODE'])
					&& GroupCodeGenerator::getAutomatedSolutionIdFromGroupCode($role['GROUP_CODE']) > 0
				)
			);
		}

		return false;
	}

	public static function getInstance(): Role
	{
		if(self::$instance === null)
		{
			self::$instance = new static;
		}

		return self::$instance;
	}

	private function checkRights(array $params): bool
	{
		$helper = new Helper();
		$automatedSolutionModeParams = $helper->getAutomatedSolutionModeParams($params);
		if (
			$automatedSolutionModeParams['isAutomatedSolutionMode']
			|| Container::getInstance()->getUserPermissions()->isCrmAdmin()
		)
		{
			return true;
		}

		return false;
	}

	private function replaceInString(string $value, array $replacementConfig, array $replacement): string
	{
		if ($replacementConfig['replacementType'] === 'full')
		{
			if ($value === $replacement[0])
			{
				return $replacement[1];
			}
		}
		elseif ($replacementConfig['replacementType'] === 'prefix')
		{
			if (str_starts_with($value, $replacement[0]))
			{
				return $replacement[1] . substr($value, strlen($replacement[0]));
			}
		}

		return $value;
	}

	private function normalizeFieldsByConfig(array $fields, array $configs): array
	{
		$result = $fields;

		foreach ($configs as $fieldName => $config)
		{
			if (
				isset($result[$fieldName])
				&& isset($config['replacementType'])
				&& isset($config['replacementValues'])
				&& is_array($config['replacementValues'])
				&& !empty($config['replacementValues'])
			)
			{
				foreach ($config['replacementValues'] as $replacement)
				{
					if (
						count($replacement) === 2
						&& isset($replacement[0])
						&& is_string($replacement[0])
						&& $replacement[0] !== ''
						&& isset($replacement[1])
						&& is_string($replacement[1])
					)
					{
						$fieldType = $config['fieldType'] ?? '';
						if ($fieldType === 'string')
						{
							if (is_string($result[$fieldName]))
							{
								$result[$fieldName] = $this->replaceInString(
									$result[$fieldName],
									$config,
									$replacement
								);
							}
						}
						elseif ($fieldType === 'array')
						{
							if (is_array($result[$fieldName]))
							{
								foreach ($result[$fieldName] as $index => $value)
								{
									if (is_string($value))
									{
										$result[$fieldName][$index] = $this->replaceInString(
											$value,
											$config,
											$replacement
										);
									}
								}
							}
						}
					}
				}
			}
		}

		return $result;
	}

	private function normalizeRoleFields(
		array $roleFields,
		array $roleFieldReplacementConfig = []
	): array
	{
		return $this->normalizeFieldsByConfig($roleFields, $roleFieldReplacementConfig);
	}

	private function normalizePermissionFields(
		array $permissionFields,
		array $permissionFieldReplacementConfig = []
	): array
	{
		$result = $permissionFields;

		if (isset($result['SETTINGS']) && $result['SETTINGS'] === 'null')
		{
			$result['SETTINGS'] = [];
		}

		return $this->normalizeFieldsByConfig($result, $permissionFieldReplacementConfig);
	}

	private function getEntityList($params): array
	{
		$result = [];

		$dynamicTypesMap = Container::getInstance()->getDynamicTypesMap()->load([
			'isLoadCategories' => false,
			'isLoadStages' => false,
		]);

		$helper = new Helper();
		$automatedSolutionModeParams = $helper->getAutomatedSolutionModeParams($params);

		$dynamicEntityTypeIdsToExclude = [];
		foreach ($dynamicTypesMap->getTypes() as $type)
		{
			$entityTypeId = (int)$type->getEntityTypeId();
			if (
				!$helper->checkDynamicTypeExportConditions(
					array_merge(
						$automatedSolutionModeParams,
						$helper->getDynamicTypeCheckExportParamsByEntityTypeId($entityTypeId)
					)
				)
			)
			{
				$dynamicEntityTypeIdsToExclude[] = $entityTypeId;
			}
		}

		$permBuilder = new PermissionEntityBuilder();

		// Here you can filter the access rights that should or should not be exported or imported.

		/** @var  EntityDTO[] $perms */
		if ($automatedSolutionModeParams['isAutomatedSolutionMode'])
		{
			$perms =
				$permBuilder
					->include(Permission::Dynamic)
					->include(Permission::AutomatedSolutionConfig)
					->excludeEntityTypeIds(Permission::Dynamic, $dynamicEntityTypeIdsToExclude)
					->exclude(Permission::ContractorContact)
					->exclude(Permission::ContractorCompany)
					->exclude(Permission::ContractorConfig)
					->buildOfMade()
			;
		}
		else
		{
			$perms =
				(new PermissionEntityBuilder())
					->includeAll()
					->excludeEntityTypeIds(Permission::Dynamic, $dynamicEntityTypeIdsToExclude)
					->exclude(Permission::AutomatedSolutionConfig)
					->exclude(Permission::AutomatedSolutionList)
					->exclude(Permission::ContractorContact)
					->exclude(Permission::ContractorCompany)
					->exclude(Permission::ContractorConfig)
					->buildOfMade()
			;
		}

		foreach ($perms as $item)
		{
			$code = $item->code();

			// Skip suppliers and contacts of the suppliers
			// Skip all other automated solutuions on single automated solution mode
			$automatedSolutionId = AutomatedSolutionConfig::decodeAutomatedSolutionId($code);
			if (
				str_starts_with($code, 'CONTACT_')
				|| str_starts_with($code, 'COMPANY_')
				|| $automatedSolutionId > 0
				&& $automatedSolutionModeParams['isSingleAutomatedSolutionMode']
				&& $automatedSolutionModeParams['customSectionId'] !== $automatedSolutionId
			)
			{
				continue;
			}

			$result[] = $code;
		}

		return $result;
	}

	public function export($params): ?array
	{
		if (!$this->checkRights($params))
		{
			return null;
		}

		if (!Manifest::isEntityAvailable(static::ENTITY_CODE, $params, $this->accessManifest))
		{
			return null;
		}

		$helper = new Helper();
		if (!($helper->checkAutomatedSolutionModeExportParams($params)))
		{
			return null;
		}

		$step = false;
		if(array_key_exists('STEP', $params))
		{
			$step = $params['STEP'];
		}

		$result = [
			'FILE_NAME' => '',
			'CONTENT' => null,
			'NEXT' => $step,
		];

		$codeList = $this->getEntityList($params);

		if(isset($codeList[$step]))
		{
			if (is_string($codeList[$step]) && $codeList[$step] !== '')
			{
				$code = $codeList[$step];

				$result['FILE_NAME'] = $code;

				$perms = RolePermissionTable::getList(
					[
						'filter' => [
							'=ENTITY' => $code,
							'!=ROLE.IS_SYSTEM' => 'Y',
						],
					]
				)->fetchAll();

				if (!is_array($perms) || empty($perms))
				{
					return $result;
				}

				$roleIds = [];
				$suitableGroupCodeMap = [];
				$permissionRepository = PermissionRepository::getInstance();
				$automatedSolutionModeParams = $helper->getAutomatedSolutionModeParams($params);
				foreach ($perms as $perm)
				{
					if (is_array($perm) && isset($perm['ROLE_ID']) && $perm['ROLE_ID'] > 0)
					{
						$roleId = (int)$perm['ROLE_ID'];
						if (!isset($suitableGroupCodeMap[$roleId]))
						{
							$role = $permissionRepository->getRole($roleId);
							$suitableGroupCodeMap[$roleId] =
								(
									is_array($role)
									&& $this->checkRoleGroupCode(
										$role,
										$automatedSolutionModeParams
									)
								)
							;
						}
						if ($suitableGroupCodeMap[$roleId])
						{
							$roleIds[$roleId] = true;
						}
					}
				}

				$filteredPerms = [];
				while ($perm = array_shift($perms))
				{
					$roleId = (int)$perm['ROLE_ID'];
					if ($roleIds[$roleId] ?? false)
					{
						$filteredPerms[] = $perm;
					}
				}
				unset($perm);

				$roleIds = array_keys($roleIds);

				if (empty($roleIds))
				{
					return $result;
				}

				$roles = RoleTable::getList(
					[
						'filter' => [
							'@ID' => $roleIds,
						],
					]
				)->fetchAll();

				if (!is_array($roles))
				{
					return $result;
				}

				$result['CONTENT'] = [
					'code' => $code,
					'perms' => $filteredPerms,
					'roles' => $roles,
				];
			}
		}
		else
		{
			$result['NEXT'] = false;
		}

		return $result;
	}

	public function clear(array $params): ?array
	{
		if (!$this->checkRights($params))
		{
			return null;
		}

		if (!Manifest::isEntityAvailable(static::ENTITY_CODE, $params, $this->accessManifest))
		{
			return null;
		}

		$isRoleEnabled = $params['IMPORT_MANIFEST']['METADATA']['crm']['enableRole'] ?? false;
		if (!$isRoleEnabled)
		{
			return null;
		}

		$helper = new Helper();
		if (!$helper->checkAutomatedSolutionModeClearParams($params))
		{
			return null;
		}

		if (!$params['CLEAR_FULL'])
		{
			return null;
		}

		$helper = new Helper();
		$automatedSolutionModeParams = $helper->getAutomatedSolutionModeParams($params);
		if ($automatedSolutionModeParams['isAutomatedSolutionMode'])
		{
			return null;
		}

		$result = [
			'NEXT' => false,
			'OWNER_DELETE' => [],
		];

		$helper = new Helper();
		$automatedSolutionModeParams = $helper->getAutomatedSolutionModeParams($params);
		if ($automatedSolutionModeParams['isAutomatedSolutionMode'])
		{
			return null;
		}

		$step = false;
		if(array_key_exists('STEP', $params))
		{
			$step = $params['STEP'];
		}

		if ($step === 0)
		{
			$result['ADDITIONAL_OPTION']['METADATA']['crm']['isClearFull'] = true;
		}

		$res = RoleTable::query()
			->setSelect(['ID'])
			->whereNot('IS_SYSTEM', 'Y')
			->where(
				(new ConditionTree())
					->logic(ConditionTree::LOGIC_OR)
					->whereNull('GROUP_CODE')
					->whereIn(
						'GROUP_CODE',
						[
							GroupCodeGenerator::getCrmFormGroupCode(),
							GroupCodeGenerator::getWidgetGroupCode(),
						]
					)
			)
			->setLimit(100)
			->exec()
		;
		$roleIds = [];
		while ($row = $res->fetch())
		{
			$roleIds[] = (int)$row['ID'];
		}
		if (!empty($roleIds))
		{
			try
			{
				$result['NEXT'] = $step;
				$permissionRepository = PermissionRepository::getInstance();
				foreach ($roleIds as $roleId)
				{
					$deleteResult = $permissionRepository->deleteRole($roleId);
					if (!$deleteResult->isSuccess())
					{
						throw new SystemException(
							Loc::getMessage(
								'CRM_ERROR_CONFIGURATION_CLEAR_EXCEPTION_ROLE_DEL',
								['#ID#' => $roleId]
							)
						);
					}
					$result['OWNER_DELETE'][] = [
						'ENTITY_TYPE' => self::OWNER_ENTITY_TYPE_ROLE,
						'ENTITY' => $roleId,
					];
				}
			}
			catch (Exception $e)
			{
				$result['NEXT'] = false;
				$result['ERROR_EXCEPTION'] = $e->getMessage();
				$result['ERROR_ACTION'] = 'DELETE_ERROR_ROLE';
			}
		}

		return $result;
	}

	public function import(array $params): ?array
	{
		$result = [];

		if (!$this->checkRights($params))
		{
			return $result;
		}

		if (!Manifest::isEntityAvailable(static::ENTITY_CODE, $params, $this->accessManifest))
		{
			return $result;
		}

		$helper = new Helper();
		$automatedSolutionModeParams = $helper->getAutomatedSolutionModeImportParams($params);

		$isAutomatedSolutionMode = $automatedSolutionModeParams['isAutomatedSolutionMode'];
		$isRoleEnabled = $params['IMPORT_MANIFEST']['METADATA']['crm']['enableRole'] ?? false;
		$isClearFull = $params['ADDITIONAL_OPTION']['METADATA']['crm']['isClearFull'] ?? false;
		if (!$isRoleEnabled || !$isClearFull && !$isAutomatedSolutionMode)
		{
			return $result;
		}

		if (!$helper->checkAutomatedSolutionModeImportParams($params))
		{
			return $result;
		}

		if(!isset($params['CONTENT']['DATA']))
		{
			return $result;
		}

		$data = $params['CONTENT']['DATA'];

		if (!isset($data['code']) || !is_string($data['code']))
		{
			return $result;
		}

		$isDynamicType = static::isDynamicType($params);

		$isAutomatedSolution = static::isAutomatedSolution($params);

		if ($isAutomatedSolutionMode && !$isDynamicType && !$isAutomatedSolution)
		{
			return $result;
		}

		$code = $data['code'];

		$permissionFieldReplacementConfig = [
			'ENTITY' => [
				'fieldType' => 'string',
				'replacementType' => 'full',
				'replacementValues' => [],
			],
			'FIELD_VALUE' => [
				'fieldType' => 'string',
				'replacementType' => 'prefix',
				'replacementValues' => [],
			],
			'SETTINGS' => [
				'fieldType' => 'array',
				'replacementType' => 'prefix',
				'replacementValues' => [],
			],
		];

		$needCheckByCodeMap = true;
		if ($isAutomatedSolution)
		{
			$oldAutomatedSolutionId = static::getOldAutomatedSolutionId($params);
			$newAutomatedSolutionId = static::getNewAutomatedSolutionId($params);
			$code = AutomatedSolutionConfig::generateEntity($newAutomatedSolutionId);
			$permissionFieldReplacementConfig['ENTITY']['replacementValues'][] = [
				AutomatedSolutionConfig::generateEntity($oldAutomatedSolutionId),
				$code,
			];
			$needCheckByCodeMap = false;
		}
		elseif ($isDynamicType)
		{
			if (!is_array($params['RATIO']))
			{
				return $result;
			}

			$helper = new Helper();
			$oldDynamicEntityTypeId = static::getOldDynamicTypeId($params);
			$oldDynamicTypeCategoryId = static::getOldDynamicCategoryId($params);
			$dynamicEntityTypeId = $helper->getDynamicEntityTypeIdByOldEntityTypeId(
				$oldDynamicEntityTypeId,
				$params['RATIO']
			);
			$dynamicTypeCategoryId = $helper->getNewDynamicTypeCategoryIdByRatio(
				$oldDynamicEntityTypeId,
				$oldDynamicTypeCategoryId,
				$params['RATIO']
			);

			if ($dynamicEntityTypeId <= 0 || $dynamicTypeCategoryId <= 0)
			{
				return $result;
			}

			$oldPermissionEntityTypeHelper = new PermissionEntityTypeHelper($oldDynamicEntityTypeId);
			$newPermissionEntityTypeHelper = new PermissionEntityTypeHelper($dynamicEntityTypeId);
			$permissionFieldReplacementConfig['ENTITY']['replacementValues'][] = [
				$oldPermissionEntityTypeHelper->getPermissionEntityTypeForCategory($oldDynamicTypeCategoryId),
				$newPermissionEntityTypeHelper->getPermissionEntityTypeForCategory($dynamicTypeCategoryId),
			];
			$prefixReplacement = [
				CCrmStatus::getDynamicEntityStatusPrefix($oldDynamicEntityTypeId, $oldDynamicTypeCategoryId) . ':',
				CCrmStatus::getDynamicEntityStatusPrefix($dynamicEntityTypeId, $dynamicTypeCategoryId) . ':',
			];
			$permissionFieldReplacementConfig['FIELD_VALUE']['replacementValues'][] = $prefixReplacement;
			$permissionFieldReplacementConfig['SETTINGS']['replacementValues'][] = $prefixReplacement;
			$needCheckByCodeMap = false;
		}
		else
		{
			$matches = [];
			if (preg_match('/^DEAL_C(\\d+)$/u', $data['code'], $matches))
			{
				if (!isset($params['RATIO']['CRM_STATUS'][(int)$matches[1]]))
				{
					return $result;
				}

				$oldDealCategoryId = (int)$matches[1];
				$dealCategoryId = (int)$params['RATIO']['CRM_STATUS'][$oldDealCategoryId];
				if ($dealCategoryId <= 0)
				{
					return $result;
				}

				$permissionEntityTypeHelper = (new PermissionEntityTypeHelper(CCrmOwnerType::Deal));
				$permissionFieldReplacementConfig['ENTITY']['replacementValues'][] = [
					$permissionEntityTypeHelper->getPermissionEntityTypeForCategory($oldDealCategoryId),
					$permissionEntityTypeHelper->getPermissionEntityTypeForCategory($dealCategoryId),
				];
				$prefixReplacement = [
					"C$oldDealCategoryId:",
					"C$dealCategoryId:",
				];
				$permissionFieldReplacementConfig['FIELD_VALUE']['replacementValues'][] = $prefixReplacement;
				$permissionFieldReplacementConfig['SETTINGS']['replacementValues'][] = $prefixReplacement;
				$needCheckByCodeMap = false;
			}
			elseif (preg_match('/^SMART_INVOICE_C(\\d+)$/u', $data['code'], $matches))
			{
				$entityTypeId = CCrmOwnerType::SmartInvoice;
				$oldCategoryId = (int)$matches[1];

				$factory = Container::getInstance()->getFactory($entityTypeId);
				if (!$factory)
				{
					return $result;
				}

				$newCategoryId = $factory->createDefaultCategoryIfNotExist()->getId();
				if ($newCategoryId < 0)
				{
					return $result;
				}

				$permissionEntityTypeHelper = new PermissionEntityTypeHelper($entityTypeId);
				$permissionFieldReplacementConfig['ENTITY']['replacementValues'][] = [
					$permissionEntityTypeHelper->getPermissionEntityTypeForCategory($oldCategoryId),
					$permissionEntityTypeHelper->getPermissionEntityTypeForCategory($newCategoryId),
				];

				$prefixReplacement = [
					CCrmStatus::getDynamicEntityStatusPrefix($entityTypeId, $oldCategoryId) . ':',
					CCrmStatus::getDynamicEntityStatusPrefix($entityTypeId, $newCategoryId) . ':',
				];

				$permissionFieldReplacementConfig['FIELD_VALUE']['replacementValues'][] = $prefixReplacement;
				$permissionFieldReplacementConfig['SETTINGS']['replacementValues'][] = $prefixReplacement;

				$needCheckByCodeMap = false;
				unset($entityTypeId);
			}
		}

		if ($needCheckByCodeMap)
		{
			// $params must contain information as for export in order
			// to correctly process the automated solution import flag
			$codeMap = array_fill_keys($this->getEntityList(['MANIFEST' => ['CODE' => $params['MANIFEST_CODE']]]), true);

			if (!isset($codeMap[$code]))
			{
				return $result;
			}
		}

		$permissionRepository = PermissionRepository::getInstance();
		if (isset($data['roles']) && is_array($data['roles']) && !empty($data['roles']))
		{
			foreach ($data['roles'] as $role)
			{
				if (isset($role['IS_SYSTEM']) && $role['IS_SYSTEM'] !== 'N')
				{
					continue;
				}

				if (is_array($role) && isset($role['ID']))
				{
					$roleId = (int)$role['ID'];
					if ($roleId > 0)
					{
						$isChecked = isset($params['RATIO'][static::ENTITY_CODE][$roleId]);
						if (!$isChecked)
						{
							$oldAutomatedSolutionId = null;

							$isGroupCodeFilled = (
								isset($role['GROUP_CODE'])
								&& is_string($role['GROUP_CODE'])
								&& $role['GROUP_CODE'] !== ''
							);

							if ($isGroupCodeFilled)
							{
								$oldAutomatedSolutionId =
									GroupCodeGenerator::getAutomatedSolutionIdFromGroupCode($role['GROUP_CODE'])
								;
							}

							if (
								$oldAutomatedSolutionId > 0
								&& isset($params['RATIO']['AUTOMATED_SOLUTION']["AS$oldAutomatedSolutionId"])
							)
							{
								$newAutomatedSolutionId =
									(int)$params['RATIO']['AUTOMATED_SOLUTION']["AS$oldAutomatedSolutionId"]
								;
								$role = $this->normalizeRoleFields(
									$role,
									[
										'GROUP_CODE' => [
											'fieldType' => 'string',
											'replacementType' => 'full',
											'replacementValues' => [
												[
													"AUTOMATED_SOLUTION_$oldAutomatedSolutionId",
													"AUTOMATED_SOLUTION_$newAutomatedSolutionId",
												],
											],
										],
									]
								);
							}

							// add
							$touchedRoleId = $permissionRepository->updateOrCreateRole(
								0,
								$role['NAME'],
								$isGroupCodeFilled ? $role['GROUP_CODE'] : null
							);

							if (!isset($result['OWNER']) || !is_array($result['OWNER']))
							{
								$result['OWNER'] = [];
							}
							$result['OWNER'][] = [
								'ENTITY_TYPE' => static::OWNER_ENTITY_TYPE_ROLE,
								'ENTITY' => $touchedRoleId,
							];

							$result['RATIO'][$roleId] = $touchedRoleId;
						}
					}
				}
			}
		}

		$groupedPermDto = [];
		foreach ($data['perms'] as $index => $perm)
		{
			if (isset($perm['ROLE_ID']) && $perm['ROLE_ID'] > 0)
			{
				$oldRoleId = (int)$perm['ROLE_ID'];
				$roleId = 0;
				if (
					isset($params['RATIO']['CRM_ROLE'][$oldRoleId])
					&& $params['RATIO']['CRM_ROLE'][$oldRoleId] > 0
				)
				{
					$roleId = (int)$params['RATIO']['CRM_ROLE'][$oldRoleId];
				}
				elseif (
					isset($result['RATIO'][$oldRoleId])
					&& $result['RATIO'][$oldRoleId] > 0
				)
				{
					$roleId = (int)$result['RATIO'][$oldRoleId];
				}
				if ($roleId > 0)
				{
					$groupedPermDto[$roleId][] = PermissionModel::createFromDbArray(
						$this->normalizePermissionFields($perm, $permissionFieldReplacementConfig)
					);
				}
			}
			unset($data['perms'][$index]);
		}
		unset($perm, $data['perms']);

		/** @var PermissionModel[] $perms */
		foreach ($groupedPermDto as $roleId => $perms)
		{
			$applyPermsResult = $permissionRepository->applyRolePermissionData($roleId, [], $perms);
			if ($applyPermsResult->isSuccess())
			{
				if (!isset($result['OWNER']) || !is_array($result['OWNER']))
				{
					$result['OWNER'] = [];
				}
				$result['OWNER'][] = [
					'ENTITY_TYPE' => static::OWNER_ENTITY_TYPE_PERM_PREFIX . $code,
					'ENTITY' => $roleId,
				];
			}
			else
			{
				$result['ERROR_MESSAGES'] = $applyPermsResult->getErrorMessages();
			}
		}

		return $result;
	}
}
