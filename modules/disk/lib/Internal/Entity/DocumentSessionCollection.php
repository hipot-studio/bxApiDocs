<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Entity;

use Bitrix\Main\Entity\EntityCollection;

class DocumentSessionCollection extends EntityCollection
{
	/**
	 * {@inheritDoc}
	 */
	protected static function getEntityClass(): string
	{
		return DocumentSession::class;
	}
}