<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item;

use Bitrix\Crm\V2\Public\Entity\Item\CustomField\CustomFieldData;
use Bitrix\Crm\V2\Public\Entity\User\Employee;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;

/**
 * Abstract base class for all CRM Item entities.
 * Pure data object — no ORM, no persistence logic.
 * Used as input for Commands and output from Providers.
 */
abstract class Item implements EntityInterface
{
	// Field name constants — base fields
	public const id = 'id';
	public const createdTime = 'createdTime';
	public const updatedTime = 'updatedTime';
	public const createdById = 'createdById';
	public const createdBy = 'createdBy';
	public const updatedById = 'updatedById';
	public const updatedBy = 'updatedBy';
	public const lastActivityTime = 'lastActivityTime';
	public const lastActivityById = 'lastActivityById';
	public const lastActivityBy = 'lastActivityBy';
	public const title = 'title';
	public const xmlId = 'xmlId';
	public const assignedById = 'assignedById';
	public const assignedBy = 'assignedBy';
	public const opened = 'opened';
	public const sourceId = 'sourceId';
	public const sourceDescription = 'sourceDescription';
	public const comments = 'comments';
	public const webformId = 'webformId';
	public const originatorId = 'originatorId';
	public const originId = 'originId';
	public const originVersion = 'originVersion';
	public const observers = 'observers';
	public const observersEmployees = 'observersEmployees';
	public const utm = 'utm';
	public const source = 'source';
	public const webform = 'webform';
	public const lastCommunication = 'lastCommunication';
	public const trackingSourceId = 'trackingSourceId';

	// Field name constants — HasStages trait
	public const stageId = 'stageId';
	public const stageSemanticId = 'stageSemanticId';
	public const movedTime = 'movedTime';
	public const movedById = 'movedById';
	public const movedBy = 'movedBy';
	public const closed = 'closed';
	public const beginDate = 'beginDate';
	public const closeDate = 'closeDate';

	// Field name constants — HasCategories trait
	public const categoryId = 'categoryId';

	// Field name constants — HasProducts trait
	public const opportunity = 'opportunity';
	public const isManualOpportunity = 'isManualOpportunity';
	public const taxValue = 'taxValue';
	public const currencyId = 'currencyId';
	public const exchRate = 'exchRate';
	public const opportunityAccount = 'opportunityAccount';
	public const taxValueAccount = 'taxValueAccount';
	public const accountCurrencyId = 'accountCurrencyId';
	public const productRows = 'productRows';

	// Field name constants — HasContactBindings trait
	public const contactBindings = 'contactBindings';

	// Field name constants — HasCompanyBindings trait
	public const companyBindings = 'companyBindings';

	// Field name constants — HasCompany trait
	public const companyId = 'companyId';

	// Field name constants — HasMultifields trait
	public const phones = 'phones';
	public const emails = 'emails';
	public const webs = 'webs';
	public const ims = 'ims';
	public const hasPhone = 'hasPhone';
	public const hasEmail = 'hasEmail';
	public const hasImol = 'hasImol';

	// Field name constants — HasMyCompany trait
	public const myCompanyId = 'myCompanyId';

	private array $changedFields = [];
	private bool $restricted = false;
	private bool $baseFieldsReadable = false;

	// Identity (read-only, set by Mapper/Handler)
	private ?int $id = null;

	// Audit (read-only)
	private ?DateTime $createdTime = null;
	private ?DateTime $updatedTime = null;
	private ?int $createdById = null;
	private ?int $updatedById = null;
	private ?DateTime $lastActivityTime = null;
	private ?int $lastActivityById = null;

	// Core writable fields
	private ?string $title = null;
	private ?string $xmlId = null;
	private ?int $assignedById = null;
	private ?bool $opened = null;
	private ?string $sourceId = null;
	private ?string $sourceDescription = null;
	private ?string $comments = null;
	private ?int $webformId = null;
	private ?string $originatorId = null;
	private ?string $originId = null;
	private ?string $originVersion = null;

	// Composite fields
	private ?Employee $createdBy = null;
	private ?Employee $updatedBy = null;
	private ?Employee $assignedBy = null;
	private ?Employee $lastActivityBy = null;
	private ?array $observers = null;
	private ?array $observersEmployees = null;
	private ?Utm $utm = null;
	private ?Source $source = null;
	private ?Webform $webform = null;
	private ?LastCommunication $lastCommunication = null;
	// The source of the trace of the last binding of the item, loaded on demand
	private ?int $trackingSourceId = null;

	// Custom fields (UF_*)
	private array $customFields = [];
	private array $customFieldData = [];

	// Parent fields
	private array $parentIds = [];
	/** @var array<int, Item|null> */
	private array $relatedItems = [];

	// --- EntityType ---

	abstract public function getEntityType(): EntityType;

	// --- Caption ---

	/**
	 * Human-readable caption for the entity (used in UI as the visible label).
	 * Contact overrides {@see buildCaption()} to use getFullName(); restricted items return
	 * a localized placeholder via {@see getRestrictedCaption()}.
	 */
	public function getCaption(): string
	{
		if (!$this->canRead() && !$this->baseFieldsReadable)
		{
			return $this->getRestrictedCaption();
		}

		return $this->buildCaption();
	}

	protected function buildCaption(): string
	{
		return $this->title ?? '';
	}

	protected function getRestrictedCaption(): string
	{
		return (string)\CCrmViewHelper::GetHiddenEntityCaption($this->getEntityType()->getId());
	}

	// --- canRead ---

	public function canRead(): bool
	{
		return !$this->restricted;
	}

	/**
	 * @internal Used by Provider to mark item as restricted (no read access).
	 */
	public function markAsRestricted(): void
	{
		$this->restricted = true;
	}

	/**
	 * @internal Used by Provider when only base fields are readable.
	 */
	public function markAsRestrictedWithBaseFields(): void
	{
		$this->restricted = true;
		$this->baseFieldsReadable = true;
	}

	// --- Changed-tracking ---

	/** @return string[]
	 * @internal
	 */
	public function getChangedFieldNames(): array
	{
		$changed = $this->changedFields;

		// Utm is a mutable composite: an in-place mutation (getUtm()->setSource(...)) does not go
		// through setUtm(), so it must be surfaced here to reach the partial-update diff.
		if (!isset($changed[self::utm]) && ($this->utm?->isChanged() ?? false))
		{
			$changed[self::utm] = true;
		}

		return array_keys($changed);
	}

	/**
	 * @return bool
	 * @internal
	 */
	public function hasChangedFields(): bool
	{
		return !empty($this->changedFields) || ($this->utm?->isChanged() ?? false);
	}

	/**
	 * @return void
	 * @internal
	 */
	public function resetChangedFields(): void
	{
		$this->changedFields = [];
		// Cascade to nested mutable state that keeps its own changed-tracking.
		$this->utm?->resetChanged();
	}

	/**
	 * Deep-copy nested mutable state so clones do not alias the original.
	 *
	 * Composite fields (Utm) and collections (product rows, multifields, bindings) are objects and
	 * would otherwise be shared by reference after a shallow clone. Collection properties are composed
	 * from traits and therefore private to the concrete subclass — unreachable from this base scope —
	 * so reflection is used to re-clone every mutable object-valued property uniformly.
	 *
	 * Collections need one extra level: {@see EntityCollection} clones shallowly, so its
	 * members are re-cloned here too — otherwise a cloned Item would still share its (mutable)
	 * product rows with the original.
	 */
	public function __clone(): void
	{
		// Walk the whole hierarchy: ReflectionObject::getProperties() omits private properties
		// declared in parent classes (e.g. Item::$utm), so iterate each level and take only the
		// properties declared there.
		for ($class = new \ReflectionObject($this); $class !== false; $class = $class->getParentClass())
		{
			foreach ($class->getProperties() as $property)
			{
				if (
					$property->isReadOnly()
					|| $property->isStatic()
					|| $property->getDeclaringClass()->getName() !== $class->getName()
				)
				{
					continue;
				}

				$value = $property->getValue($this);
				if (is_object($value))
				{
					$property->setValue($this, self::cloneValueDeep($value));
				}
			}
		}
	}

	private static function cloneValueDeep(object $value): object
	{
		if ($value instanceof EntityCollection)
		{
			$members = [];
			foreach ($value as $member)
			{
				$members[] = clone $member;
			}

			$collectionClass = get_class($value);

			return new $collectionClass(...$members);
		}

		return clone $value;
	}

	protected function markChanged(string $fieldName): void
	{
		$this->changedFields[$fieldName] = true;
	}

	// --- Read-only fields (getters only, populated via internalSet) ---

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(int $id): static
	{
		$this->id = $id;

		return $this;
	}

	public function getCreatedTime(): ?DateTime
	{
		return $this->createdTime;
	}

	public function getUpdatedTime(): ?DateTime
	{
		return $this->updatedTime;
	}

	public function getCreatedById(): ?int
	{
		return $this->createdById;
	}

	public function getCreatedBy(): ?Employee
	{
		return $this->createdBy;
	}

	public function getUpdatedById(): ?int
	{
		return $this->updatedById;
	}

	public function getUpdatedBy(): ?Employee
	{
		return $this->updatedBy;
	}

	public function getLastActivityTime(): ?DateTime
	{
		return $this->lastActivityTime;
	}

	public function getLastActivityById(): ?int
	{
		return $this->lastActivityById;
	}

	public function getLastActivityBy(): ?Employee
	{
		return $this->lastActivityBy;
	}

	// --- Core writable fields ---

	public function getTitle(): ?string
	{
		return $this->title;
	}

	public function setTitle(?string $title): static
	{
		$this->title = $title;
		$this->markChanged(self::title);

		return $this;
	}

	public function getXmlId(): ?string
	{
		return $this->xmlId;
	}

	public function setXmlId(?string $xmlId): static
	{
		$this->xmlId = $xmlId;
		$this->markChanged(self::xmlId);

		return $this;
	}

	public function getAssignedById(): ?int
	{
		return $this->assignedById;
	}

	public function setAssignedById(?int $assignedById): static
	{
		$this->assignedById = $assignedById;
		$this->markChanged(self::assignedById);

		return $this;
	}

	public function getAssignedBy(): ?Employee
	{
		return $this->assignedBy;
	}

	public function getOpened(): ?bool
	{
		return $this->opened;
	}

	public function setOpened(?bool $opened): static
	{
		$this->opened = $opened;
		$this->markChanged(self::opened);

		return $this;
	}

	public function getSourceId(): ?string
	{
		return $this->sourceId;
	}

	public function setSourceId(?string $sourceId): static
	{
		$this->sourceId = $sourceId;
		$this->markChanged(self::sourceId);

		return $this;
	}

	public function getSourceDescription(): ?string
	{
		return $this->sourceDescription;
	}

	public function setSourceDescription(?string $sourceDescription): static
	{
		$this->sourceDescription = $sourceDescription;
		$this->markChanged(self::sourceDescription);

		return $this;
	}

	public function getComments(): ?string
	{
		return $this->comments;
	}

	public function setComments(?string $comments): static
	{
		$this->comments = $comments;
		$this->markChanged(self::comments);

		return $this;
	}

	public function getWebformId(): ?int
	{
		return $this->webformId;
	}

	public function setWebformId(?int $webformId): static
	{
		$this->webformId = $webformId;
		$this->markChanged(self::webformId);

		return $this;
	}

	public function getOriginatorId(): ?string
	{
		return $this->originatorId;
	}

	public function setOriginatorId(?string $originatorId): static
	{
		$this->originatorId = $originatorId;
		$this->markChanged(self::originatorId);

		return $this;
	}

	public function getOriginId(): ?string
	{
		return $this->originId;
	}

	public function setOriginId(?string $originId): static
	{
		$this->originId = $originId;
		$this->markChanged(self::originId);

		return $this;
	}

	public function getOriginVersion(): ?string
	{
		return $this->originVersion;
	}

	public function setOriginVersion(?string $originVersion): static
	{
		$this->originVersion = $originVersion;
		$this->markChanged(self::originVersion);

		return $this;
	}

	// --- Composite fields ---

	/** @return int[]|null */
	public function getObservers(): ?array
	{
		return $this->observers;
	}

	/** @return Employee[]|null */
	public function getObserversEmployees(): ?array
	{
		return $this->observersEmployees;
	}

	/** @param int[] $observers */
	public function setObservers(array $observers): static
	{
		$this->observers = $observers;
		$this->markChanged(self::observers);

		return $this;
	}

	public function getUtm(): ?Utm
	{
		return $this->utm;
	}

	public function setUtm(?Utm $utm): static
	{
		$this->utm = $utm;
		$this->markChanged(self::utm);

		return $this;
	}

	public function getTrackingSourceId(): ?int
	{
		return $this->trackingSourceId;
	}

	public function setTrackingSourceId(?int $trackingSourceId): static
	{
		$this->trackingSourceId = $trackingSourceId;
		$this->markChanged(self::trackingSourceId);

		return $this;
	}

	// --- Custom fields (UF_*) ---

	public function getCustomField(string $name): mixed
	{
		return $this->customFields[$name] ?? null;
	}

	public function setCustomField(string $name, mixed $value): static
	{
		$this->customFields[$name] = $value;
		$this->markChanged($name);

		return $this;
	}

	/** @return array<string, mixed> */
	public function getCustomFields(): array
	{
		return $this->customFields;
	}

	public function getCustomFieldData(string $name): CustomFieldData|array|null
	{
		return $this->customFieldData[$name] ?? null;
	}

	public function internalSetCustomFieldData(string $name, CustomFieldData|array $data): static
	{
		$this->customFieldData[$name] = $data;

		return $this;
	}

	/**
	 * @internal Drops the listed custom fields from the bag. Used by the Provider to apply per-user
	 * UF visibility on the copy handed back, without touching change tracking. Not for client code.
	 *
	 * @param string[] $names
	 */
	public function internalRemoveCustomFields(array $names): static
	{
		foreach ($names as $name)
		{
			unset($this->customFields[$name], $this->customFieldData[$name]);
		}

		return $this;
	}

	// --- Parent fields ---

	public function getParentId(EntityType $entityType): ?int
	{
		return $this->parentIds[$entityType->getId()] ?? null;
	}

	/**
	 * Sets (or, with a null $id, detaches) the parent of the given type. Detach mirrors the
	 * file-field "null = no value" contract: `null` removes the binding. "Not set" and "explicitly
	 * detached" are told apart by change-tracking (the `parentId_<typeId>` mark is set on any
	 * explicit call), not by the stored value - a detached parent leaves no entry in the bag, so
	 * {@see getParentId()} returns null for both, while {@see getChangedFieldNames()} surfaces the
	 * detach to the write mapper.
	 */
	public function setParentId(EntityType $entityType, ?int $id): static
	{
		if ($id === null)
		{
			unset($this->parentIds[$entityType->getId()]);
		}
		else
		{
			$this->parentIds[$entityType->getId()] = $id;
		}

		$this->markChanged('parentId_' . $entityType->getId());

		return $this;
	}

	/**
	 * Sugar for {@see setParentId()} with a null id - detaches the parent of the given type.
	 */
	public function removeParent(EntityType $entityType): static
	{
		return $this->setParentId($entityType, null);
	}

	/** @return array<int, int> entityTypeId => parentEntityId */
	public function getParentIds(): array
	{
		return $this->parentIds;
	}

	public function getSource(): ?Source
	{
		return $this->source;
	}

	public function getWebform(): ?Webform
	{
		return $this->webform;
	}

	public function getLastCommunication(): ?LastCommunication
	{
		return $this->lastCommunication;
	}

	public function getRelatedItem(EntityType $entityType): ?Item
	{
		return $this->relatedItems[$entityType->getId()] ?? null;
	}

	// --- internalSet ---

	/**
	 * @internal Used by Mapper/Handler to set any field without triggering change tracking.
	 * Not for client code - use typed setters instead.
	 */
	public function internalSet(string $fieldName, mixed $value): static
	{
		// UF_* user fields go into the customFields bag without marking as changed.
		if (str_starts_with($fieldName, 'UF_'))
		{
			$this->customFields[$fieldName] = $value;

			return $this;
		}

		// Parent hydration path (ParentLoader): 'parentId_<typeId>' -> parentIds bag, no change mark.
		// A null value is never written here - this path only carries loaded bindings; detaching a
		// parent goes through the public setParentId(type, null), which does mark change-tracking.
		if (str_starts_with($fieldName, 'parentId_'))
		{
			if ($value !== null)
			{
				$this->parentIds[(int)substr($fieldName, strlen('parentId_'))] = (int)$value;
			}

			return $this;
		}

		if (str_starts_with($fieldName, 'related'))
		{
			$entityType = EntityType::fromCode(substr($fieldName, strlen('related')));
			if ($entityType !== null)
			{
				$this->relatedItems[$entityType->getId()] = $value;

				return $this;
			}
		}

		match ($fieldName)
		{
			// Read-only fields
			self::id => $this->id = $value,
			self::createdTime => $this->createdTime = $value,
			self::updatedTime => $this->updatedTime = $value,
			self::createdById => $this->createdById = $value,
			self::createdBy => $this->createdBy = $value,
			self::updatedById => $this->updatedById = $value,
			self::updatedBy => $this->updatedBy = $value,
			// Writable fields (same as setters but without change tracking)
			self::title => $this->title = $value,
			self::xmlId => $this->xmlId = $value,
			self::assignedById => $this->assignedById = $value,
			self::assignedBy => $this->assignedBy = $value,
			self::opened => $this->opened = $value,
			self::sourceId => $this->sourceId = $value,
			self::sourceDescription => $this->sourceDescription = $value,
			self::comments => $this->comments = $value,
			self::webformId => $this->webformId = $value,
			self::originatorId => $this->originatorId = $value,
			self::originId => $this->originId = $value,
			self::originVersion => $this->originVersion = $value,
			self::observers => $this->observers = $value,
			self::observersEmployees => $this->observersEmployees = $value,
			self::utm => $this->utm = $value,
			self::source => $this->source = $value,
			self::webform => $this->webform = $value,
			self::lastCommunication => $this->lastCommunication = $value,
			self::trackingSourceId => $this->trackingSourceId = $value,
			self::lastActivityTime => $this->lastActivityTime = $value,
			self::lastActivityById => $this->lastActivityById = $value,
			self::lastActivityBy => $this->lastActivityBy = $value,
			default => throw new \Bitrix\Main\ArgumentException("Unknown field: {$fieldName}"),
		};

		return $this;
	}
}
