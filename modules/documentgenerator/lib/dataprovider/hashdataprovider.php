<?php

namespace Bitrix\DocumentGenerator\DataProvider;

use Bitrix\DocumentGenerator\DataProvider;

abstract class HashDataProvider extends DataProvider
{
	private bool $initializingData = true;
	private int $explicitDataRevision = 0;

	public function __construct($source, array $options = [])
	{
		parent::__construct($source, $options);
		$this->data = [];
		if(is_array($source))
		{
			$this->setData($source);
		}
		$this->initializingData = false;
	}

	public function setData(array $data): HashDataProvider
	{
		$this->data = [];
		foreach($this->getFields() as $placeholder => $field)
		{
			if(isset($data[$placeholder]))
			{
				$this->data[$placeholder] = $data[$placeholder];
			}
		}
		if (!$this->initializingData)
		{
			++$this->explicitDataRevision;
		}

		return $this;
	}

	public function getExplicitDataRevision(): int
	{
		return $this->explicitDataRevision;
	}

	public function getData(): array
	{
		return $this->data;
	}
}
