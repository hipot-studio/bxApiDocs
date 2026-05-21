<?php

declare(strict_types=1);

namespace Bitrix\Booking\Entity\ResourceType;

use Bitrix\Booking\Entity\EntityInterface;
use Bitrix\Booking\Entity\Enum\Notification\ReminderNotificationDelay;
use Bitrix\Booking\Entity\Enum\TemplateType\TemplateTypeConfirmation;
use Bitrix\Booking\Entity\Enum\TemplateType\TemplateTypeDelayed;
use Bitrix\Booking\Entity\Enum\TemplateType\TemplateTypeFeedback;
use Bitrix\Booking\Entity\Enum\TemplateType\TemplateTypeInfo;
use Bitrix\Booking\Entity\Enum\TemplateType\TemplateTypeReminder;
use Bitrix\Booking\Internals\Exception\InvalidArgumentException;

class ResourceType implements EntityInterface
{
	public const INTERNAL_MODULE_ID = 'booking';

	private int|null $id = null;
	private string|null $moduleId = null;
	private string|null $code = null;
	private string|null $name = null;
	private int|null $resourcesCnt = 0;

	private bool $isInfoNotificationOn = true;
	private string $templateTypeInfo;
	private int $infoNotificationDelay = 0;
	private bool $isCancellationNotificationOn = true;
	private int $cancellationNotificationDelay = 600; // 10 minutes
	private bool $isConfirmationNotificationOn = true;
	private string $templateTypeConfirmation;
	private int $confirmationNotificationDelay = 86400; // one day
	private int $confirmationNotificationRepetitions = 0;
	private int $confirmationNotificationRepetitionsInterval = 10800; // 3 hours
	private int $confirmationCounterDelay = 10800; // 3 hours
	private int $reminderNotificationDelay;
	private bool $isReminderNotificationOn = true;
	private string $templateTypeReminder;
	private bool $isFeedbackNotificationOn = true;
	private string $templateTypeFeedback;
	private bool $isDelayedNotificationOn = true;
	private string $templateTypeDelayed;
	private int $delayedNotificationDelay = 300; // 5 minutes
	private int $delayedCounterDelay = 300; // 5 minutes
	private string $senderCode = 'bitrix24';

	public function __construct()
	{
		$this->templateTypeInfo = TemplateTypeInfo::InAnimate->value;
		$this->templateTypeConfirmation = TemplateTypeConfirmation::InAnimate->value;
		$this->templateTypeReminder = TemplateTypeReminder::Base->value;
		$this->templateTypeFeedback = TemplateTypeFeedback::InAnimate->value;
		$this->templateTypeDelayed = TemplateTypeDelayed::InAnimate->value;

		$this->reminderNotificationDelay = ReminderNotificationDelay::Morning->value;
	}

	public function getId(): int|null
	{
		return $this->id;
	}

	public function setId(int|null $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getModuleId(): string|null
	{
		return $this->moduleId;
	}

	public function setModuleId(string|null $moduleId): self
	{
		$this->moduleId = $moduleId;

		return $this;
	}

	public function getCode(): string|null
	{
		return $this->code;
	}

	public function setCode(string|null $code): self
	{
		$this->code = $code;

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

	public function isReminderNotificationOn(): bool
	{
		return $this->isReminderNotificationOn;
	}

	public function setIsReminderNotificationOn(bool $isOn): self
	{
		$this->isReminderNotificationOn = $isOn;

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

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'moduleId' => $this->moduleId,
			'code' => $this->code,
			'name' => $this->name,
			'resourcesCnt' => $this->resourcesCnt,
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
		];
	}

	public static function mapFromArray(array $props): self
	{
		$resourceType = (new ResourceType())
			->setId(isset($props['id']) ? (int)$props['id'] : null)
			->setModuleId(isset($props['moduleId']) ? (string)$props['moduleId'] : null)
			->setCode(isset($props['code']) ? (string)$props['code'] : null)
			->setName(isset($props['name']) ? (string)$props['name'] : null)
		;

		if (isset($props['isInfoNotificationOn']))
		{
			$resourceType->setIsInfoNotificationOn((bool)$props['isInfoNotificationOn']);
		}
		if (isset($props['templateTypeInfo']))
		{
			$resourceType->setTemplateTypeInfo((string)$props['templateTypeInfo']);
		}

		if (isset($props['infoNotificationDelay']))
		{
			$resourceType->setInfoNotificationDelay((int)$props['infoNotificationDelay']);
		}

		if (isset($props['isCancellationNotificationOn']))
		{
			$resourceType->setIsCancellationNotificationOn((bool)$props['isCancellationNotificationOn']);
		}
		if (isset($props['cancellationNotificationDelay']))
		{
			$resourceType->setCancellationNotificationDelay((int)$props['cancellationNotificationDelay']);
		}

		if (isset($props['isConfirmationNotificationOn']))
		{
			$resourceType->setIsConfirmationNotificationOn((bool)$props['isConfirmationNotificationOn']);
		}
		if (isset($props['templateTypeConfirmation']))
		{
			$resourceType->setTemplateTypeConfirmation((string)$props['templateTypeConfirmation']);
		}
		if (isset($props['confirmationNotificationDelay']))
		{
			$resourceType->setConfirmationNotificationDelay((int)$props['confirmationNotificationDelay']);
		}
		if (isset($props['confirmationNotificationRepetitions']))
		{
			$resourceType->setConfirmationNotificationRepetitions((int)$props['confirmationNotificationRepetitions']);
		}
		if (isset($props['confirmationNotificationRepetitionsInterval']))
		{
			$resourceType->setConfirmationNotificationRepetitionsInterval((int)$props['confirmationNotificationRepetitionsInterval']);
		}
		if (isset($props['confirmationCounterDelay']))
		{
			$resourceType->setConfirmationCounterDelay((int)$props['confirmationCounterDelay']);
		}

		if (isset($props['isReminderNotificationOn']))
		{
			$resourceType->setIsReminderNotificationOn((bool)$props['isReminderNotificationOn']);
		}
		if (isset($props['templateTypeReminder']))
		{
			$resourceType->setTemplateTypeReminder((string)$props['templateTypeReminder']);
		}
		if (isset($props['reminderNotificationDelay']))
		{
			$resourceType->setReminderNotificationDelay((int)$props['reminderNotificationDelay']);
		}

		if (isset($props['isFeedbackNotificationOn']))
		{
			$resourceType->setIsFeedbackNotificationOn((bool)$props['isFeedbackNotificationOn']);
		}
		if (isset($props['templateTypeFeedback']))
		{
			$resourceType->setTemplateTypeFeedback((string)$props['templateTypeFeedback']);
		}

		if (isset($props['isDelayedNotificationOn']))
		{
			$resourceType->setIsDelayedNotificationOn((bool)$props['isDelayedNotificationOn']);
		}
		if (isset($props['templateTypeDelayed']))
		{
			$resourceType->setTemplateTypeDelayed((string)$props['templateTypeDelayed']);
		}
		if (isset($props['delayedNotificationDelay']))
		{
			$resourceType->setDelayedNotificationDelay((int)$props['delayedNotificationDelay']);
		}
		if (isset($props['delayedCounterDelay']))
		{
			$resourceType->setDelayedCounterDelay((int)$props['delayedCounterDelay']);
		}

		if (isset($props['senderCode']))
		{
			$resourceType->setSenderCode((string)$props['senderCode']);
		}

		return $resourceType;
	}

	public function setResourcesCnt(int|null $resourcesCnt): ResourceType
	{
		$this->resourcesCnt = $resourcesCnt;

		return $this;
	}
}
