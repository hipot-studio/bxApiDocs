<?php

namespace Bitrix\DocumentGenerator\DataProvider;

use Bitrix\DocumentGenerator\DataProvider;
use Bitrix\Main\Entity\Base;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\SystemException;

abstract class EntityDataProvider extends DataProvider
{
	public function __construct($source, array $options = [])
	{
		parent::__construct($source, $options);
		$this->fetchData();
	}

	/**
	 * @return string
	 */
	abstract protected function getTableClass();

	/**
	 * @return array
	 */
	public function getFields()
	{
		if($this->fields === null)
		{
			$fields = array_keys($this->getEntity()->getFields());
			$hiddenFields = $this->getHiddenFields();
			$fields = array_diff($fields, $hiddenFields);
			foreach($fields as $placeholder)
			{
				$this->fields[$placeholder] = [
					'TITLE' => $this->getEntity()->getField($placeholder)->getTitle(),
				];
			}
		}

		return $this->fields;
	}

	/**
	 * @return bool
	 */
	public function isLoaded()
	{
		return !empty($this->data);
	}

	/**
	 * @return Base
	 */
	protected function getEntity()
	{
		/** @var \Bitrix\Main\Entity\DataManager $className */
		$className = $this->getTableClass();
		return Base::getInstance($className);
	}

	/**
	 * @return array
	 */
	protected function getHiddenFields()
	{
		return [];
	}

	/**
	 * @return array
	 */
	protected function getGetListParameters()
	{
		$result = [
			'select' => ['*'],
		];

		return $result;
	}

	/**
	 * Fill $this->data.
	 */
	protected function fetchData()
	{
		if ($this->data === null)
		{
			$this->data = [];

			/** @var \Bitrix\Main\Entity\DataManager $className */
			$className = $this->getTableClass();
			if (!is_a($className, DataManager::class, true) || is_object($this->source))
			{
				return;
			}

			if ($this->isEmptySource())
			{
				return;
			}

			try
			{
				$data = $className::getByPrimary($this->source, $this->getGetListParameters())->fetch();
			}
			catch (SystemException)
			{
				$data = $className::getByPrimary($this->source, ['select' => ['*']])->fetch();
			}

			if ($data)
			{
				$this->data = $data;
			}
		}
	}

	private function isEmptySource(): bool
	{
		if ($this->source === null)
		{
			return true;
		}

		if (is_string($this->source) && trim($this->source) === '')
		{
			return true;
		}

		if (is_numeric($this->source) && (int)$this->source <= 0)
		{
			return true;
		}

		return false;
	}

	/**
	 * @return bool
	 */
	protected function isLightMode()
	{
		return (isset($this->options['isLightMode']) && $this->options['isLightMode'] === true);
	}

	/**
	 * @return bool
	 */
	public function isRootProvider()
	{
		return true;
	}
}
