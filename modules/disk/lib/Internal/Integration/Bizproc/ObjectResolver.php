<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Integration\Bizproc;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Internal\Enum\ObjectValueSource;

/**
 * The object of disk a property of a business process names.
 *
 * A file property hands over the identifier of an attachment, and a template may hand over the identifier
 * of an object on its own. Which of the two a number is has to be said by the caller
 * ({@see ObjectValueSource}) and is never read off the value: the numbers of attachments and of objects
 * overlap, and the prefix n, which a file just uploaded carries, would otherwise let a value of a file
 * field address any object of the portal. A value naming nothing is no exception: the caller writes it to
 * the journal and stops.
 */
final class ObjectResolver
{
	public function resolve(mixed $value, ObjectValueSource $source): ?BaseObject
	{
		// A right lives on the real object: neither a link to it nor a revision of it carries one.
		return $this->findObject($value, $source)?->getRealObject();
	}

	private function findObject(mixed $value, ObjectValueSource $source): ?BaseObject
	{
		if (!is_string($value) && !is_int($value))
		{
			return null;
		}

		if (!preg_match('/^(n?)(\d+)$/', trim((string)$value), $match))
		{
			return null;
		}

		$id = (int)$match[2];
		if ($id <= 0)
		{
			return null;
		}

		$isNewObjectValue = $match[1] === 'n';

		return match ($source)
		{
			ObjectValueSource::ObjectId => BaseObject::loadById($id),
			// A number of a file field is the attachment and nothing else, and n<id> is no attachment at
			// all: reading either of them as an object of disk is the very guess the source was named to
			// avoid.
			ObjectValueSource::FileField => $isNewObjectValue
				? null
				: AttachedObject::loadById($id, ['OBJECT'])?->getObject()
			,
		};
	}
}
