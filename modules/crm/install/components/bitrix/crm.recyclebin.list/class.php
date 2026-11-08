<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Crm;
use Bitrix\Crm\Integration\Recyclebin\RecyclingManager;
use Bitrix\Crm\Restriction\AvailabilityManager;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

Main\Loader::includeModule('crm');
Main\Loader::includeModule('recyclebin');

class CCrmRecyclebinListComponent extends \CBitrixComponent
{
	private const TTL = 30;
	private const ENTITY_TYPE_ID_PARAMETER = 'entityTypeId';
	private const GRID_ID = 'CRM_RECYCLE_BIN';

	public function executeComponent()
	{
		$toolsManager = \Bitrix\Crm\Service\Container::getInstance()->getIntranetToolsManager();
		$isAvailable = $toolsManager->checkCrmAvailability();
		if (!$isAvailable)
		{
			print AvailabilityManager::getInstance()->getCrmInaccessibilityContent();

			return;
		}

		$entityTypeName = $this->resolveEntityTypeName();
		if ($entityTypeName === null)
		{
			print AvailabilityManager::getInstance()->getCrmInaccessibilityContent();

			return;
		}

		$this->arResult['TTL'] = self::TTL;

		$this->arResult['ENTITY_TYPE_NAME'] = $entityTypeName;

		$this->arResult['ENTITY_TYPE_ID'] = \CCrmOwnerType::ResolveID($this->arResult['ENTITY_TYPE_NAME']);
		$this->arResult['RECYCLABLE_ENTITY_TYPE'] = RecyclingManager::resolveRecyclableEntityType(
			$this->arResult['ENTITY_TYPE_ID']
		);

		$narrowedEntityTypeId = $this->arResult['RECYCLABLE_ENTITY_TYPE'] === ''
			? 0
			: $this->arResult['ENTITY_TYPE_ID'];

		$this->arResult['GRID_ID'] = $this->resolveGridId($narrowedEntityTypeId);
		$this->arResult['FILTER_PRESETS'] = $this->resolveFilterPresets($narrowedEntityTypeId);

		$this->arResult['USER_ID'] = \CCrmSecurityHelper::GetCurrentUserID();
		$this->arResult['PATH_TO_USER_PROFILE'] = isset($this->arParams['~PATH_TO_USER_PROFILE'])
			? $this->arParams['~PATH_TO_USER_PROFILE'] : '';

		$this->includeComponentTemplate();
	}

	/**
	 * The id of the grid, and with it of the filter: the core hands the filter the id of the grid,
	 * and the presets a user keeps - the current one among them - are stored under it. A bin narrowed
	 * to one type therefore gets an id of its own, or the two screens would decide each other's saved
	 * presets. Zero is the bin of the whole CRM and keeps the id it has always had.
	 */
	protected function resolveGridId(int $entityTypeId): string
	{
		return $entityTypeId > 0 ? self::GRID_ID . '_' . $entityTypeId : self::GRID_ID;
	}

	/**
	 * Zero, as in resolveGridId(), is the bin of the whole CRM: it keeps the preset it has always had.
	 * A narrowed bin names its preset after the type, because the type is no longer a field of the
	 * filter there and nothing else on the screen says which bin this is.
	 */
	protected function resolveFilterPresets(int $entityTypeId): array
	{
		if ($entityTypeId > 0)
		{
			return [
				'preset_crm_entity_type' => [
					'name' => \CCrmOwnerType::GetDescription($entityTypeId),
					'default' => true,
					'fields' => [],
				],
			];
		}

		$entityNames = Crm\Integration\Recyclebin\RecyclingManager::getEntityNames();
		unset($entityNames[\CCrmOwnerType::Activity]);

		return array(
			'preset_crm_main_entities' => array(
				'name' => Loc::getMessage('CRM_RECYCLE_BIN_PRESET_MAIN_ENTITIES'),
				'default' => true,
				'fields' => array(
					'ENTITY_TYPE' => array_values($entityNames)
				)
			)
		);
	}

	/**
	 * The type the bin is narrowed to, an empty name for the bin of the whole CRM, or null for a
	 * type the caller named but is not to be shown - the answer to which is a refusal.
	 *
	 * The parameter of the component wins over the address, and it wins by being passed at all:
	 * a caller that names the type keeps the behaviour it has always had.
	 */
	protected function resolveEntityTypeName(): ?string
	{
		if (isset($this->arParams['~ENTITY_TYPE_NAME']))
		{
			return (string)$this->arParams['~ENTITY_TYPE_NAME'];
		}

		$entityTypeId = $this->resolveEntityTypeIdFromRequest();

		return $entityTypeId === null ? null : \CCrmOwnerType::ResolveName($entityTypeId);
	}

	/**
	 * The type named by the query string, 0 when the query names none, or null when the named one
	 * does not pass every gate.
	 *
	 * An address carrying the key has already asked for one type, so a value that fails a gate is
	 * refused rather than widened back to the bin of the whole CRM. Only the query is read: the
	 * same name in the body of the request must not decide which bin is opened.
	 */
	protected function resolveEntityTypeIdFromRequest(): ?int
	{
		$query = $this->request->getQueryList()->getValues();
		if (!array_key_exists(self::ENTITY_TYPE_ID_PARAMETER, $query))
		{
			return 0;
		}

		$rawEntityTypeId = $this->request->getQuery(self::ENTITY_TYPE_ID_PARAMETER);
		if (!is_string($rawEntityTypeId) || preg_match('/^[1-9][0-9]*$/D', $rawEntityTypeId) !== 1)
		{
			return null;
		}

		$entityTypeId = (int)$rawEntityTypeId;
		$userPermissions = \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions();
		if (!$userPermissions->entityType()->canReadItems($entityTypeId))
		{
			return null;
		}

		if (RecyclingManager::resolveRecyclableEntityType($entityTypeId) === '')
		{
			return null;
		}

		return $entityTypeId;
	}
}