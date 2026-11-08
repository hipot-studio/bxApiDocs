<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Ai;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Repository\Ai\FieldFillExclusionRepository;
use Bitrix\Crm\V2\Internal\Service\Item\CustomFieldRegistry;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Application;
use Bitrix\Main\Data\ManagedCache;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\UserField\Types\DateType;
use Bitrix\Main\UserField\Types\DoubleType;
use Bitrix\Main\UserField\Types\EnumType;
use Bitrix\Main\UserField\Types\IntegerType;
use Bitrix\Main\UserField\Types\StringType;

/**
 * The single point the module reads and changes the "may BitrixGPT fill this user field" policy
 * through.
 *
 * The storage is sparse - a row forbids one field, its absence allows it - so the answer for a whole
 * entity costs one query over the ids the entity's UF metadata names, and is kept between hits per
 * entity type. Adding a field never invalidates anything: a field nobody forbade is allowed by the
 * same absence that answered before it appeared. Forbidding and allowing a field do invalidate, each
 * only the type it belongs to, and so does deleting a field that was forbidden - deleting one that was
 * not leaves nothing to invalidate.
 *
 * The policy answers whether filling is permitted at all - never what the value should be, whether
 * the reader may see the field or whether the field is mandatory. Those belong to their own layers,
 * and it does not write through any of them: it stores the permission, and the assistant tools,
 * robots and other writers read it.
 *
 * @internal
 */
final class FieldFillPolicyService
{
	private const CACHE_TTL = 86400;
	private const CACHE_DIR = 'crm/ai_field_fill';
	private const CACHE_KEY_PREFIX = 'crm_ai_field_fill_exclusion_';

	/**
	 * The cached names travel inside an envelope so that "nothing is forbidden here" survives the round
	 * trip as an answer of its own: {@see \Bitrix\Main\Data\ManagedCache::get()} reports a miss with
	 * false, and an empty list must not be read as one.
	 */
	private const CACHE_PAYLOAD_KEY = 'disabledFieldNames';

	private static ?self $instance = null;

	private readonly FieldFillExclusionRepository $repository;
	private readonly CustomFieldRegistry $customFieldRegistry;
	private readonly ManagedCache $cache;

	public function __construct(
		?FieldFillExclusionRepository $repository = null,
		?CustomFieldRegistry $customFieldRegistry = null,
		?ManagedCache $cache = null,
	)
	{
		$this->repository = $repository ?? new FieldFillExclusionRepository();
		$this->customFieldRegistry = $customFieldRegistry ?? CustomFieldRegistry::getInstance();
		$this->cache = $cache ?? Application::getInstance()->getManagedCache();
	}

	public static function getInstance(): self
	{
		return self::$instance ??= new self();
	}

	/**
	 * Names (`UF_CRM_*`) of the entity's user fields BitrixGPT must not fill. Empty for an entity type
	 * that has no user fields at all, and for one where every field is left allowed.
	 *
	 * @return string[]
	 */
	public function getDisabledFieldNames(int $entityTypeId): array
	{
		$cacheKey = $this->getCacheKey($entityTypeId);

		if ($this->cache->read(self::CACHE_TTL, $cacheKey, self::CACHE_DIR))
		{
			$cached = $this->cache->get($cacheKey);
			if (is_array($cached) && is_array($cached[self::CACHE_PAYLOAD_KEY] ?? null))
			{
				return $cached[self::CACHE_PAYLOAD_KEY];
			}
		}

		$disabledFieldNames = $this->fetchDisabledFieldNames($entityTypeId);
		$this->cache->set($cacheKey, [self::CACHE_PAYLOAD_KEY => $disabledFieldNames]);

		return $disabledFieldNames;
	}

	/**
	 * The same answer as {@see self::getDisabledFieldNames()} shaped as a set: a caller walking the fields
	 * of an entity takes it once and asks it per field instead of scanning the list every time.
	 *
	 * @return array<string, true>
	 */
	public function getDisabledFieldNameMap(int $entityTypeId): array
	{
		return array_fill_keys($this->getDisabledFieldNames($entityTypeId), true);
	}

	/**
	 * A field nobody forbade is allowed, including a name this entity type does not know.
	 */
	public function isFillAllowed(int $entityTypeId, string $fieldName): bool
	{
		$disabledFieldNameMap = $this->getDisabledFieldNameMap($entityTypeId);

		return !isset($disabledFieldNameMap[$fieldName]);
	}

	/**
	 * Forbids or allows filling one user field of the entity type. A name the entity type does not have
	 * is refused with {@see ErrorCode::NOT_FOUND} - the same code
	 * {@see \Bitrix\Crm\Controller\AiFieldFillPolicy::saveAction()} answers that situation with, so one
	 * refusal cannot reach a client under two different codes. That route checks the name before getting
	 * here and carries the phrase shown to the user; the text below is for the calling code.
	 */
	public function setFillEnabled(int $entityTypeId, string $fieldName, bool $isEnabled): Result
	{
		$result = new Result();

		$descriptor = $this->getDescriptorsByName($entityTypeId)[$fieldName] ?? null;
		if ($descriptor === null)
		{
			return $result->addError(new Error(
				"User field is unknown for entity type {$entityTypeId}.",
				ErrorCode::NOT_FOUND,
				['field' => $fieldName],
			));
		}

		$writeResult = $isEnabled
			? $this->repository->delete($descriptor->id)
			: $this->repository->add($descriptor->id)
		;
		if (!$writeResult->isSuccess())
		{
			return $result->addErrors($writeResult->getErrors());
		}

		$this->invalidateCache($entityTypeId);

		return $result;
	}

	/**
	 * Drops the policy of a user field that is gone. The entity type comes from the caller because the
	 * storage does not keep it while the cache is laid out by it, and the deletion event knows it.
	 *
	 * A field nobody forbade - what most of the fields reaching here are, and every field of an entity
	 * whose type is being deleted - costs one read: there is no row to delete and no answer to drop.
	 */
	public function clearByUserFieldId(int $userFieldId, int $entityTypeId): void
	{
		if (!$this->repository->exists($userFieldId))
		{
			return;
		}

		$this->repository->delete($userFieldId);
		$this->invalidateCache($entityTypeId);
	}

	/**
	 * Drops the policy of an entity type that is gone, called once the type is deleted. The user fields of
	 * a type are deleted along with it rather than one at a time, so {@see self::clearByUserFieldId()}
	 * never runs for them and no metadata is left to name the ids the type owned: the rows are recognised
	 * by their user field no longer existing at all, which takes the rows of a type deleted before this
	 * cleanup existed with them as well.
	 */
	public function clearByEntityTypeId(int $entityTypeId): void
	{
		$this->repository->deleteOrphaned();
		$this->invalidateCache($entityTypeId);
	}

	/**
	 * Whether the setting exists for the entity type at all: it does for Lead, Deal, Contact, Company,
	 * Quote and for a smart process the portal has, and it does not for the static entities running on
	 * the smart process engine (SmartInvoice, SmartDocument, SmartB2eDocument).
	 */
	public function isEntityTypeAvailable(int $entityTypeId): bool
	{
		$entityType = $this->createEntityType($entityTypeId);
		if ($entityType === null || $entityType->isSmartProcessBasedStaticEntity())
		{
			return false;
		}

		return !$entityType->isSmartProcess() || $this->smartProcessExists($entityTypeId);
	}

	/**
	 * User field types the setting is offered for - the ones BitrixGPT can fill. The single place the
	 * list lives: {@see \Bitrix\Crm\Entity\FieldDataProvider} builds the schema sent to BitrixGPT under
	 * {@see \Bitrix\Crm\Service\Context::SCOPE_AI} from it, so a type gained here is offered and sent as
	 * one change.
	 *
	 * @return string[]
	 */
	public function getSupportedFieldTypes(): array
	{
		return [
			StringType::USER_TYPE_ID,
			IntegerType::USER_TYPE_ID,
			DoubleType::USER_TYPE_ID,
			DateType::USER_TYPE_ID,
			EnumType::USER_TYPE_ID,
		];
	}

	/**
	 * @return array{available: bool, supportedTypes: string[]}
	 */
	public function getEditorSettings(int $entityTypeId): array
	{
		return [
			'available' => $this->isEntityTypeAvailable($entityTypeId),
			'supportedTypes' => $this->getSupportedFieldTypes(),
		];
	}

	/**
	 * Drops the answer kept for the entity type. The stored policy is left as it is: the next read
	 * builds the answer from the storage again.
	 */
	public function invalidateCache(int $entityTypeId): void
	{
		$this->cache->clean($this->getCacheKey($entityTypeId), self::CACHE_DIR);
	}

	/**
	 * @return string[]
	 */
	private function fetchDisabledFieldNames(int $entityTypeId): array
	{
		$fieldNamesById = [];
		foreach ($this->getDescriptorsByName($entityTypeId) as $descriptor)
		{
			$fieldNamesById[$descriptor->id] = $descriptor->name;
		}

		if ($fieldNamesById === [])
		{
			return [];
		}

		$disabledFieldNames = [];
		foreach ($this->repository->findExcludedUserFieldIds(array_keys($fieldNamesById)) as $userFieldId)
		{
			$disabledFieldNames[] = $fieldNamesById[$userFieldId];
		}

		return $disabledFieldNames;
	}

	/**
	 * @return array<string, \Bitrix\Crm\V2\Internal\Service\Item\CustomFieldDescriptor>
	 */
	private function getDescriptorsByName(int $entityTypeId): array
	{
		$entityType = $this->createEntityType($entityTypeId);

		return $entityType === null ? [] : $this->customFieldRegistry->getEntityDescriptorsMap($entityType);
	}

	private function createEntityType(int $entityTypeId): ?EntityType
	{
		return EntityType::isValid($entityTypeId) ? EntityType::fromId($entityTypeId) : null;
	}

	/**
	 * {@see EntityType::isValid()} knows only the range of ids a smart process may take, and an id of
	 * that range the portal has no type under names nothing. The type is looked up in the collection
	 * kept for the whole portal, so the answer costs no query of its own.
	 */
	private function smartProcessExists(int $entityTypeId): bool
	{
		return Container::getInstance()->getTypeByEntityTypeId($entityTypeId) !== null;
	}

	private function getCacheKey(int $entityTypeId): string
	{
		return self::CACHE_KEY_PREFIX . $entityTypeId;
	}
}
