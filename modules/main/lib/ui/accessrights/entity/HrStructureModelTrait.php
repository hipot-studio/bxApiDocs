<?php

namespace Bitrix\Main\UI\AccessRights\Entity;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\NodeDataBuilder;
use Bitrix\Main\Loader;

trait HrStructureModelTrait
{
	private static array $modelsCache = [];

	protected function loadModel(): void
	{
		if ($this->model)
		{
			return;
		}

		if (array_key_exists($this->getId(), self::$modelsCache))
		{
			$this->model = self::$modelsCache[$this->getId()];

			return;
		}

		if (Loader::includeModule('humanresources'))
		{
			$this->model =
				NodeDataBuilder::createWithFilter(
					new NodeFilter(IdFilter::fromId($this->getId()))
				)
				->get()
			;

			self::$modelsCache[$this->getId()] = $this->model;
		}
	}
}
