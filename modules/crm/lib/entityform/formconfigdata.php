<?php

namespace Bitrix\Crm\EntityForm;

use Bitrix\Crm\Filter\EntityEditorConfigDataProvider;
use Bitrix\Crm\Filter\Filter;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;
use CCrmOwnerType;

final class FormConfigData extends \Bitrix\UI\EntityForm\FormConfigData
{
	use EntityTypeIdResolveTrait;

	private ?Factory $factory;

	public function __construct(string $navParamName, string $moduleId, int|string $entityTypeId)
	{
		parent::__construct($navParamName, $moduleId, $entityTypeId);

		$this->factory = Container::getInstance()->getFactory($this->getCrmEntityTypeIdByEntityTypeId($entityTypeId));
	}

	private function isEntityCategoryValid(): bool
	{
		return $this->factory
			&& $this->factory?->isCategoriesEnabled()
			&& !in_array($this->factory->getEntityTypeId(), [\CCrmOwnerType::Contact, CCrmOwnerType::Company], true);
	}

	protected function getColumns(): array
	{
		$columns = parent::getColumns();
		if (!$this->isEntityCategoryValid())
		{
			return $columns;
		}

		$columns[] = [
			'id' => 'CATEGORY',
			'name' => Loc::getMessage('UI_FORM_CONFIG_CATEGORY'),
			'default' => true,
			'editable' => false,
		];

		return $columns;
	}

	protected function getFilter(): array
	{
		if (!$this->isEntityCategoryValid())
		{
			return [];
		}

		$gridId = $this->getGridId();

		$filterFields = (new Filter(
			$gridId,
			new EntityEditorConfigDataProvider($gridId, $this->factory),
		))->getFieldArrays();

		return [
			'FILTER_ID' => $this->getFilterName($this->factory),
			'FILTER_FIELDS' => $filterFields,
		];
	}

	protected function prepareRowData(int|string $scopeId, array $scope, string $entityTypeId): array
	{
		$data = parent::prepareRowData($scopeId, $scope, $entityTypeId);
		if (!$this->isEntityCategoryValid())
		{
			return $data;
		}

		$data['CATEGORY'] = htmlspecialcharsbx($this->getCategoryName($scope['ENTITY_TYPE_ID']));

		return $data;
	}

	protected function getContextActions(int $scopeId, array $scope): array
	{
		$actions = [];

		if (!$this->isEntityCategoryValid())
		{
			return $actions;
		}

		$jsEventData = Json::encode(['scopeId' => $scopeId]);

		$scopeAccess = ScopeAccess::getInstance($this->moduleId);

		if ($scopeAccess->canAddByEntityTypeId($this->entityTypeId))
		{
			$actions[] = [
				'TEXT' => Loc::getMessage('UI_FORM_CONFIG_COPY_CONTEXT_BUTTON'),
				'ONCLICK' => "BX.Event.EventEmitter.emit('BX.Ui.Form.ConfigItem:copyContextAction', {$jsEventData});return false;",
			];
		}
		if ($scopeAccess->canDelete($scopeId))
		{
			$actions[] = [
				'TEXT' => Loc::getMessage('UI_FORM_CONFIG_DELETE_CONTEXT_BUTTON'),
				'ONCLICK' => "BX.Event.EventEmitter.emit('BX.Ui.Form.ConfigItem:deleteContextAction', {$jsEventData});return false;",
			];
		}

		return $actions;
	}

	private function getCategoryName(string $editorEntityTypeId): ?string
	{
		$categoryId = (int)$this->getCategoryId($editorEntityTypeId);

		return $categoryId >= 0 ? $this->factory->getCategory($categoryId)?->getName() : null;
	}
}
