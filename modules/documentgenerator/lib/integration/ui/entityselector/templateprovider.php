<?php

namespace Bitrix\DocumentGenerator\Integration\UI\EntitySelector;

use Bitrix\DocumentGenerator\DataProviderManager;
use Bitrix\DocumentGenerator\Driver;
use Bitrix\DocumentGenerator\Model\TemplateTable;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\SearchQuery;

final class TemplateProvider extends BaseProvider
{
	public function __construct(array $options = [])
	{
		parent::__construct();

		$this->options = [
			'value' => $options['value'] ? (int)$options['value'] : null,
		];

		// for now, we work only with crm data providers, but later this parameter should be passed with $options
		$moduleId = 'crm';

		$providerClassName = $options['providerClassName'] ? (string)$options['providerClassName'] : null;
		if (!empty($providerClassName) && Loader::includeModule($moduleId))
		{
			if (!DataProviderManager::checkProviderName($providerClassName, $moduleId))
			{
				$providerClassName = null;
			}
		}

		$this->options['providerClassName'] = $providerClassName;
	}

	public function isAvailable(): bool
	{
		return Driver::getInstance()->getUserPermissions()->canViewDocuments();
	}

	/**
	 * @param int[] $ids
	 * @return Item[]
	 */
	public function getItems(array $ids): array
	{
		$filter = $this->getDefaultFilter();
		if (!$filter || empty($ids))
		{
			return [];
		}

		$filter->whereIn('ID', $ids);

		return $this->findItems($filter);
	}

	public function fillDialog(Dialog $dialog): void
	{
		$filter = $this->getDefaultFilter();
		if (!$filter)
		{
			return;
		}

		$dialog->addRecentItems($this->findItems($filter));
	}

	public function doSearch(SearchQuery $searchQuery, Dialog $dialog): void
	{
		$filter = $this->getDefaultFilter();
		if (!$filter)
		{
			return;
		}

		$filter->whereLike('NAME', $searchQuery->getQuery());

		$dialog->addItems($this->findItems($filter));
	}

	private function findItems(ConditionTree $filter): array
	{
		$templates = TemplateTable::getList([
			'select' => ['ID', 'NAME'],
			'filter' => $filter,
			'order' => ['SORT' => 'asc', 'ID' => 'asc'],
		])->fetchAll();

		return array_map(function ($template) {
			return new Item([
				'id' => $template['ID'],
				'entityId' => 'documentgenerator-template',
				'title' => $template['NAME'],
				'searchable' => true,
				'hidden' => false,
			]);
		}, $templates);
	}

	private function getDefaultFilter(): ?ConditionTree
	{
		$className = $this->getOption('providerClassName');
		if (!$className)
		{
			return null;
		}

		$userId = Driver::getInstance()->getUserId();
		if ($userId <= 0)
		{
			return null;
		}

		$value = $this->getOption('value');
		if (!$value)
		{
			$value = ' ';
		}

		return TemplateTable::prepareClassNameFilter((string)$className, $userId, $value);
	}
}
