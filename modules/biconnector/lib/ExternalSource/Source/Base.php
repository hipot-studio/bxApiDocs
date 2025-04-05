<?php

namespace Bitrix\BIConnector\ExternalSource\Source;

use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceSettingsCollection;
use Bitrix\Main;

abstract class Base
{
	protected int $id;

	/**
	 * @param int $id source id
	 */
	public function __construct(int $id)
	{
		$this->id = $id;
	}

	/**
	 * Connects to external source
	 */
	abstract public function connect(ExternalSourceSettingsCollection $settings): Main\Result;

	/**
	 * @return array
	 */
	abstract public function getEntityList(): array;

	/**
	 * @param string $entityName
	 * @return array
	 */
	abstract public function getDescription(string $entityName): array;

	/**
	 * @param string $entityName
	 * @param int $n
	 * @return array
	 */
	abstract public function getFirstNData(string $entityName, int $n): array;
}
