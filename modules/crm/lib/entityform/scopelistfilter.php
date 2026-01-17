<?php

namespace Bitrix\Crm\EntityForm;

use Bitrix\Crm\Filter\EntityEditorConfigDataProvider;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\UI\Filter\Options;
use Bitrix\Ui\EntityForm\Scope;

final class ScopeListFilter extends \Bitrix\Ui\EntityForm\ScopeListFilter
{
	use EntityTypeIdResolveTrait;

	private ?Factory $factory;

	public function prepareFilter(string $entityTypeId, bool $isAdminForEntity, bool $excludeEmptyAccessCode, Scope $scope): array
	{
		$this->factory = Container::getInstance()->getFactory($this->getCrmEntityTypeIdByEntityTypeId($entityTypeId));

		if (
			!$this->factory
			|| !$this->factory->isCategoriesEnabled()
			|| in_array($this->factory->getEntityTypeId(), [\CCrmOwnerType::Contact, \CCrmOwnerType::Company])
		)
		{
			return parent::prepareFilter($entityTypeId, $isAdminForEntity, $excludeEmptyAccessCode, $scope);
		}

		$filterName = $this->getFilterName($this->factory);

		$provider = new EntityEditorConfigDataProvider('editor_scopes', $this->factory);
		$entityFilter = new Filter($filterName, $provider);

		$filterOptions = new Options($filterName);
		$requestFilter = $filterOptions->getFilter($entityFilter->getFieldArrays());

		$filter = [];

		if (!$isAdminForEntity)
		{
			$filter['@ID'] = $scope->getScopesIdByUser();
		}

		if ($excludeEmptyAccessCode)
		{
			$filter['!=ACCESS_CODE'] = '';
		}

		$provider->prepareListFilter($filter, $requestFilter);

		return $filter;
	}
}
