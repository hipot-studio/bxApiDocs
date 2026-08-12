<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Factory;

use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\ArrayProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\BooleanProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\IntegerProperty;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\InputScheme\Properties\ObjectProperty;

/**
 * Builds reusable input schema fragments for relation tools.
 */
final class RelationsSchemaFactory
{
	/**
	 * An array of {entityTypeId, isChildrenListEnabled} objects (used by add/configure tools).
	 */
	public static function entitiesArrayProperty(string $id, string $description): ArrayProperty
	{
		$item = (new ObjectProperty('relationEntity', 'A single relation target.'))
			->setProperties([
				(new IntegerProperty(
					'entityTypeId',
					'ENTITY_TYPE_ID of the related entity (use search_dynamic_type for smart processes).',
				))
					->setIsRequired(true)
				,
				(new BooleanProperty(
					'isChildrenListEnabled',
					'Whether linked items are shown as a list (tab) in the parent entity card.',
				))
					->setIsRequired(true)
				,
			])
		;

		return (new ArrayProperty($id, $description))
			->setArrayItemProperty($item)
		;
	}
}
