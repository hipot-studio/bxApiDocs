<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Settings;

use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Dto\Dto;

final class SettingMetaDto extends Dto
{
	#[Description('Human-readable title of the setting.')]
	public string $title;

	#[Description('Explanation of the setting and the effect of changing it.')]
	public string $description;

	#[Description(
		'CRM-specific value schema, not JSON Schema. type is boolean, integer, string, select, multiSelect, or '
		. 'object. integer has inclusive min/max (null means no bound) and step based on min, or zero when min is null. '
		. 'string has minLength/maxLength (null means no length bound). select and multiSelect have options with '
		. 'value/title/description; select takes one exact option value, and multiSelect takes a unique list of exact '
		. 'option values (the empty list is valid). object has fields with id/title/description/required and schema, '
		. 'which uses this format recursively. '
		. 'Missing descriptions are empty strings. For crm.settings.set, send all declared object fields; '
		. 'the object value is replaced, not patched.',
	)]
	public array $schema;
}
