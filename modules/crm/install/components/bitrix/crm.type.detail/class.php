<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Crm\Component\Base;
use Bitrix\Crm\Integration;
use Bitrix\Crm\Model\Dynamic\Type;
use Bitrix\Crm\Model\Field\DefaultValue\CloseDateConfigurator;
use Bitrix\Crm\RelationIdentifier;
use Bitrix\Crm\Restriction\RestrictionManager;
use Bitrix\Crm\Service;
use Bitrix\Crm\UserField\UserFieldManager;
use Bitrix\Crm\V2\Internal\Service\Type\HubActionUrlResolver;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI\Buttons;
use Bitrix\UI\Toolbar\ButtonLocation;

if (!Loader::includeModule('crm'))
{
	return;
}

class CrmTypeDetailComponent extends Base
{
	/**
	 * The preset the tile "Полный" of the legacy screen hands to the form ({@see Service\Broker\TypePreset}).
	 */
	private const FULL_PRESET_ID = 'bitrix:full';

	private const TAB_IDS = [
		'custom-section',
		'common',
		'fields',
		'relation',
		'user-fields',
		// 'conversion',
	];

	/** @var Type */
	protected $type;

	public function onPrepareComponentParams($arParams): array
	{
		$this->fillParameterFromRequest('entityTypeId', $arParams);
		$this->fillParameterFromRequest('automatedSolutionId', $arParams);
		$this->fillParameterFromRequest('activeTabId', $arParams);

		return parent::onPrepareComponentParams($arParams);
	}

	protected function init(): void
	{
		parent::init();

		if ($this->getErrors())
		{
			return;
		}

		$userPermissions = $this->userPermissions;
		$entityTypeId = (int) $this->arParams['entityTypeId'];
		$automatedSolutionId = (!empty($this->arParams['automatedSolutionId']) && (int)$this->arParams['automatedSolutionId'] > 0)
			? (int)$this->arParams['automatedSolutionId']
			: null
		;
		if($entityTypeId > 0)
		{
			$this->type = Service\Container::getInstance()->getTypeByEntityTypeId($entityTypeId);
			if(!$this->type)
			{
				$this->errorCollection[] = new Error(Loc::getMessage('CRM_TYPE_TYPE_NOT_FOUND'));
				return;
			}

			$requestUrl = $this->request->getRequestUri();
			$consistentUrl = Service\Container::getInstance()->getRouter()->getConsistentUrlFromPartlyDefined($requestUrl);
			if ($consistentUrl)
			{
				LocalRedirect($consistentUrl->getUri());
				return;
			}

			if (!$userPermissions->dynamicType()->canUpdate($this->type->getEntityTypeId()))
			{
				$this->errorCollection[] = new Error(Loc::getMessage('CRM_TYPE_TYPE_ACCESS_DENIED'));
			}
			else
			{
				$this->getApplication()->setTitle(
					Loc::getMessage('CRM_TYPE_TYPE_EDIT_TITLE', [
						'#TITLE#' => htmlspecialcharsbx($this->type->getTitle()),
					])
				);
			}
		}
		elseif($userPermissions->dynamicType()->canAdd($automatedSolutionId))
		{
			$this->type = $this->createDraftType();
			$this->getApplication()->setTitle(Loc::getMessage('CRM_TYPE_DETAIL_NEW_TITLE'));
		}
		else
		{
			$this->errorCollection[] = new Error(Loc::getMessage('CRM_TYPE_TYPE_ACCESS_DENIED'));
		}
	}

	protected function createDraftType(): Type
	{
		/** @var Type $type */
		$type = Service\Container::getInstance()->getDynamicTypeDataClass()::createObject();
		$type->unset('CREATED_TIME');
		$type->unset('UPDATED_TIME');
		$type->unset('CREATED_BY');
		$type->unset('UPDATED_BY');

		if (!empty($this->arParams['automatedSolutionId']) && (int)$this->arParams['automatedSolutionId'] > 0)
		{
			$type->setCustomSectionId($this->arParams['automatedSolutionId']);
		}

		// Under the same gate as the hub template below, and for the same reason: the tiles of the
		// presets are the legacy screen's, and the creation flow of the hub has none of them. With
		// nothing left to switch the settings on, a draft would arrive with every toggle off and the
		// created process would come out empty, so the draft of the hub is given the set of the full
		// preset - the one the tile "Полный" hands to the legacy form. Off the feature, the draft is
		// exactly the one the legacy screen has always started its tiles from.
		if (\Bitrix\Crm\Feature::enabled(\Bitrix\Crm\Feature\SmartProcessCrmHub::class))
		{
			$this->applyFullPresetToDraftType($type);
		}

		return $type;
	}

	/**
	 * Fills a draft with the settings of the full preset the way the server has always applied the
	 * fields of a preset: the camelCase keys of the preset are the keys of the form, and what turns
	 * them into fields of the type is the orm converter ({@see \Bitrix\Crm\Controller\Type::update()}).
	 * The title of the preset is deliberately left out - it names the tile, not the process, and the
	 * name is what the person is about to type in the hero of the hub.
	 */
	private function applyFullPresetToDraftType(Type $type): void
	{
		$preset = null;
		foreach (Service\Container::getInstance()->getTypePresetBroker()->getList() as $candidate)
		{
			if ($candidate->getId() === self::FULL_PRESET_ID)
			{
				$preset = $candidate;
				break;
			}
		}

		if ($preset === null)
		{
			return;
		}

		$fields = Service\Container::getInstance()->getOrmObjectConverter()->convertKeysToUpperCase(
			$preset->getData()
		);
		unset($fields['TITLE']);

		foreach ($fields as $name => $value)
		{
			if ($type->entity->hasField($name))
			{
				$type->set($name, $value);
			}
		}
	}

	public function executeComponent()
	{
		$this->init();

		if($this->getErrors())
		{
			$this->includeComponentTemplate();
			return;
		}

		$this->arResult['type'] = $this->getType()->jsonSerialize();
		$this->arResult['isCategoriesControlDisabled'] = false;
		$this->arResult['isRecyclebinControlDisabled'] = false;
		$this->arResult['categoriesCount'] = 0;
		if ($this->type->getIsCategoriesEnabled() && $this->type->getId() > 0)
		{
			$factory = Service\Container::getInstance()->getFactory($this->type->getEntityTypeId());
			if ($factory)
			{
				$categories = $factory->getCategories();
				$this->arResult['categoriesCount'] = count($categories);
				if (count($categories) > 1)
				{
					$this->arResult['isCategoriesControlDisabled'] = true;
				}
			}
		}
		if ($this->type->getIsRecyclebinEnabled() && $this->type->getId() > 0)
		{
			$recyblebinController = Bitrix\Crm\Recycling\DynamicController::getInstance($this->type->getEntityTypeId());
			if ($recyblebinController->countItemsInRecycleBin() > 0)
			{
				$this->arResult['isRecyclebinControlDisabled'] = true;
			}
		}
		$this->arResult['presets'] = Service\Container::getInstance()->getTypePresetBroker()->getList();
		$this->arResult['presetCategories'] = Service\Container::getInstance()->getTypePresetBroker()->getCategories();
		$this->arResult['listUrl'] = Service\Container::getInstance()->getRouter()->getTypeListUrl()->getUri();
		$this->arResult['relations'] = $this->getRelations();
		$this->arResult['isCustomSectionsAvailable'] = Integration\IntranetManager::isCustomSectionsAvailable();
		$this->arResult['linkedUserFields'] = $this->getLinkedUserFields();
		$this->arResult['isExternal'] = ($this->arParams['isExternal'] ?? null) === true
			|| $this->request->get('isExternal') === 'Y'
		;
		if (!$this->type->getId())
		{
			$this->arResult['canEditAutomatedSolution'] = $this->arResult['isExternal']
				? $this->userPermissions->automatedSolution()->canEdit()
				: $this->userPermissions->isCrmAdmin();
		}
		else
		{
			$this->arResult['canEditAutomatedSolution'] = $this->type->getCustomSectionId() > 0 ? $this->userPermissions->automatedSolution()->canEdit() : $this->userPermissions->isCrmAdmin();
		}
		$this->arResult['canToggleAutomatedSolutionSwitcher'] = $this->userPermissions->automatedSolution()->canEdit() && $this->userPermissions->isCrmAdmin();

		$this->initializeRestrictionValues();

		$this->arResult['activeTabId'] = $this->arParams['activeTabId'] ?? null;
		if (!in_array($this->arResult['activeTabId'], self::TAB_IDS, true))
		{
			$this->arResult['activeTabId'] = $this->arResult['isExternal'] ? 'custom-section' : 'common';
		}

		$this->arResult['permissionsUrl'] = $this->router->getEntityPermissionsUrl($this->type->getEntityTypeId());

		if (\Bitrix\Crm\Feature::enabled(\Bitrix\Crm\Feature\SmartProcessCrmHub::class))
		{
			// A draft does carry an entity type id - the default of the field hands it out and burns
			// it off the sequence every time the screen is opened - but no record of a type stands
			// behind it yet. Addresses built off such an id look valid and lead nowhere, so a draft
			// is served no map at all and the action buttons are simply not drawn. The hub is the
			// form of the creation flow all the same, hence the template either way.
			if (!$this->type->isNew())
			{
				$this->arResult['hubActionUrls'] = (new HubActionUrlResolver())
					->getActionUrls($this->type->getEntityTypeId())
				;
			}

			// The legacy form sends its seed back as a whole, so the description reaches the hub alone
			$this->arResult['description'] = $this->type->getDescription();

			$this->setTemplateName('hub');
		}

		$this->includeComponentTemplate();
	}

	public function getType(): Type
	{
		return $this->type;
	}

	public function getDaysBeforeClose(): int
	{
		$typeDaysBeforeClose = $this->type->getDaysBeforeClose();
		if ($typeDaysBeforeClose !== null)
		{
			return $typeDaysBeforeClose;
		}

		$defaultDaysBeforeClose =
			ServiceLocator::getInstance()
				->get(CloseDateConfigurator::class)
				?->resolveDefaultCloseDateDays($this->type->getEntityTypeId())
		;

		return $defaultDaysBeforeClose ?? CloseDateConfigurator::DEFAULT_VALUE;
	}

	protected function getRelations(): array
	{
		$relationManager = Service\Container::getInstance()->getRelationManager();
		$parent = [];
		$child = [];
		$entityTypeId = $this->type->getEntityTypeId();
		$availableTypes = $relationManager->getAvailableForParentBindingEntityTypes($entityTypeId);
		foreach ($availableTypes as $typeId => $type)
		{
			$relation = $relationManager->getRelation(new RelationIdentifier($typeId, $entityTypeId));
			$parent[] = [
				'title' => $type['title'],
				'entityTypeId' => $typeId,
				'isChecked' => $relation ? true : false,
				'isChildrenListEnabled' => $relation ? $relation->isChildrenListEnabled() : false,
			];
		}
		$availableTypes = $relationManager->getAvailableForChildBindingEntityTypes($entityTypeId);
		foreach ($availableTypes as $typeId => $type)
		{
			$relation = $relationManager->getRelation(new RelationIdentifier($entityTypeId, $typeId));
			$child[] = [
				'title' => $type['title'],
				'entityTypeId' => $typeId,
				'isChecked' => $relation ? true : false,
				'isChildrenListEnabled' => $relation ? $relation->isChildrenListEnabled() : false,
			];
		}

		return [
			'parent' => $parent,
			'child' => $child,
		];
	}

	protected function getEntityTitle(int $entityTypeId): string
	{
		$factory = Service\Container::getInstance()->getFactory($entityTypeId);
		if ($factory)
		{
			return $factory->getEntityDescription();
		}

		return \CCrmOwnerType::GetDescription($entityTypeId);
	}

	protected function getPresets(): array
	{
		$presets = [];

		foreach (Service\Container::getInstance()->getTypePresetBroker()->getList() as $preset)
		{
			$presets[] = $preset->jsonSerialize();
		}

		return $presets;
	}

	protected function getToolbarParameters(): array
	{
		$parameters = [];
		$parameters['buttons'][ButtonLocation::RIGHT][] = new Buttons\Button([
			'color' => Buttons\Color::LIGHT_BORDER,
			'text' => Loc::getMessage('CRM_COMMON_HELP'),
			'onclick' => new Buttons\JsHandler('BX.Crm.Router.Instance.openTypeHelpPage'),
		]);

		return array_merge(parent::getToolbarParameters(), $parameters);
	}

	protected function getLinkedUserFields(): array
	{
		$linkedDescriptions = UserFieldManager::getLinkedUserFieldsDescription();
		$entityTypeName = \CCrmOwnerType::ResolveName($this->type->getEntityTypeId());

		$result = [];
		foreach (UserFieldManager::getLinkedUserFieldsMap() as $userFieldName => $userField)
		{
			$isEnabled = (!$this->type->isNew() && UserFieldManager::isEntityEnabledInUserField($userField, $entityTypeName));

			$result[$userFieldName] = $linkedDescriptions[$userFieldName];
			$result[$userFieldName]['isEnabled'] = $isEnabled;
		}

		return $result;
	}

	protected function initializeRestrictionValues(): void
	{
		$restrictions = RestrictionManager::getDynamicTypesLimitRestriction();
		$isRestricted = $restrictions->isCreateTypeRestricted();

		$this->arResult['isRestricted'] = $isRestricted;
		$this->arResult['restrictionErrorMessage'] = $isRestricted
			? $restrictions->getCreateTypeRestrictedError()->getMessage()
			: ''
		;
		$this->arResult['restrictionSliderCode'] = $isRestricted && $restrictions->canShowRestrictionSlider()
			? $restrictions->getCreateTypeRestrictionSliderCode()
			: ''
		;
	}
}
