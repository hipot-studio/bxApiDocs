<?php

namespace Bitrix\Crm\Entity;

use Bitrix\Crm\Service\Context;
use Bitrix\Crm\UserField\Visibility\VisibilityManager;
use Bitrix\Crm\V2\Internal\Service\Ai\FieldFillPolicyService;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\UserField\Types\EnumType;
use CCrmOwnerType;
use CCrmUserType;
use CUserFieldEnum;

final class FieldDataProvider
{
	private const FIELD_DATA_CACHE_TTL = 86400;
	private const FIELD_DATA_CACHE_ID = 'crm_entity_field_data';
	private const FIELD_DATA_CACHE_PATH = 'crm';

	private int $entityTypeId;
	private ?string $scope;
	private ?CCrmUserType $crmUserType;
	private ?Cache $cache;

	public function __construct(int $entityTypeId, ?string $scope = null)
	{
		$this->entityTypeId = $entityTypeId;
		$this->scope = $scope;

		$this->initCrmUserType();
		$this->cache = Application::getInstance()->getCache();
	}

	public function getFieldData(): array
	{
		$fieldData = null;
		if (
			$this->cache->initCache(
				self::FIELD_DATA_CACHE_TTL,
				$this->getCacheId(),
				self::FIELD_DATA_CACHE_PATH
			)
		)
		{
			$cacheVars = $this->cache->getVars();
			if (isset($cacheVars['fieldData']) && is_array($cacheVars['fieldData']))
			{
				$fieldData = $cacheVars['fieldData'];
			}
		}

		if (!is_array($fieldData))
		{
			$this->cache->startDataCache();
			$fieldData = $this->fetchFieldData();
			$this->cache->endDataCache(['fieldData' => $fieldData]);
		}

		$supportedUserFieldTypes = $this->getSupportedUserFieldTypes();
		if (!is_array($supportedUserFieldTypes))
		{
			return $fieldData;
		}

		return array_filter(
			$fieldData,
			static fn(array $data) => in_array($data['TYPE'], $supportedUserFieldTypes, true)
		);
	}

	/**
	 * Returns array with description of fields that BitrixGPT is allowed to fill. Unlike getFieldData()
	 * the policy of the field is applied, so an excluded field is absent both in the schema sent to AI
	 * and in the parsing of its answer
	 */
	public function getEnabledForAiFillFieldData(): array
	{
		return $this->removeDisabledForAiFill($this->getFieldData());
	}

	/**
	 * Returns array with description of fields that are accessible (visible) for the given user
	 */
	public function getAccessibleByUserFieldData(int $userId): array
	{
		$notAccessible = VisibilityManager::getNotAccessibleFields(
			$this->entityTypeId,
			VisibilityManager::getUserAccessCodes($userId),
		);

		return array_filter(
			$this->getFieldData(),
			static fn(array $data) => !in_array($data['ID'], $notAccessible, true)
		);
	}

	/**
	 * Returns array with description of fields that are displayed in user's entity editor - user has access to them,
	 * they are always displayed (not hidden)
	 */
	public function getDisplayedInEntityEditorFieldData(int $userId, ?int $categoryId = null): array
	{
		$config = EntityEditorConfig::createWithCurrentScope(
			$this->entityTypeId,
			[
				'USER_ID' => $userId,
				'CATEGORY_ID' => $categoryId,
				'DEAL_CATEGORY_ID' => $categoryId,
			],
		);

		$displayedFieldData = array_filter(
			$this->getAccessibleByUserFieldData($userId),
			static fn(array $field) => $config->isFormFieldVisible($field['ID']),
		);

		return $this->removeDisabledForAiFill($displayedFieldData);
	}

	public function invalidateFieldDataCache(): void
	{
		$this->cache->clean(
			$this->getCacheId(),
			self::FIELD_DATA_CACHE_PATH
		);
	}

	private function initCrmUserType(): void
	{
		$userFieldEntityId = CCrmOwnerType::ResolveUserFieldEntityID($this->entityTypeId);
		if (empty($userFieldEntityId))
		{
			return;
		}

		global $USER_FIELD_MANAGER;
		$this->crmUserType = new CCrmUserType($USER_FIELD_MANAGER, $userFieldEntityId);
	}

	private function fetchFieldData(): array
	{
		if (!isset($this->crmUserType))
		{
			return [];
		}

		$userFieldsRaw = $this->crmUserType->GetEntityFields(0);
		if (empty($userFieldsRaw))
		{
			return [];
		}

		$result = [];
		foreach ($userFieldsRaw as $item)
		{
			$row = [
				'ID' => $item['FIELD_NAME'],
				'NAME' => $item['EDIT_FORM_LABEL'] ?? $item['USER_TYPE']['DESCRIPTION'],
				'TYPE' => $item['USER_TYPE_ID'],
				'MULTIPLE' => isset($item['MULTIPLE']) && $item['MULTIPLE'] === 'Y',
			];

			if ($item['USER_TYPE_ID'] === EnumType::USER_TYPE_ID)
			{
				$enumDbResult = CUserFieldEnum::GetList([], ['USER_FIELD_ID' => $item['ID']]);
				$enumResults = [];
				while ($enumFields = $enumDbResult->Fetch())
				{
					$enumResults[$enumFields['ID']] = $enumFields['VALUE'];
				}

				$row['VALUES'] = $enumResults;
			}

			$result[$row['ID']] = $row;
		}

		return $result;
	}

	/**
	 * Applied over the field data instead of inside it: the daily cache above is not dropped when the
	 * setting of a field is switched, while the policy keeps a fresh cache of its own.
	 */
	private function removeDisabledForAiFill(array $fieldData): array
	{
		if ($this->scope !== Context::SCOPE_AI)
		{
			return $fieldData;
		}

		$disabledFieldNameMap = FieldFillPolicyService::getInstance()->getDisabledFieldNameMap($this->entityTypeId);
		if (empty($disabledFieldNameMap))
		{
			return $fieldData;
		}

		return array_filter(
			$fieldData,
			static fn(array $field) => !isset($disabledFieldNameMap[$field['ID']])
		);
	}

	private function getSupportedUserFieldTypes(): ?array
	{
		if ($this->scope === Context::SCOPE_AI)
		{
			return FieldFillPolicyService::getInstance()->getSupportedFieldTypes();
		}

		return null;
	}

	private function getCacheId(): string
	{
		return self::FIELD_DATA_CACHE_ID . '_' . $this->entityTypeId;
	}
}
