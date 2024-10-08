<?php

namespace Bitrix\Crm\Activity\Entity;

use Bitrix\Crm\Activity\Provider;
use Bitrix\Crm\Activity\Provider\ToDo\OptionallyConfigurable;
use Bitrix\Crm\Activity\ToDo\ColorSettings\ColorSettingsProvider;
use Bitrix\Crm\Integration\DiskManager;
use Bitrix\Crm\Integration\StorageType;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Settings\Crm;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Uri;
use CCrmActivity;

class ToDo implements OptionallyConfigurable
{
	private const MAX_SUBJECT_LENGTH = 500;

	protected ?int $id = null;

	protected string $description = '';
	protected string $subject = '';
	protected ?DateTime $deadline = null;
	protected ?int $parentActivityId = null;

	protected int $responsibleId;
	protected ItemIdentifier $owner;

	protected ?int $autocompleteRule = null;
	protected string $completed;

	protected bool $checkPermissions = true;

	protected ?int $calendarEventId = null;
	protected ?string $colorId = null;

	protected ?array $storageElementIds = null;
	protected ?array $settings = null;

	protected array $additionalFields = [];

	public static function createWithDefaultSubjectAndDescription(
		int $entityTypeId,
		int $id,
		DateTime $deadline,
		bool $ceilDeadlineTime = true,
		bool $skipSubject = false
	): Result
	{
		if ($ceilDeadlineTime)
		{
			$deadline
				->setTime($deadline->format('H'), 0)
				->add('PT1H')
			;
		}

		$itemIdentifier = new ItemIdentifier($entityTypeId, $id);

		$todo = (new self($itemIdentifier))
			->setDefaultDescription()
			->setDeadline($deadline)
		;

		if (!$skipSubject)
		{
			$todo->setDefaultSubject();
		}

		return $todo->save();
	}

	// @deprecated
	public static function createWithDefaultDescription(
		int $entityTypeId,
		int $id,
		DateTime $deadline,
		bool $ceilDeadlineTime = true
	): Result
	{
		return self::createWithDefaultSubjectAndDescription(
			$entityTypeId,
			$id,
			$deadline,
			$ceilDeadlineTime,
			true
		);
	}

	public function __construct(ItemIdentifier $owner)
	{
		$this->owner = $owner;
		$this->responsibleId = Container::getInstance()->getContext()->getUserId();
	}

	public static function load(ItemIdentifier $owner, int $id): ?self
	{
		$filter = [
			'BINDINGS' => [
				[
					'OWNER_TYPE_ID' => $owner->getEntityTypeId(),
					'OWNER_ID' => $owner->getEntityId(),
				]
			],
			'=ID' => $id
		];

		return self::getInstanceByParams($owner, $filter);
	}
	public static function loadNearest(ItemIdentifier $owner): ?self
	{
		$filter = [
			'=COMPLETED' => 'N',
			'=PROVIDER_ID' => Provider\ToDo\ToDo::PROVIDER_ID,
			'=PROVIDER_TYPE_ID' => Provider\ToDo\ToDo::PROVIDER_TYPE_ID_DEFAULT,
			'BINDINGS' => [
				[
					'OWNER_TYPE_ID' => $owner->getEntityTypeId(),
					'OWNER_ID' => $owner->getEntityId(),
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

		return self::getInstanceByParams($owner, $filter, $order, $options);
	}
	protected static function getInstanceByParams(
		ItemIdentifier $owner,
		array $filter,
		array $order = [],
		array $options = []
	): ?ToDo
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
			],
			$options
		)->Fetch();

		if (!$data)
		{
			return null;
		}

		$todo = new self($owner);
		$todo
			->setId((int)$data['ID'])
			->setDeadline(
				($data['DEADLINE'] && !\CCrmDateTimeHelper::IsMaxDatabaseDate($data['DEADLINE']))
					? DateTime::createFromUserTime($data['DEADLINE'])
					: null
			)
			->setDescription($data['DESCRIPTION'])
			->setSubject($data['SUBJECT'])
			->setResponsibleId($data['RESPONSIBLE_ID'])
			->setParentActivityId($data['ASSOCIATED_ENTITY_ID'] ?: null)
			->setAutocompleteRule($data['AUTOCOMPLETE_RULE'] ?: null)
			->setCompleted($data['COMPLETED'])
			->setCalendarEventId($data['CALENDAR_EVENT_ID'])
			->setStorageElementIds($data['STORAGE_ELEMENT_IDS'] ?: null)
			->setSettings($data['SETTINGS'] ?: null)
		;

		return $todo;
	}
	public static function getDescriptionForEntityType(int $entityTypeId): string
	{
		$defaultDescription = Loc::getMessage('CRM_TODO_ENTITY_ACTIVITY_DESCRIPTION_CONTACT_CLIENT') ?? '';
		if ($entityTypeId === \CCrmOwnerType::Undefined)
		{
			return $defaultDescription;
		}

		$entityType = \CCrmOwnerType::ResolveName($entityTypeId);

		return (
			Loc::getMessage('CRM_TODO_ENTITY_ACTIVITY_DESCRIPTION_CONTACT_CLIENT_IN_' . $entityType) ?? $defaultDescription
		);
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

	public function getResponsibleId(): ?int
	{
		return $this->responsibleId;
	}

	public function setResponsibleId(int $responsibleId): self
	{
		$this->responsibleId = $responsibleId;

		return $this;
	}

	public function getProviderId(): string
	{
		return Provider\ToDo\ToDo::PROVIDER_ID;
	}

	public function getDescription(): string
	{
		return $this->description;
	}

	public function setDefaultDescription(): ToDo
	{
		$entityTypeId = $this->getOwner()->getEntityTypeId();
		return $this->setDescription(self::getDescriptionForEntityType($entityTypeId));
	}

	public function setDescription(string $description): self
	{
		$this->description = $description;

		return $this;
	}

	public function setDefaultSubject(): ToDo
	{
		return $this->setSubject($this->getDefaultSubject());
	}

	public function getDefaultSubject(): string
	{
		return Loc::getMessage('CRM_TODO_ENTITY_ACTIVITY_DEFAULT_SUBJECT');
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

	public function getDeadline(): ?DateTime
	{
		return $this->deadline;
	}

	public function setDeadline(?DateTime $deadline): self
	{
		$this->deadline = $deadline;

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

	public function getAutocompleteRule(): ?int
	{
		return $this->autocompleteRule;
	}

	public function setAutocompleteRule(?int $autocompleteRule): self
	{
		$this->autocompleteRule = $autocompleteRule;

		return $this;
	}

	public function getCompleted(): string
	{
		return $this->completed;
	}

	protected function setCompleted(string $completed): self
	{
		$this->completed = $completed;

		return $this;
	}

	public function isCompleted(): bool
	{
		return $this->completed === 'Y';
	}

	public function getStorageElementIds(): ?array
	{
		return $this->storageElementIds;
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

	/**
	 * @internal
	 * @todo refactor it
	 */
	public function setCalendarFileLinks(): void
	{
		if (!is_array($this->storageElementIds))
		{
			return;
		}

		$options = [
			'OWNER_TYPE_ID' => \CCrmOwnerType::Activity,
			'OWNER_ID' => $this->getId(),
		];

		$title = Loc::getMessage('CRM_TODO_ENTITY_ACTIVITY_FILES_TITLE');

		$fields['CALENDAR_ADDITIONAL_DESCRIPTION_DATA']['FILE'] = [
			'TITLE' => $title,
			'ITEMS' => [],
		];

		foreach ($this->storageElementIds as $id)
		{
			$fileInfo = DiskManager::getFileInfo($id, false, $options);
			if (is_array($fileInfo) && !empty($fileInfo['VIEW_URL']))
			{
				$link = (new Uri($fileInfo['VIEW_URL']))->toAbsolute();
				$fields['CALENDAR_ADDITIONAL_DESCRIPTION_DATA']['FILE']['ITEMS'][] = '<a href="' . $link . '" target="_blank">' . $fileInfo['NAME'] . '</a>';
			}
		}

		$this->appendAdditionalFields($fields);
	}

	public function setCheckPermissions(bool $checkPermissions): self
	{
		$this->checkPermissions = $checkPermissions;

		return $this;
	}

	public function save(array $options = [], $useCurrentSettings = false): Result
	{
		$result = new Result();

		if (Crm::isTimelineToDoUseV2Enabled())
		{
			$subject = $this->getSubject();
		}
		else
		{
			$subject = $this->getSubjectFromDescription($this->getDescription());
			$this->setSubject($subject);
		}

		$fields = [
			'SUBJECT' => $subject,
			'DESCRIPTION' => $this->getDescription(),
			'CALENDAR_EVENT_ID' => $this->getCalendarEventId(),
			'RESPONSIBLE_ID' => $this->getResponsibleId(),
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

		if($this->checkPermissions && !CCrmActivity::CheckUpdatePermission($this->getOwner()->getEntityTypeId(), $this->getOwner()->getEntityId()))
		{
			$result->addError(\Bitrix\Crm\Controller\ErrorCode::getAccessDeniedError());

			return $result;
		}

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
				]
			)->Fetch();

			if (!$existedActivity)
			{
				$result->addError(\Bitrix\Crm\Controller\ErrorCode::getNotFoundError());

				return $result;
			}
			if ($existedActivity['COMPLETED'] === 'Y')
			{
				$result->addError(
					new Error(Loc::getMessage("CRM_TODO_ENTITY_ACTIVITY_ALREADY_COMPLETED"), 'CAN_NOT_UPDATE_COMPLETED_TODO'),
				);

				return $result;
			}
			if ($existedActivity['PROVIDER_ID'] !== \Bitrix\Crm\Activity\Provider\ToDo\ToDo::getId())
			{
				$result->addError(\Bitrix\Crm\Controller\ErrorCode::getNotFoundError());

				return $result;
			}

			$isSuccess = CCrmActivity::Update($this->getId(), $fields, $this->checkPermissions, true, $options);

			if (!$isSuccess)
			{
				foreach (CCrmActivity::GetErrorMessages() as $errorMessage)
				{
					$result->addError(new Error($errorMessage));
				}
			}
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
			$provider = new \Bitrix\Crm\Activity\Provider\ToDo\ToDo();
			$result = $provider->createActivity(\Bitrix\Crm\Activity\Provider\ToDo\ToDo::PROVIDER_TYPE_ID_DEFAULT, $fields, $options);
			if ($result->isSuccess())
			{
				$this->id = (int)$result->getData()['id'];

				if ($this->getParentActivityId())
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

	private function isParentActivityCompleted(int $activityId): bool
	{
		$dbRes = CCrmActivity::GetList(
			[],
			['ID'=> $activityId, 'CHECK_PERMISSIONS' => 'N'],
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

	public function setAdditionalFields(array $fields): self
	{
		$this->additionalFields = $fields;

		return $this;
	}

	public function getAdditionalFields(): array
	{
		return $this->additionalFields;
	}

	public function appendAdditionalFields(array $fields): self
	{
		$this->additionalFields = array_merge_recursive($this->additionalFields, $fields);

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

	final protected function getColorId(): ?string
	{
		return $this->colorId;
	}

	final public function setColorId(?string $colorId): ToDo
	{
		$colorSettingsProvider = new ColorSettingsProvider();
		if ($colorSettingsProvider->isAvailableColorId($colorId))
		{
			$this->colorId = $colorId;
		}

		return $this;
	}

	private function getSubjectFromDescription(string $description): string
	{
		$lines = explode("\n", $description, 2);
		if (count($lines) > 1)
		{
			$subject = $lines[0];
			if (mb_strlen($subject) > self::MAX_SUBJECT_LENGTH)
			{
				$subject = mb_substr($subject, 0, self::MAX_SUBJECT_LENGTH);
			}

			return rtrim($subject, '.') . '...';
		}

		return TruncateText($lines[0], self::MAX_SUBJECT_LENGTH);
	}
}
