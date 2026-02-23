<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\Schema;

use Bitrix\Landing\Copilot\Connector\Schema\Dto\SchemaNodeDto;

abstract class Schema implements ISchema
{
	/**
	 * Returns the complete schema document including name and structure.
	 *
	 * @return array Fully built schema representation with name and structure.
	 */
	final public function getSchema(): array
	{
		return [
			'name' => $this->getName(),
			'structure' => $this->getStructure(),
		];
	}

	/**
	 * Returns the name of the schema.
	 *
	 * @return string The schema name.
	 */
	abstract protected function getName(): string;

	/**
	 * Creates and returns the internal structure of the schema object.
	 *
	 * @return SchemaNodeDto Fully built internal schema structure.
	 */
	abstract protected function getStructure(): SchemaNodeDto;
}