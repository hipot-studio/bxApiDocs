<?php

declare(strict_types=1);

namespace Bitrix\Booking\Entity\Resource;

use Bitrix\Booking\Entity\EntityInterface;
use Bitrix\Booking\Entity\Enum\Notification\ReminderNotificationDelay;
use Bitrix\Booking\Entity\Enum\TemplateType\TemplateTypeConfirmation;
use Bitrix\Booking\Entity\Enum\TemplateType\TemplateTypeDelayed;
use Bitrix\Booking\Entity\Enum\TemplateType\TemplateTypeFeedback;
use Bitrix\Booking\Entity\Enum\TemplateType\TemplateTypeInfo;
use Bitrix\Booking\Entity\Enum\TemplateType\TemplateTypeReminder;
use Bitrix\Booking\Entity\File\File;
use Bitrix\Booking\Entity\ResourceType\ResourceType;
use Bitrix\Booking\Entity\Slot\RangeCollection;
use Bitrix\Booking\Internals\Exception\InvalidArgumentException;

class Resource implements EntityInterface
{
	private int|null $id = null;
	private int|null $externalId = null;
	private File|null $avatar = null;
	private ResourceType|null $type = null;
	private string|null $name = null;
	private string|null $description = null;
	private RangeCollection $slotRanges;
	private int $counter = 0;
	private bool|null $isMain = null;
	private bool|null $isDeleted = null;

	private bool $isInfoNotificationOn = true;
	private int $infoNotificationDelay = 0;
	private string $templateTypeInfo;
	private bool $isCancellationNotificationOn = true;
	private int $cancellationNotificationDelay = 600; // 10 minutes
	private bool $isConfirmationNotificationOn = true;
	private string $templateTypeConfirmation;
	private int $confirmationNotificationDelay = 86400; // one day
	private int $confirmationNotificationRepetitions = 0;
	private int $confirmationNotificationRepetitionsInterval = 10800; // 3 hours
	private int $confirmationCounterDelay = 10800; // 3 hours
	private bool $isReminderNotificationOn = true;
	private string $templateTypeReminder;
	private int $reminderNotificationDelay;
	private bool $isFeedbackNotificationOn = true;
	private string $templateTypeFeedback;
	private bool $isDelayedNotificationOn = true;
	private string $templateTypeDelayed;
	private int $delayedNotificationDelay = 300; // 5 minutes
	private int $delayedCounterDelay = 300; // 5 minutes
	private string $senderCode = 'bitrix24';

	private int|null $createdBy = null;
	private int|null $createdAt = null;
	private int|null $updatedAt = null;
	private int|null $deletedAt = null;
	private ResourceLinkedEntityCollection|null $entityCollection = null;
	private ResourceSkuCollection|null $skuCollection = null;
	private ResourceSkuCollection|null $skuYandexCollection = null;

	public function __construct()
	{
		$this->slotRanges = new RangeCollection(...[]);

		$this->templateTypeInfo = TemplateTypeInfo::InAnimate->value;
		$this->templateTypeConfirmation = TemplateTypeConfirmation::InAnimate->value;
		$this->templateTypeReminder = TemplateTypeReminder::Base->value;
		$this->templateTypeFeedback = TemplateTypeFeedback::InAnimate->value;
		$this->templateTypeDelayed = TemplateTypeDelayed::InAnimate->value;

		$this->reminderNotificationDelay = ReminderNotificationDelay::Morning->value;

		$this->entityCollection = new ResourceLinkedEntityCollection();
		$this->skuCollection = new ResourceSkuCollection();
		$this->skuYandexCollection = new ResourceSkuCollection();
	}

	public function getId(): int|null
	{
		return $this->id;
	}

	public function setId(int|null $id): Resource
	{
		$this->id = $id;

		return $this;
	}

	public function getExternalId(): int|null
	{
		return $this->externalId;
	}

	public function setExternalId(int|null $externalId): Resource
	{
		$this->externalId = $externalId;

		return $this;
	}

	public function setAvatar(File|null $avatar): self
	{
		$this->avatar = $avatar;

		return $this;
	}

	public function getAvatar(): File|null
	{
		return $this->avatar;
	}

	public function getType(): ResourceType|null
	{
		return $this->type;
	}

	public function setType(ResourceType|null $type): self
	{
		$this->type = $type;

		return $this;
	}

	public function getName(): string|null
	{
		return $this->name;
	}

	public function setName(string|null $name): self
	{
		$this->name = $name;

		return $this;
	}

	public function getDescription(): string|null
	{
		return $this->description;
	}

	public function setDescription(string|null $description): self
	{
		$this->description = $description;

		return $this;
	}

	public function getSlotRanges(): RangeCollection
	{
		return $this->slotRanges;
	}

	public function setSlotRanges(RangeCollection $slotRanges): self
	{
		$this->slotRanges = $slotRanges;

		return $this;
	}

	public function isInfoNotificationOn(): bool
	{
		return $this->isInfoNotificationOn;
	}

	public function setIsInfoNotificationOn(bool $isOn): self
	{
		$this->isInfoNotificationOn = $isOn;

		return $this;
	}

	public function getInfoNotificationDelay(): int
	{
		return $this->infoNotificationDelay;
	}


	public function setInfoNotificationDelay(int $delay): self
	{
		$this->infoNotificationDelay = $delay;

		return $this;
	}

	public function isCancellationNotificationOn(): bool
	{
		return $this->isCancellationNotificationOn;
	}

	public function setIsCancellationNotificationOn(bool $isOn): self
	{
		$this->isCancellationNotificationOn = $isOn;

		return $this;
	}

	public function getCancellationNotificationDelay(): int
	{
		return $this->cancellationNotificationDelay;
	}

	public function setCancellationNotificationDelay(int $delay): self
	{
		$this->cancellationNotificationDelay = $delay;

		return $this;
	}

	public function isConfirmationNotificationOn(): bool
	{
		return $this->isConfirmationNotificationOn;
	}

	public function setIsConfirmationNotificationOn(bool $isOn): self
	{
		$this->isConfirmationNotificationOn = $isOn;

		return $this;
	}

	public function isReminderNotificationOn(): bool
	{
		return $this->isReminderNotificationOn;
	}

	public function setIsReminderNotificationOn(bool $isOn): self
	{
		$this->isReminderNotificationOn = $isOn;

		return $this;
	}

	public function isFeedbackNotificationOn(): bool
	{
		return $this->isFeedbackNotificationOn;
	}

	public function setIsFeedbackNotificationOn(bool $isOn): self
	{
		$this->isFeedbackNotificationOn = $isOn;

		return $this;
	}

	public function getTemplateTypeInfo(): string
	{
		return $this->templateTypeInfo;
	}

	public function setTemplateTypeInfo(string $templateType): self
	{
		if (!TemplateTypeInfo::isValid($templateType))
		{
			throw new InvalidArgumentException('Invalid value for templateTypeInfo');
		}

		$this->templateTypeInfo = $templateType;

		return $this;
	}

	public function getTemplateTypeConfirmation(): string
	{
		return $this->templateTypeConfirmation;
	}

	public function setTemplateTypeConfirmation(string $templateType): self
	{
		if (!TemplateTypeConfirmation::isValid($templateType))
		{
			throw new InvalidArgumentException('Invalid value for templateTypeConfirmation');
		}

		$this->templateTypeConfirmation = $templateType;

		return $this;
	}

	public function getConfirmationNotificationDelay(): int
	{
		return $this->confirmationNotificationDelay;
	}

	public function setConfirmationNotificationDelay(int $delay): self
	{
		$this->confirmationNotificationDelay = $delay;

		return $this;
	}

	public function getConfirmationNotificationRepetitions(): int
	{
		return $this->confirmationNotificationRepetitions;
	}

	public function setConfirmationNotificationRepetitions(int $repetitions): self
	{
		$this->confirmationNotificationRepetitions = $repetitions;

		return $this;
	}

	public function getConfirmationNotificationRepetitionsInterval(): int
	{
		return $this->confirmationNotificationRepetitionsInterval;
	}

	public function setConfirmationNotificationRepetitionsInterval(int $repetitionsInterval): self
	{
		$this->confirmationNotificationRepetitionsInterval = $repetitionsInterval;

		return $this;
	}

	public function getConfirmationCounterDelay(): int
	{
		return $this->confirmationCounterDelay;
	}

	public function setConfirmationCounterDelay(int $delay): self
	{
		$this->confirmationCounterDelay = $delay;

		return $this;
	}

	public function getTemplateTypeReminder(): string
	{
		return $this->templateTypeReminder;
	}

	public function setTemplateTypeReminder(string $templateType): self
	{
		if (!TemplateTypeReminder::isValid($templateType))
		{
			throw new InvalidArgumentException('Invalid value for templateTypeReminder');
		}

		$this->templateTypeReminder = $templateType;

		return $this;
	}

	public function getReminderNotificationDelay(): int
	{
		return $this->reminderNotificationDelay;
	}

	public function setReminderNotificationDelay(int $delay): self
	{
		$this->reminderNotificationDelay = $delay;

		return $this;
	}

	public function getTemplateTypeFeedback(): string
	{
		return $this->templateTypeFeedback;
	}

	public function setTemplateTypeFeedback(string $templateType): self
	{
		if (!TemplateTypeFeedback::isValid($templateType))
		{
			throw new InvalidArgumentException('Invalid value for templateTypeFeedback');
		}

		$this->templateTypeFeedback = $templateType;

		return $this;
	}

	public function isDelayedNotificationOn(): bool
	{
		return $this->isDelayedNotificationOn;
	}

	public function setIsDelayedNotificationOn(bool $isOn): self
	{
		$this->isDelayedNotificationOn = $isOn;

		return $this;
	}

	public function getTemplateTypeDelayed(): string
	{
		return $this->templateTypeDelayed;
	}

	public function setTemplateTypeDelayed(string $templateType): self
	{
		if (!TemplateTypeDelayed::isValid($templateType))
		{
			throw new InvalidArgumentException('Invalid value for templateTypeDelayed');
		}

		$this->templateTypeDelayed = $templateType;

		return $this;
	}

	public function getDelayedNotificationDelay(): int
	{
		return $this->delayedNotificationDelay;
	}

	public function setDelayedNotificationDelay(int $delay): self
	{
		$this->delayedNotificationDelay = $delay;

		return $this;
	}

	public function getDelayedCounterDelay(): int
	{
		return $this->delayedCounterDelay;
	}

	public function setDelayedCounterDelay(int $delay): self
	{
		$this->delayedCounterDelay = $delay;

		return $this;
	}

	public function getSenderCode(): string
	{
		return $this->senderCode;
	}

	public function setSenderCode(string $senderCode): self
	{
		$this->senderCode = $senderCode;

		return $this;
	}

	public function getCreatedBy(): int|null
	{
		return $this->createdBy;
	}

	public function setCreatedBy(int|null $createdBy): self
	{
		$this->createdBy = $createdBy;

		return $this;
	}

	public function getCreatedAt(): int|null
	{
		return $this->createdAt;
	}

	public function setCreatedAt(int|null $createdAt): self
	{
		$this->createdAt = $createdAt;

		return $this;
	}

	public function getUpdatedAt(): int|null
	{
		return $this->updatedAt;
	}

	public function setUpdatedAt(int|null $updatedAt): self
	{
		$this->updatedAt = $updatedAt;

		return $this;
	}

	public function getDeletedAt(): int|null
	{
		return $this->deletedAt;
	}

	public function setDeletedAt(int|null $deletedAt): self
	{
		$this->deletedAt = $deletedAt;

		return $this;
	}

	public function getCounter(): int
	{
		return $this->counter;
	}

	public function setCounter(int $value): self
	{
		$this->counter = $value;

		return $this;
	}

	public function isExternal(): bool
	{
		$typeHasExternalModuleId = false;
		$moduleId = $this->type?->getModuleId();

		if ($moduleId !== null && $moduleId !== 'booking')
		{
			$typeHasExternalModuleId = true;
		}

		return $this->getExternalId() && $typeHasExternalModuleId;
	}

	public function isMain(): bool|null
	{
		return $this->isMain;
	}

	public function setMain(bool $isMain): self
	{
		$this->isMain = $isMain;

		return $this;
	}

	public function isDeleted(): bool|null
	{
		return $this->isDeleted;
	}

	public function setDeleted(bool $isDeleted): self
	{
		$this->isDeleted = $isDeleted;

		return $this;
	}

	public function getEntityCollection(): ResourceLinkedEntityCollection
	{
		return $this->entityCollection;
	}

	public function setEntityCollection(ResourceLinkedEntityCollection $entityCollection): void
	{
		$this->entityCollection = $entityCollection;
	}

	public function getSkuCollection(): ResourceSkuCollection
	{
		return $this->skuCollection;
	}

	public function setSkuCollection(ResourceSkuCollection $skuCollection): Resource
	{
		$this->skuCollection = $skuCollection;

		return $this;
	}

	public function getSkuYandexCollection(): ResourceSkuCollection
	{
		return $this->skuYandexCollection;
	}

	public function setSkuYandexCollection(ResourceSkuCollection $skuCollection): Resource
	{
		$this->skuYandexCollection = $skuCollection;

		return $this;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'externalId' => $this->externalId,
			'avatar' => $this->avatar?->toArray(),
			'type' => $this->type?->toArray(),
			'isMain' => $this->isMain,
			'isDeleted' => $this->isDeleted,
			'name' => $this->name,
			'description' => $this->description,
			'slotRanges' => $this->slotRanges->toArray(),
			'isInfoNotificationOn' => $this->isInfoNotificationOn,
			'templateTypeInfo' => $this->templateTypeInfo,
			'infoNotificationDelay' => $this->infoNotificationDelay,
			'isCancellationNotificationOn' => $this->isCancellationNotificationOn,
			'cancellationNotificationDelay' => $this->cancellationNotificationDelay,
			'isConfirmationNotificationOn' => $this->isConfirmationNotificationOn,
			'templateTypeConfirmation' => $this->templateTypeConfirmation,
			'confirmationNotificationDelay' => $this->confirmationNotificationDelay,
			'confirmationNotificationRepetitions' => $this->confirmationNotificationRepetitions,
			'confirmationNotificationRepetitionsInterval' => $this->confirmationNotificationRepetitionsInterval,
			'confirmationCounterDelay' => $this->confirmationCounterDelay,
			'isReminderNotificationOn' => $this->isReminderNotificationOn,
			'templateTypeReminder' => $this->templateTypeReminder,
			'reminderNotificationDelay' => $this->reminderNotificationDelay,
			'isFeedbackNotificationOn' => $this->isFeedbackNotificationOn,
			'templateTypeFeedback' => $this->templateTypeFeedback,
			'isDelayedNotificationOn' => $this->isDelayedNotificationOn,
			'templateTypeDelayed' => $this->templateTypeDelayed,
			'delayedNotificationDelay' => $this->delayedNotificationDelay,
			'delayedCounterDelay' => $this->delayedCounterDelay,
			'senderCode' => $this->senderCode,
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'deletedAt' => $this->deletedAt,
			'counter' => $this->counter,
			'entities' => $this->entityCollection->toArray(),
			'skus' => $this->skuCollection->toArray(),
			'skusYandex' => $this->skuYandexCollection->toArray(),
		];
	}

	public static function mapFromArray(array $props): self
	{
		$type = isset($props['type']) ? ResourceType::mapFromArray($props['type']) : null;

		$slotRanges = isset($props['slotRanges'])
			? RangeCollection::mapFromArray($props['slotRanges'])
			: new RangeCollection(...[])
		;

		$resource = (new Resource())
			->setId(isset($props['id']) ? (int)$props['id'] : null)
			->setExternalId(isset($props['externalId']) ? (int)$props['externalId'] : null)
			->setAvatar(isset($props['avatar']) ? File::createFromArray($props['avatar']) : null)
			->setType($type)
			->setName(isset($props['name']) ? (string)$props['name'] : null)
			->setDescription(isset($props['description']) ? (string)$props['description'] : null)
			->setSlotRanges($slotRanges)
			->setCreatedBy(isset($props['createdBy']) ? (int)$props['createdBy'] : null)
			->setCreatedAt(isset($props['createdAt']) ? (int)$props['createdAt'] : null)
			->setUpdatedAt(isset($props['updatedAt']) ? (int)$props['updatedAt'] : null)
			->setDeletedAt(isset($props['deletedAt']) ? (int)$props['deletedAt'] : null)
		;

		if (isset($props['isInfoNotificationOn']))
		{
			$resource->setIsInfoNotificationOn((bool)$props['isInfoNotificationOn']);
		}
		if (isset($props['templateTypeInfo']))
		{
			$resource->setTemplateTypeInfo((string)$props['templateTypeInfo']);
		}
		if (isset($props['infoNotificationDelay']))
		{
			$resource->setInfoNotificationDelay((int)$props['infoNotificationDelay']);
		}

		if (isset($props['isCancellationNotificationOn']))
		{
			$resource->setIsCancellationNotificationOn((bool)$props['isCancellationNotificationOn']);
		}
		if (isset($props['cancellationNotificationDelay']))
		{
			$resource->setCancellationNotificationDelay((int)$props['cancellationNotificationDelay']);
		}

		if (isset($props['isConfirmationNotificationOn']))
		{
			$resource->setIsConfirmationNotificationOn((bool)$props['isConfirmationNotificationOn']);
		}
		if (isset($props['templateTypeConfirmation']))
		{
			$resource->setTemplateTypeConfirmation((string)$props['templateTypeConfirmation']);
		}
		if (isset($props['confirmationNotificationDelay']))
		{
			$resource->setConfirmationNotificationDelay((int)$props['confirmationNotificationDelay']);
		}
		if (isset($props['confirmationNotificationRepetitions']))
		{
			$resource->setConfirmationNotificationRepetitions((int)$props['confirmationNotificationRepetitions']);
		}
		if (isset($props['confirmationNotificationRepetitionsInterval']))
		{
			$resource->setConfirmationNotificationRepetitionsInterval((int)$props['confirmationNotificationRepetitionsInterval']);
		}
		if (isset($props['confirmationCounterDelay']))
		{
			$resource->setConfirmationCounterDelay((int)$props['confirmationCounterDelay']);
		}

		if (isset($props['isReminderNotificationOn']))
		{
			$resource->setIsReminderNotificationOn((bool)$props['isReminderNotificationOn']);
		}
		if (isset($props['templateTypeReminder']))
		{
			$resource->setTemplateTypeReminder((string)$props['templateTypeReminder']);
		}
		if (isset($props['reminderNotificationDelay']))
		{
			$resource->setReminderNotificationDelay((int)$props['reminderNotificationDelay']);
		}

		if (isset($props['isFeedbackNotificationOn']))
		{
			$resource->setIsFeedbackNotificationOn((bool)$props['isFeedbackNotificationOn']);
		}
		if (isset($props['templateTypeFeedback']))
		{
			$resource->setTemplateTypeFeedback((string)$props['templateTypeFeedback']);
		}

		if (isset($props['isDelayedNotificationOn']))
		{
			$resource->setIsDelayedNotificationOn((bool)$props['isDelayedNotificationOn']);
		}
		if (isset($props['templateTypeDelayed']))
		{
			$resource->setTemplateTypeDelayed((string)$props['templateTypeDelayed']);
		}
		if (isset($props['delayedNotificationDelay']))
		{
			$resource->setDelayedNotificationDelay((int)$props['delayedNotificationDelay']);
		}
		if (isset($props['delayedCounterDelay']))
		{
			$resource->setDelayedCounterDelay((int)$props['delayedCounterDelay']);
		}

		if (isset($props['senderCode']))
		{
			$resource->setSenderCode((string)$props['senderCode']);
		}

		if (isset($props['isMain']))
		{
			$resource->setMain((bool)$props['isMain']);
		}

		if (isset($props['isDeleted']))
		{
			$resource->setDeleted((bool)$props['isDeleted']);
		}

		if (isset($props['entities']))
		{
			$resource->setEntityCollection(ResourceLinkedEntityCollection::mapFromArray($props['entities']));
		}

		if (isset($props['skus']))
		{
			$resource->setSkuCollection(ResourceSkuCollection::mapFromArray($props['skus']));
		}

		if (isset($props['skusYandex']))
		{
			$resource->setSkuYandexCollection(ResourceSkuCollection::mapFromArray($props['skusYandex']));
		}

		return $resource;
	}
}
