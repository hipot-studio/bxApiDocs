<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Card;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\EntityRefDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\ObjectCardDto;
use Bitrix\Disk\Infrastructure\Rest\V3\Structure\Sync\SectionsStructure;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Internals\StorageTable;
use Bitrix\Disk\Internals\VersionTable;
use Bitrix\Disk\ProxyType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\UserTable;
use Bitrix\Main\Web\MimeType;

/**
 * The cards of one page of objects (DTO-01).
 *
 * The collector describes objects; it does not decide which of them the reader may see. That was
 * decided by the fetch that produced the page, and asking again here would both cost a second pass
 * over the rights and let the two answers disagree. The one thing it does decide is whether the
 * bytes of a file may be fetched - {@see ContentAvailability} - because that question is about the
 * identity of the resource rather than about visibility.
 *
 * Everything is read for the page as a whole: the rows of the objects, their versions, the storages
 * they lie in, the people the cards name. A question per object would be a query per object, and a
 * page of a hundred would cost hundreds of round trips against tables of millions of rows. How many
 * queries the places cost is not fixed in the same way and cannot be - see {@see PlaceCollector} -
 * but nothing here multiplies with the size of the page.
 *
 * Sections are honoured rather than noted: a page asked only for metadata never runs the search for
 * places, and a page asked only for location never reads the versions, the storages or the names.
 * What a section does not cover is absent from the card instead of arriving empty, so that a client
 * can always tell "there is none" from "you did not ask".
 */
final class CardCollector
{
	/**
	 * How many identifiers one statement carries. A page is smaller than this; the chunking keeps a
	 * caller that assembles several pages at once from handing the database a list it plans badly.
	 */
	private const IDS_PER_QUERY = 300;

	/** What a stored content type says when it says nothing. */
	private const GENERIC_MIME_TYPE = 'application/octet-stream';

	private PlaceCollector $places;
	private ContentAvailability $contentAvailability;
	private PlaceExpectation $placeExpectation;

	public function __construct(
		?PlaceCollector $places = null,
		?ContentAvailability $contentAvailability = null,
		?PlaceExpectation $placeExpectation = null,
	)
	{
		$this->places = $places ?? new PlaceCollector();
		$this->contentAvailability = $contentAvailability ?? new ContentAvailability();
		$this->placeExpectation = $placeExpectation ?? new PlaceExpectation();
	}

	/**
	 * The cards of a page, in the order the objects were asked about.
	 *
	 * An object that no longer exists by the time the page is assembled simply has no card: it was
	 * deleted between the fetch and the assembly, and the client learns of that from the journal. The
	 * caller sees which ones dropped out by comparing what it asked for with the objects of the
	 * cards it got.
	 *
	 * @param int[] $realObjectIds real objects of the page, as the fetch collapsed them
	 * @param int[] $visibleStorageIds storages the user sees, from
	 *        {@see \Bitrix\Disk\Internal\Service\Sync\StorageListProvider}
	 * @return ObjectCardDto[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function collect(
		int $userId,
		array $realObjectIds,
		array $visibleStorageIds,
		SectionsStructure $sections,
	): array
	{
		$realObjectIds = self::normaliseIds($realObjectIds);
		if ($realObjectIds === [])
		{
			return [];
		}

		$withMetadata = $sections->has(SectionsStructure::SECTION_METADATA);
		$withLocation = $sections->has(SectionsStructure::SECTION_LOCATION);

		$rows = $this->readObjects($realObjectIds, $withMetadata);
		if ($rows === [])
		{
			return [];
		}

		$fileIds = [];
		foreach ($rows as $objectId => $row)
		{
			if ($row['CARD_TYPE'] === ObjectCardDto::TYPE_FILE)
			{
				$fileIds[] = $objectId;
			}
		}

		$versions = [];
		$available = [];
		$owners = [];
		$parties = [];
		if ($withMetadata)
		{
			$versions = $this->readVersions($fileIds);
			$available = $this->contentAvailability->findAvailable($userId, $fileIds);
			$owners = $this->readStorageOwners($rows);
			$parties = $this->readParties($rows, $owners);
		}

		$placeSet = $withLocation
			? $this->places->collect($userId, $realObjectIds, self::normaliseIds($visibleStorageIds))
			: null
		;

		$expecting = $placeSet === null
			? []
			: $this->placeExpectation->findExpecting($userId, self::withoutPlaces($rows, $placeSet))
		;

		$cards = [];
		foreach ($realObjectIds as $objectId)
		{
			$row = $rows[$objectId] ?? null;
			if ($row === null)
			{
				continue;
			}

			$card = new ObjectCardDto();
			$card->type = $row['CARD_TYPE'];
			$card->objectId = $objectId;

			if ($withMetadata)
			{
				$this->fillMetadata($card, $row, $versions, $available, $owners, $parties);
			}

			if ($placeSet !== null)
			{
				$card->places = $placeSet->getPlacesOf($objectId);
				$card->placeExpected = isset($expecting[$objectId]);
			}

			$cards[] = $card;
		}

		return $cards;
	}

	/**
	 * The rows of the page, read in one statement per batch.
	 *
	 * The stored content type travels with the row through the reference the entity already declares
	 * on the table of files, so knowing what a file is costs no query of its own. An object of a kind
	 * the card has no shape for is dropped rather than guessed at.
	 *
	 * @param int[] $objectIds
	 * @return array<int, array<string, mixed>>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function readObjects(array $objectIds, bool $withMetadata): array
	{
		$select = ['ID', 'TYPE'];
		if ($withMetadata)
		{
			$select = [
				'ID',
				'TYPE',
				'NAME',
				'STORAGE_ID',
				'SIZE',
				'GLOBAL_CONTENT_VERSION',
				'CREATE_TIME',
				'UPDATE_TIME',
				'CREATED_BY',
				'UPDATED_BY',
				'CONTENT_TYPE' => 'FILE_CONTENT.CONTENT_TYPE',
			];
		}

		$rows = [];
		foreach (array_chunk($objectIds, self::IDS_PER_QUERY) as $chunk)
		{
			$cursor = ObjectTable::getList([
				'select' => $select,
				'filter' => Query::filter()->whereIn('ID', $chunk),
			]);

			while ($row = $cursor->fetch())
			{
				$type = self::typeOf((int)$row['TYPE']);
				if ($type === null)
				{
					continue;
				}

				$rows[(int)$row['ID']] = $row + ['CARD_TYPE' => $type];
			}
		}

		return $rows;
	}

	/**
	 * The current version of every file of the page, asked of the database rather than picked out of
	 * every version ever stored.
	 *
	 * The row a file is at right now is the one written last. A version is written only after the
	 * content of the object has already been counted up, one joined to the version before it is
	 * rewritten in place, and restoring an older version writes a new row like any other change of
	 * content - so the greatest identifier of a file is the state its content is in. Reading the rows
	 * to work that out in code costs the whole history of a document on every page it appears in, and
	 * a document kept under automatic saving has thousands of them.
	 *
	 * Two statements per batch, both bounded: the identifiers over the index of the object, then the
	 * numbers of exactly those rows.
	 *
	 * @param int[] $fileIds
	 * @return array<int, array{id: int, number: int}>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function readVersions(array $fileIds): array
	{
		if ($fileIds === [])
		{
			return [];
		}

		$versions = [];
		foreach (array_chunk($fileIds, self::IDS_PER_QUERY) as $chunk)
		{
			foreach (self::readVersionsByIds(self::readCurrentVersionIds($chunk)) as $objectId => $version)
			{
				$versions[$objectId] = $version;
			}
		}

		return $versions;
	}

	/**
	 * @param int[] $fileIds
	 * @return int[] one identifier per file that has any version at all
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private static function readCurrentVersionIds(array $fileIds): array
	{
		$rows = VersionTable::query()
			->registerRuntimeField(new ExpressionField('CURRENT_ID', 'MAX(%s)', ['ID']))
			->setSelect(['CURRENT_ID'])
			->whereIn('OBJECT_ID', $fileIds)
			->addGroup('OBJECT_ID')
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['CURRENT_ID'], $rows);
	}

	/**
	 * @param int[] $versionIds
	 * @return array<int, array{id: int, number: int}> keyed by the object
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private static function readVersionsByIds(array $versionIds): array
	{
		if ($versionIds === [])
		{
			return [];
		}

		$rows = VersionTable::query()
			->setSelect(['ID', 'OBJECT_ID', 'GLOBAL_CONTENT_VERSION'])
			->whereIn('ID', $versionIds)
			->fetchAll()
		;

		$versions = [];
		foreach ($rows as $row)
		{
			$versions[(int)$row['OBJECT_ID']] = [
				'id' => (int)$row['ID'],
				'number' => (int)$row['GLOBAL_CONTENT_VERSION'],
			];
		}

		return $versions;
	}

	/**
	 * Who owns the storage of every object of the page, read in one statement.
	 *
	 * A workgroup is named by the storage itself: the module renames the storage whenever the group
	 * is renamed, so the column already holds the current name and no other module has to be asked.
	 * A personal storage is named by its owner instead, whose name is read anyway for the authors of
	 * the page and is the one that stays current when a person is renamed. Anything else - the common
	 * storage of the portal above all - belongs to nobody in particular and is answered by its own
	 * name with no owner beside it.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, array{id: int, name: string, userId: int}> keyed by the storage
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function readStorageOwners(array $rows): array
	{
		$storageIds = [];
		foreach ($rows as $row)
		{
			$storageIds[(int)$row['STORAGE_ID']] = true;
		}
		unset($storageIds[0]);
		if ($storageIds === [])
		{
			return [];
		}

		$owners = [];
		foreach (array_chunk(array_keys($storageIds), self::IDS_PER_QUERY) as $chunk)
		{
			$cursor = StorageTable::getList([
				'select' => ['ID', 'NAME', 'ENTITY_TYPE', 'ENTITY_ID'],
				'filter' => Query::filter()->whereIn('ID', $chunk),
			]);

			while ($row = $cursor->fetch())
			{
				$entityType = ltrim((string)$row['ENTITY_TYPE'], '\\');
				$isUser = $entityType === ProxyType\User::class;
				$isGroup = $entityType === ProxyType\Group::class;

				$owners[(int)$row['ID']] = [
					'id' => $isUser || $isGroup ? (int)$row['ENTITY_ID'] : 0,
					'name' => (string)$row['NAME'],
					'userId' => $isUser ? (int)$row['ENTITY_ID'] : 0,
				];
			}
		}

		return $owners;
	}

	/**
	 * The people the cards of the page name, read in one statement.
	 *
	 * Owners of personal storages are read together with the authors: they are people of the same
	 * kind, and splitting them would only cost a second query over the same table.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @param array<int, array{id: int, name: string, userId: int}> $owners
	 * @return array<int, string> display name, keyed by the user
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function readParties(array $rows, array $owners): array
	{
		$userIds = [];
		foreach ($rows as $row)
		{
			$userIds[(int)$row['CREATED_BY']] = true;
			$userIds[(int)$row['UPDATED_BY']] = true;
		}
		foreach ($owners as $owner)
		{
			$userIds[$owner['userId']] = true;
		}
		unset($userIds[0]);
		if ($userIds === [])
		{
			return [];
		}

		$format = \CSite::getNameFormat();

		$names = [];
		foreach (array_chunk(array_keys($userIds), self::IDS_PER_QUERY) as $chunk)
		{
			$cursor = UserTable::getList([
				'select' => ['ID', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'LOGIN', 'EMAIL'],
				'filter' => Query::filter()->whereIn('ID', $chunk),
			]);

			while ($row = $cursor->fetch())
			{
				$names[(int)$row['ID']] = (string)\CUser::formatName($format, $row, true, false);
			}
		}

		return $names;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<int, array{id: int, number: int}> $versions
	 * @param array<int, true> $available
	 * @param array<int, array{id: int, name: string, userId: int}> $owners
	 * @param array<int, string> $parties
	 */
	private function fillMetadata(
		ObjectCardDto $card,
		array $row,
		array $versions,
		array $available,
		array $owners,
		array $parties,
	): void
	{
		$name = (string)$row['NAME'];

		$card->name = $name;
		if ($card->type === ObjectCardDto::TYPE_FILE)
		{
			$version = $versions[$card->objectId] ?? null;

			$card->extension = (string)getFileExtension($name);
			$card->mimeType = self::mimeTypeOf($name, (string)($row['CONTENT_TYPE'] ?? ''));
			$card->size = (int)$row['SIZE'];
			// A file whose stored versions were never written has no version to name, and the field is
			// left out rather than answered with a zero a client would try to fetch by. The number of
			// the version is a counter of the object itself and is true of such a file all the same.
			if ($version !== null)
			{
				$card->versionId = $version['id'];
			}
			$card->versionNumber = $version['number'] ?? (int)$row['GLOBAL_CONTENT_VERSION'];
		}

		$card->createdAt = $row['CREATE_TIME'];
		// An object written before the column existed carries no moment of change of its own, and the
		// moment it appeared is the last one that is true of it.
		$card->updatedAt = $row['UPDATE_TIME'] ?? $row['CREATE_TIME'];
		$card->createdBy = self::person((int)$row['CREATED_BY'], $parties);
		$card->updatedBy = self::person((int)$row['UPDATED_BY'], $parties);
		$card->storageOwner = self::storageOwner($owners[(int)$row['STORAGE_ID']] ?? null, $parties);

		if ($card->type === ObjectCardDto::TYPE_FILE)
		{
			$card->contentAvailable = isset($available[$card->objectId]);
			if ($card->contentAvailable)
			{
				$card->downloadId = $this->contentAvailability->downloadIdOf($card->objectId);
			}
		}
	}

	/**
	 * The type the stored content declares, unless it declares nothing in particular: a file uploaded
	 * by a client that named no type at all is described by its own name instead of being handed to
	 * the client as a stream of bytes of unknown kind.
	 */
	private static function mimeTypeOf(string $name, string $storedContentType): string
	{
		$stored = MimeType::normalize($storedContentType);

		return $stored === self::GENERIC_MIME_TYPE ? MimeType::getByFilename($name) : $stored;
	}

	/**
	 * @param array<int, string> $parties
	 */
	private static function person(int $userId, array $parties): EntityRefDto
	{
		$person = new EntityRefDto();
		$person->id = $userId;
		$person->name = $parties[$userId] ?? '';

		return $person;
	}

	/**
	 * @param array{id: int, name: string, userId: int}|null $owner
	 * @param array<int, string> $parties
	 */
	private static function storageOwner(?array $owner, array $parties): EntityRefDto
	{
		if ($owner === null)
		{
			return self::person(0, $parties);
		}

		$reference = new EntityRefDto();
		$reference->id = $owner['id'];
		$reference->name = $owner['userId'] > 0
			? ($parties[$owner['userId']] ?? $owner['name'])
			: $owner['name']
		;

		return $reference;
	}

	private static function typeOf(int $type): ?string
	{
		return match ($type)
		{
			ObjectTable::TYPE_FILE => ObjectCardDto::TYPE_FILE,
			ObjectTable::TYPE_FOLDER => ObjectCardDto::TYPE_FOLDER,
			default => null,
		};
	}

	/**
	 * The objects of the page the question of an expected place is asked about at all.
	 *
	 * An object that already lies somewhere the reader sees is not among them: it is answered false
	 * without a statement, and "will a place appear" is not a question a client has about it.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return int[]
	 */
	private static function withoutPlaces(array $rows, PlaceSet $placeSet): array
	{
		$objectIds = [];
		foreach (array_keys($rows) as $objectId)
		{
			if ($placeSet->getPlacesOf($objectId) === [])
			{
				$objectIds[] = $objectId;
			}
		}

		return $objectIds;
	}

	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	private static function normaliseIds(array $ids): array
	{
		$normalised = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$normalised[$id] = $id;
			}
		}

		return array_values($normalised);
	}
}
