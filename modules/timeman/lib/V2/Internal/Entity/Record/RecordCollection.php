<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Entity\Record;

use Bitrix\Timeman\V2\Internal\Entity\AbstractEntityCollection;

/**
 * @extends AbstractEntityCollection<Record>
 */
final class RecordCollection extends AbstractEntityCollection
{
	protected static function getEntityClass(): string
	{
		return Record::class;
	}
}

