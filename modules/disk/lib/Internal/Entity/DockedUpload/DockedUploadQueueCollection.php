<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Entity\DockedUpload;

use Bitrix\Main\Entity\EntityCollection;

/**
 * @method DockedUploadQueue[] getIterator()
 */
class DockedUploadQueueCollection extends EntityCollection
{
	/**
	 * {@inheritDoc}
	 */
	protected static function getEntityClass(): string
	{
		return DockedUploadQueue::class;
	}
}
