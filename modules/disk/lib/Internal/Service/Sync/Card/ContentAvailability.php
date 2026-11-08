<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Card;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;

/**
 * Whose content a page of files may be fetched, and under which identity.
 *
 * The rule is deliberately pessimistic: content is offered only for what the rights of Disk
 * themselves make visible, and everything else - an object reached through an external link, a file
 * seen because it hangs on a task or a message - is answered as unavailable even where its owner
 * would in fact allow the download.
 *
 * The reason is identity rather than permission. There is no separate right to content in the model
 * of rights of Disk: the same canRead that opens the metadata opens the bytes. But an object visible
 * through interaction is fetched by the identifier of the attachment it hangs on, and whether that
 * attachment may be read is known only to the module that owns it. Those modules answer one object
 * at a time, which is the one thing a page assembled in fixed queries cannot afford, and connecting
 * them is work of a later stage. Until then the conservative answer is the honest one: a client told
 * "no content" asks a person, while a client told "content" and refused at the download has no move
 * left.
 */
final class ContentAvailability
{
	/**
	 * How many identifiers one statement carries. A page is smaller than this, and the chunking is
	 * here so that a caller assembling several pages at once still hands the database a size it
	 * plans well.
	 */
	private const IDS_PER_QUERY = 300;

	/**
	 * The kind of resource a download identifier names. The identifier is published as an opaque
	 * string rather than as a number precisely because it will not always name an object: the branch
	 * of stage 3 names an attachment, and a bare number could not tell one from the other.
	 */
	private const IDENTITY_OBJECT = 'object';

	/**
	 * The files of a page whose content may be fetched, as a set.
	 *
	 * One statement per batch with the list predicate of the module - the same one every large list
	 * of Disk is filtered by - and not a question per object: an answer per object is a query per
	 * object, which a page may not cost.
	 *
	 * @param int[] $fileIds real objects of the page that are files
	 * @return array<int, true> keyed by the identifier of the file
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function findAvailable(int $userId, array $fileIds): array
	{
		if ($fileIds === [] || $userId <= 0)
		{
			return [];
		}

		$available = [];
		foreach (array_chunk(array_values($fileIds), self::IDS_PER_QUERY) as $chunk)
		{
			$parameters = [
				'select' => ['ID'],
				'filter' => Query::filter()->whereIn('ID', $chunk),
			];
			$parameters = Driver::getInstance()->getRightsManager()->addRightsCheck(
				new DiskSecurityContext($userId),
				$parameters,
				['ID', 'CREATED_BY'],
			);

			$cursor = ObjectTable::getList($parameters);
			while ($row = $cursor->fetch())
			{
				$available[(int)$row['ID']] = true;
			}
		}

		return $available;
	}

	/**
	 * What the client hands to disk.sync.content.get to get the bytes of the given file.
	 *
	 * Qualified by the kind of resource it names so that the method reading it never has to guess:
	 * today the contour only ever answers for a real object, and the identity of stage 3 - an
	 * attachment of a neighbouring module - is added beside this one rather than in place of it.
	 */
	public function downloadIdOf(int $realObjectId): string
	{
		return self::IDENTITY_OBJECT . ':' . $realObjectId;
	}
}
