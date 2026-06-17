<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Dto\Record;

use Bitrix\Timeman\V2\Public\Dto\AbstractCollection;

final class RecordCollection extends AbstractCollection
{
	protected static function getItemClass(): string
	{
		return Record::class;
	}
}
