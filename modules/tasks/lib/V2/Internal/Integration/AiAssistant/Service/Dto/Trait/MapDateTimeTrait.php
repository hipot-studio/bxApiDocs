<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\Dto\Trait;

use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Integration\AiAssistant\Service\SchemaBuilder\BaseSchemaBuilder;

trait MapDateTimeTrait
{
	use MapTypeTrait;

	private static function mapFormattedDateTime(array $props, string $key): ?DateTime
	{
		$formattedDate = static::mapString($props, $key);

		try
		{
			return
				$formattedDate !== null
					? new DateTime($formattedDate, BaseSchemaBuilder::DATE_FORMAT)
					: null
			;
		}
		catch (ObjectException)
		{
			return null;
		}
	}
}
