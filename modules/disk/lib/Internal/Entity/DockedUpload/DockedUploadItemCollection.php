<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Entity\DockedUpload;

use Bitrix\Main\Entity\EntityCollection;

/**
 * @method DockedUploadItem[] getIterator()
 */
class DockedUploadItemCollection extends EntityCollection
{
	/**
	 * {@inheritDoc}
	 */
	protected static function getEntityClass(): string
	{
		return DockedUploadItem::class;
	}
}
