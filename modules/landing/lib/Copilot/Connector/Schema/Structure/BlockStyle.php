<?php

declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\Schema\Structure;

use Bitrix\Landing\Copilot\Connector\Schema\Dto\SchemaNodeDto;

class BlockStyle
{
	/**
	 * Creates the structure describing block style properties.
	 *
	 * @return SchemaNodeDto Fully built block style structure.
	 */
	public function createBlockStyleStructure(): SchemaNodeDto
	{
		$properties = [
			new SchemaNodeDto('string', 'background'),
			new SchemaNodeDto('string', 'textsColor'),
			new SchemaNodeDto('string', 'headersColor'),
			new SchemaNodeDto('string', 'textsFontName'),
			new SchemaNodeDto('string', 'headersFontName'),
		];

		return new SchemaNodeDto(
			'object',
			null,
			[
				'properties' => $properties,
			]
		);
	}
}
