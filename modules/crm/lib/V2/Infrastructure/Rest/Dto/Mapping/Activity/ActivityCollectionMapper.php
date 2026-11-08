<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Activity;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Activity\ActivityBindingDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Activity\ActivityCommunicationDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Activity\ActivityDto;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityCommunication;
use Bitrix\Crm\V2\Internal\Entity\Activity\ValueObject\ActivityFile;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Realisation\Dto\FileDto;

/**
 * Assembles the collections of {@see ActivityDto} out of what the read scenario has already loaded.
 *
 * Loads nothing itself - every collection is read off the model or handed over by the caller, each of them
 * loaded for a whole set of activities at once elsewhere. The single-valued fields are answered by
 * {@see ActivityDtoMapper}, which fills the same DTO before this one is called.
 *
 * A collection the model does not carry is left off the DTO entirely, while a collection that was loaded
 * and holds nothing is answered as the empty list it is.
 *
 * The files come from outside the model: it keeps them as the identifiers a write stores
 * ({@see \Bitrix\Crm\V2\Internal\Entity\Activity\ActivityStorageElement}), so the metadata
 * ({@see ActivityFile}) is read by the scenario and passed in.
 */
final class ActivityCollectionMapper
{
	private const BINDINGS = 'bindings';
	private const COMMUNICATIONS = 'communications';
	private const FILES = 'files';

	private const ANSWERED_BY_DEFAULT = [
		self::BINDINGS,
		self::COMMUNICATIONS,
		self::FILES,
	];

	/**
	 * @param ActivityFile[]|null $files metadata of the files of the activity, null where none was read.
	 * @param string[] $select names of the published fields the client asked for; an empty one means the
	 *        set the contract answers by default.
	 * @param array<string, string[]> $nested the fields asked of the elements of each collection, by its name;
	 *        a collection missing here is answered with every field of its elements.
	 */
	public function fill(
		ActivityDto $dto,
		Activity $activity,
		?array $files,
		array $select = [],
		array $nested = [],
	): void
	{
		$selected = array_fill_keys($select === [] ? self::ANSWERED_BY_DEFAULT : $select, true);

		$bindings = $activity->getBindings();
		if (isset($selected[self::BINDINGS]) && $bindings !== null)
		{
			$dto->bindings = self::bindingsOf($bindings, $nested[self::BINDINGS] ?? null);
		}

		$communications = $activity->getCommunications();
		if (isset($selected[self::COMMUNICATIONS]) && $communications !== null)
		{
			$dto->communications = self::communicationsOf($communications, $nested[self::COMMUNICATIONS] ?? null);
		}

		if (isset($selected[self::FILES]) && $files !== null)
		{
			$dto->files = self::filesOf($files, $nested[self::FILES] ?? null);
		}
	}

	/**
	 * The order stays the one the activity carries; nothing here sorts or deduplicates.
	 *
	 * @param ActivityBinding[] $bindings
	 * @param string[]|null $fields
	 */
	private static function bindingsOf(array $bindings, ?array $fields): DtoCollection
	{
		$collection = new DtoCollection(ActivityBindingDto::class);
		foreach ($bindings as $binding)
		{
			$element = new ActivityBindingDto();
			NestedFieldProjection::assign($element, [
				'entityTypeId' => $binding->getOwnerType()->getId(),
				'entityId' => $binding->getOwnerId(),
			], $fields);

			$collection->add($element);
		}

		return $collection;
	}

	/**
	 * The channel is always answered and the rest is not: a CRM object addressed by the undefined type and a
	 * zero is no object, and a value the masking of the read scenario has emptied is no address. Both reach
	 * a client as a null while the element itself stays.
	 *
	 * @param ActivityCommunication[] $communications
	 * @param string[]|null $fields
	 */
	private static function communicationsOf(array $communications, ?array $fields): DtoCollection
	{
		$collection = new DtoCollection(ActivityCommunicationDto::class);
		foreach ($communications as $communication)
		{
			$entityTypeId = $communication->getEntityType()->getId();
			$entityId = $communication->getEntityId();
			$hasEntity = $entityTypeId > 0 && $entityId > 0;

			$element = new ActivityCommunicationDto();
			NestedFieldProjection::assign($element, [
				'type' => $communication->getType(),
				'value' => $communication->getValue() === '' ? null : $communication->getValue(),
				'entityTypeId' => $hasEntity ? $entityTypeId : null,
				'entityId' => $hasEntity ? $entityId : null,
			], $fields);

			$collection->add($element);
		}

		return $collection;
	}

	/**
	 * @param ActivityFile[] $files
	 * @param string[]|null $fields
	 */
	private static function filesOf(array $files, ?array $fields): DtoCollection
	{
		$collection = new DtoCollection(FileDto::class);
		foreach ($files as $file)
		{
			$element = new FileDto();
			NestedFieldProjection::assign($element, [
				'id' => (string)$file->getId(),
				'name' => $file->getName(),
				'url' => $file->getUrl(),
				'downloadUrl' => $file->getDownloadUrl(),
			], $fields);

			$collection->add($element);
		}

		return $collection;
	}
}
