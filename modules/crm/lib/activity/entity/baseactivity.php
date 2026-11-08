<?php

namespace Bitrix\Crm\Activity\Entity;

use Bitrix\Crm\Activity\CalendarEventEditPermissionChecker;
use Bitrix\Crm\Activity\Provider\Base as ActivityProvider;
use Bitrix\Crm\Activity\Provider\ToDo\OptionallyConfigurable;
use Bitrix\Crm\Activity\ToDo\ColorSettings\ColorSettingsProvider;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Integration\StorageType;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Context;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use CCrmActivity;
use CCrmDateTimeHelper;
use CCrmOwnerType;

class BaseActivity implements OptionallyConfigurable
{
	private const MAX_SUBJECT_LENGTH = 500;

	protected array $additionalFields = [];
	protected ?int $autocompleteRule = null;
	protected ?int $calendarEventId = null;
	protected bool $checkPermissions = true;
	protected ?string $colorId = null;
	protected string $completed;
	protected ?DateTime $deadline = null;
	protected string $description = '';
	protected ?int $id = null;
	protected ItemIdentifier $owner;
	protected ?int $parentActivityId = null;
	protected ActivityProvider $provider;
	private ?string $providerId = null;
	private ?string $providerTypeId = null;
	protected int $responsibleId;
	protected ?int $authorId = null;
	protected ?array $settings = null;
	protected ?array $storageElementIds = null;
	protected string $subject = '';
	protected ?Context $context = null;

	public function __construct(ItemIdentifier $owner, ActivityProvider $provider)
	{
		$this->owner = $owner;
		$this->provider = $provider;
		$this->responsibleId = Container::getInstance()->getContext()->getUserId();
	}

	public function getAdditionalFields(): array
	{
		return $this->additionalFields;
	}

	public function setAdditionalFields(array $fields): self
	{
		$this->additionalFields = $fields;

		return $this;
	}

	private function getAutocompleteRule(): ?int
	{
		return $this->autocompleteRule;
	}

	public function setAutocompleteRule(?int $autocompleteRule): self
	{
		$this->autocompleteRule = $autocompleteRule;

		return $this;
	}

	public function getCalendarEventId(): ?int
	{
		return $this->calendarEventId;
	}

	public function setCalendarEventId(?int $id): self
	{
		$this->calendarEventId = $id;

		return $this;
	}

	public function setCheckPermissions(bool $checkPermissions): self
	{
		$this->checkPermissions = $checkPermissions;

		return $this;
	}

	final public function getColorId(): ?string
	{
		return $this->colorId;
	}

	final public function setColorId(?string $colorId): self
	{
		$colorSettingsProvider = new ColorSettingsProvider();
		if ($colorSettingsProvider->isAvailableColorId($colorId))
		{
			$this->colorId = $colorId;
		}

		return $this;
	}

	private function setCompleted(string $completed): self
	{
		$this->completed = $completed;

		return $this;
	}

	public function isCompleted(): bool
	{
		return $this->completed === 'Y';
	}

	public function getDeadline(): ?DateTime
	{
		return $this->deadline;
	}

	public function setDeadline(?DateTime $deadline): self
	{
		$this->deadline = $deadline;

		return $this;
	}

	public function getDescription(): string
	{
		return $this->description;
	}

	public function setDescription(string $description): self
	{
		$this->description = $description;

		return $this;
	}

	public function setDefaultDescription(): static
	{
		$entityTypeId = $this->getOwner()->getEntityTypeId();
		return $this->setDescription(self::getDescriptionForEntityType($entityTypeId));
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getOwner(): ItemIdentifier
	{
		return $this->owner;
	}

	public function setOwner(ItemIdentifier $owner): self
	{
		$this->owner = $owner;

		return $this;
	}

	public function getParentActivityId(): ?int
	{
		return $this->parentActivityId;
	}

	public function setParentActivityId(?int $parentActivityId): self
	{
		$this->parentActivityId = $parentActivityId;

		return $this;
	}

	public function getProviderId(): string
	{
		return $this->providerId;
	}

	private function setProviderId(string $providerId): self
	{
		$this->providerId = $providerId;

		return $this;
	}

	public function isValidProviderId(string $providerId): bool
	{
		return false;
	}

	public function getProviderTypeId(): string
	{
		//return $this->providerTypeId;

		return 'BASE_ACTIVITY_PROVIDER_TYPE_ID';
	}

	public function getResponsibleId(): ?int
	{
		return $this->responsibleId;
	}

	public function setResponsibleId(int $responsibleId): self
	{
		$this->responsibleId = $responsibleId;

		return $this;
	}
	
	public function getAuthorId(): ?int
	{
		return $this->authorId;
	}

	public function setAuthorId(int $authorId): self
	{
		$this->authorId = $authorId;

		return $this;
	}

	public function getSettings(): ?array
	{
		return $this->settings;
	}

	public function setSettings(?array $settings): self
	{
		$this->settings = $settings;

		return $this;
	}

	public function getStorageElementIds(): ?array
	{
		return $this->storageElementIds;
	}

	/**
	 * @param string|int[] $storageElementIds
	 *
	 * @return $this
	 */
	public function setStorageElementIds($storageElementIds): self
	{
		if (is_string($storageElementIds))
		{
			$storageElementIds = unserialize($storageElementIds, ['allowed_classes' => false]);
		}

		if (is_array($storageElementIds))
		{
			$this->storageElementIds = $storageElementIds;
		}

		return $this;
	}

	public function getSubject(): string
	{
		return $this->subject;
	}

	public function setSubject(string $subject): self
	{
		$this->subject = $subject;

		return $this;
	}

	public function getDefaultSubject(): string
	{
		return Loc::getMessage('CRM_TODO_ENTITY_ACTIVITY_DEFAULT_SUBJECT');
	}

	public function setDefaultSubject(): self
	{
		return $this->setSubject($this->getDefaultSubject());
	}

	public static function getDescriptionForEntityType(int $entityTypeId): string
	{
		$defaultDescription = Loc::getMessage('CRM_TODO_ENTITY_ACTIVITY_DESCRIPTION_CONTACT_CLIENT') ?? '';
		if ($entityTypeId === CCrmOwnerType::Undefined)
		{
			return $defaultDescription;
		}

		$entityType = CCrmOwnerType::ResolveName($entityTypeId);

		return (
			Loc::getMessage('CRM_TODO_ENTITY_ACTIVITY_DESCRIPTION_CONTACT_CLIENT_IN_' . $entityType) ?? $defaultDescription
		);
	}

	public function createWithDefaultSubjectAndDescription(
		DateTime $deadline,
		bool $ceilDeadlineTime = true,
		bool $skipSubject = false
	): Result
	{
		if ($ceilDeadlineTime)
		{
			$deadline
				->setTime($deadline->format('H'), 0)
				->add('PT1H');
		}

		$activity = (new self($this->owner, $this->provider))
			->setDefaultDescription()
			->setDeadline($deadline);

		if (!$skipSubject)
		{
			$activity->setDefaultSubject();
		}

		return $activity->save();
	}

	final public function load(int $id): ?static
	{
		$filter = [
			'BINDINGS' => [
				[
					'OWNER_TYPE_ID' => $this->owner->getEntityTypeId(),
					'OWNER_ID' => $this->owner->getEntityId(),
				]
			],
			'=PROVIDER_ID' => $this->provider::getId(),
			'=ID' => $id
		];

		return $this->getInstanceByParams($filter);
	}

	public function loadNearest(): ?static
	{
		$filter = [
			'=COMPLETED' => 'N',
			'=PROVIDER_ID' => $this->getProviderId(),
			'=PROVIDER_TYPE_ID' => $this->getProviderTypeId(),
			'BINDINGS' => [
				[
					'OWNER_TYPE_ID' => $this->owner->getEntityTypeId(),
					'OWNER_ID' => $this->owner->getEntityId(),
				],
			],
		];

		$order = [
			'DEADLINE' => 'ASC',
		];

		$options = [
			'QUERY_OPTIONS' => [
				'LIMIT' => 1,
			],
		];

		return $this->getInstanceByParams($filter, $order, $options);
	}

	private function getInstanceByParams(array $filter, array $order = [], array $options = []): ?static
	{
		$data = CCrmActivity::GetList(
			$order,
			$filter,
			false,
			false,
			[
				'ID',
				'COMPLETED',
				'DEADLINE',
				'DESCRIPTION',
				'SUBJECT',
				'RESPONSIBLE_ID',
				'ASSOCIATED_ENTITY_ID',
				'AUTOCOMPLETE_RULE',
				'STORAGE_ELEMENT_IDS',
				'CALENDAR_EVENT_ID',
				'SETTINGS',
				'PROVIDER_ID',
			],
			$options
		)->Fetch();

		if (!$data)
		{
			return null;
		}

		$activity = new static($this->owner, $this->provider);
		$activity
			->setId((int)$data['ID'])
			->setDeadline(
				($data['DEADLINE'] && !CCrmDateTimeHelper::IsMaxDatabaseDate($data['DEADLINE']))
					? DateTime::createFromUserTime($data['DEADLINE'])
					: null
			)
			->setDescription((string)($data['DESCRIPTION'] ?? ''))
			->setSubject($data['SUBJECT'])
			->setResponsibleId($data['RESPONSIBLE_ID'])
			->setParentActivityId($data['ASSOCIATED_ENTITY_ID'] ?: null)
			->setAutocompleteRule($data['AUTOCOMPLETE_RULE'] ?: null)
			->setCompleted($data['COMPLETED'])
			->setCalendarEventId($data['CALENDAR_EVENT_ID'])
			->setStorageElementIds($data['STORAGE_ELEMENT_IDS'] ?: null)
			->setSettings($data['SETTINGS'] ?: null)
			->setProviderId($data['PROVIDER_ID'] ?: null);

		return $activity;
	}

	public function save(array $options = [], $useCurrentSettings = false): Result
	{
		$result = new Result();

		$fields = [
			'SUBJECT' => $this->getSubject(),
			'DESCRIPTION' => $this->getDescription(),
			'DESCRIPTION_TYPE' => \CCrmContentType::BBCode,
			'CALENDAR_EVENT_ID' => $this->getCalendarEventId(),
			'RESPONSIBLE_ID' => $this->getResponsibleId(),
			'AUTHOR_ID' => $this->getAuthorId() ?? $this->getResponsibleId(),
			'BINDINGS' => CCrmActivity::GetBindings($this->getId()),
		];

		if ($this->getDeadline())
		{
			$fields['START_TIME'] = $this->getDeadline()->toString();
			$fields['END_TIME'] = $this->getDeadline()->toString();
		}
		if (!is_null($this->getAutocompleteRule()))
		{
			$fields['AUTOCOMPLETE_RULE'] = $this->getAutocompleteRule();
		}

		$parentActivityBindings = [];
		if ($this->getParentActivityId())
		{
			if ($this->isParentActivityCompleted($this->getParentActivityId()))
			{
				$result->addError(new Error(Loc::getMessage('CRM_TODO_ENTITY_ACTIVITY_PARENT_ACTIVITY_RESTRICT'), 'ERROR_PARENT_ACTIVITY_RESTRICT'));

				return $result;
			}

			$parentActivityBindings = CCrmActivity::GetBindings($this->getParentActivityId());
			if ($this->isParentActivityValid($parentActivityBindings))
			{
				$fields['ASSOCIATED_ENTITY_ID'] = $this->getParentActivityId();
			}
		}

		if (is_array($this->getStorageElementIds()))
		{
			$fields['STORAGE_TYPE_ID'] = StorageType::Disk;
			$fields['STORAGE_ELEMENT_IDS'] = $this->getStorageElementIds();
		}

		$fields = array_merge($fields, $this->getAdditionalFields());

		if ($useCurrentSettings)
		{
			$fields['SETTINGS'] = $this->getSettings();
		}

		$color = $this->getColorId();
		if ($color)
		{
			$fields['SETTINGS']['COLOR'] = $color;
		}

		if ($this->checkPermissions && !CCrmActivity::CheckUpdatePermission($this->getOwner()->getEntityTypeId(), $this->getOwner()->getEntityId()))
		{
			$result->addError(ErrorCode::getAccessDeniedError());

			return $result;
		}

		$calendarEventId = (int)$this->getCalendarEventId();
		$optionsContext = $options['CONTEXT'] ?? null;
		$context = $optionsContext instanceof Context ? $optionsContext : $this->getContext();
		$isAutomation = $context !== null && $context->getScope() === Context::SCOPE_AUTOMATION;

		$existedActivity = null;
		if ($this->getId())
		{
			$existedActivity = CCrmActivity::GetList(
				[],
				[
					'=ID' => $this->getId(),
					'CHECK_PERMISSIONS' => 'N',
				],
				false,
				false,
				[
					'ID',
					'COMPLETED',
					'PROVIDER_ID',
					'DEADLINE',
				]
			)->Fetch();

			if (!$existedActivity)
			{
				$result->addError(ErrorCode::getNotFoundError());

				return $result;
			}
			if ($existedActivity['COMPLETED'] === 'Y')
			{
				$result->addError(
					new Error(Loc::getMessage("CRM_TODO_ENTITY_ACTIVITY_ALREADY_COMPLETED"), 'CAN_NOT_UPDATE_COMPLETED_TODO'),
				);

				return $result;
			}
			if (!$this->isValidProviderId($existedActivity['PROVIDER_ID']))
			{
				$result->addError(ErrorCode::getNotFoundError());

				return $result;
			}
		}

		// gate calendar-event editing for a bound event when the editor lacks rights on the event itself
		if (
			$this->checkPermissions
			&& $calendarEventId > 0
			&& !$isAutomation
			&& Loader::includeModule('calendar')
		)
		{
			$editorUserId = $context?->getUserId() ?? Container::getInstance()->getContext()->getUserId();
			if (!$this->canEditCalendarEvent($editorUserId, $calendarEventId))
			{
				// moving the linked event's date (synced as START_TIME/END_TIME) requires edit rights on the event
				if ($this->isDeadlineChanged($existedActivity, $fields))
				{
					$result->addError(
						new Error(
							Loc::getMessage('CRM_TODO_ENTITY_ACTIVITY_CALENDAR_EVENT_ACCESS_DENIED'),
							ErrorCode::ACCESS_DENIED,
						),
					);

					return $result;
				}

				// date unchanged: keep the CRM activity update, but do not propagate other fields
				// (name, description, color, attendees) to the calendar event without edit rights
				$options['SKIP_CALENDAR_EVENT'] = true;
			}
		}

		if ($this->getId())
		{
			$result = $this->performUpdate($fields, $options);
		}
		else
		{
			$fields['BINDINGS'] = empty($parentActivityBindings)
				? [
					[
						'OWNER_TYPE_ID' => $this->owner->getEntityTypeId(),
						'OWNER_ID' => $this->owner->getEntityId(),
					],
				]
				: $parentActivityBindings;
			$result = $this->performCreate($fields, $options);
			if ($result->isSuccess())
			{
				$this->id = (int)$result->getData()['id'];

				$allowComplete = true;
				if (
					isset($options['DISABLE_COMPLETE_PARENT_ACTIVITY'])
					&& $options['DISABLE_COMPLETE_PARENT_ACTIVITY'] === true
				)
				{
					$allowComplete = false;
				}
				if ($allowComplete && $this->getParentActivityId())
				{
					// close parent activity
					if (!CCrmActivity::Complete($this->getParentActivityId(), true, ['REGISTER_SONET_EVENT' => true]))
					{
						$result->addError(new Error(implode(', ', CCrmActivity::GetErrorMessages()), 'CAN_NOT_COMPLETE'));
					}
				}
			}
		}

		return $result;
	}

	/**
	 * Writes the changed values of the activity that is already stored.
	 *
	 * ONE OF THE TWO SEAMS OF THE WRITE. Everything above it - the fields, the check of the rights, the gate of a
	 * bound calendar event - is the same whichever API performs the write, and which API that is depends on the
	 * kind of activity: the domain entity of a to-do calls the service of V2 directly while the delegation flag
	 * is on, every other kind goes on writing through the legacy entry point.
	 *
	 * @param array<string, mixed> $fields
	 * @param array<string, mixed> $options
	 */
	protected function performUpdate(array $fields, array $options): Result
	{
		$result = new Result();
		if (CCrmActivity::Update($this->getId(), $fields, $this->checkPermissions, true, $options))
		{
			return $result;
		}

		foreach (CCrmActivity::GetErrorMessages() as $errorMessage)
		{
			$result->addError(new Error($errorMessage));
		}

		return $result;
	}

	/**
	 * Creates the activity. The other seam of the write, see above.
	 *
	 * @param array<string, mixed> $fields
	 * @param array<string, mixed> $options
	 * @return Result the identifier of the created activity under the `id` key of its data.
	 */
	protected function performCreate(array $fields, array $options): Result
	{
		$provider = new \Bitrix\Crm\Activity\Provider\ToDo\ToDo();

		return $provider->createActivity(
			\Bitrix\Crm\Activity\Provider\ToDo\ToDo::PROVIDER_TYPE_ID_DEFAULT,
			$fields,
			$options,
		);
	}

	private function isParentActivityCompleted(int $activityId): bool
	{
		$dbRes = CCrmActivity::GetList(
			[],
			['ID' => $activityId, 'CHECK_PERMISSIONS' => 'N'],
			false,
			false,
			['ID', 'COMPLETED']
		);
		$fields = $dbRes->Fetch();

		return isset($fields['COMPLETED']) && $fields['COMPLETED'] === true;
	}

	private function isParentActivityValid($bindings): bool
	{
		if (is_array($bindings) && !empty($bindings))
		{
			foreach ($bindings as $binding)
			{
				if (
					$this->owner->getEntityTypeId() === (int)$binding['OWNER_TYPE_ID']
					&& $this->owner->getEntityId() === (int)$binding['OWNER_ID']
				)
				{
					return true;
				}
			}
		}

		return false;
	}

	public function appendAdditionalFields(array $fields): self
	{
		$this->additionalFields = array_merge_recursive($this->additionalFields, $fields);

		return $this;
	}

	public function setContext(Context $context): self
	{
		$this->context = $context;

		return $this;
	}

	public function getContext(): ?Context
	{
		return $this->context;
	}

	private function canEditCalendarEvent(int $editorUserId, int $calendarEventId): bool
	{
		return (new CalendarEventEditPermissionChecker())->canChangeDeadline($editorUserId, $calendarEventId);
	}

	private function isDeadlineChanged(?array $existedActivity, array $fields): bool
	{
		// a new activity has nothing to compare against, keep the gate unconditional
		if ($existedActivity === null)
		{
			return true;
		}

		// The stored DEADLINE comes from CCrmActivity::GetList in the current user's timezone, while the
		// written DEADLINE (assembled from additionalFields, e.g. by the calendar block via
		// DateTime::toString()) is rendered in the server timezone. Parsing each in its own timezone
		// compares the same absolute instant even when the user and server timezones differ; fall back
		// to the entity deadline when no field-level DEADLINE is produced (unbound activity).
		$storedDeadline = $this->parseDeadlineValue($existedActivity['DEADLINE'] ?? null, true);

		$writtenDeadline = array_key_exists('DEADLINE', $fields)
			? $this->parseDeadlineValue($fields['DEADLINE'], false)
			: $this->getDeadline();

		return $this->normalizeDeadlineTimestamp($storedDeadline) !== $this->normalizeDeadlineTimestamp($writtenDeadline);
	}

	private function parseDeadlineValue($value, bool $inUserTime): ?DateTime
	{
		if ($value instanceof DateTime)
		{
			return $value;
		}

		if (empty($value) || CCrmDateTimeHelper::IsMaxDatabaseDate($value))
		{
			return null;
		}

		return $inUserTime ? DateTime::createFromUserTime($value) : new DateTime($value);
	}

	private function normalizeDeadlineTimestamp(?DateTime $deadline): ?int
	{
		if ($deadline === null || CCrmDateTimeHelper::IsMaxDatabaseDate($deadline->toString()))
		{
			return null;
		}

		return $deadline->getTimestamp();
	}
}
