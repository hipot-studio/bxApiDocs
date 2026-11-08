<?php

namespace Bitrix\Crm\Copilot\CallAssessment;

use Bitrix\Crm\Copilot\CallAssessment\Entity\CopilotCallAssessment;
use Bitrix\Crm\Copilot\CallAssessment\Enum\AutoCheckType;
use Bitrix\Crm\Copilot\CallAssessment\Enum\AvailabilityType;
use Bitrix\Crm\Copilot\CallAssessment\Enum\CallType;
use Bitrix\Crm\Integration\AI\Model\QueueTable;

final class CallAssessmentItem
{
	public const LOW_BORDER_DEFAULT = 30;
	public const HIGH_BORDER_DEFAULT = 70;
	public const STATUS_GENERATING_FROM_DIALOG = 'GENERATING_FROM_DIALOG';

	private ?int $id;
	private string $title;
	private string $description;
	private string $prompt;
	private ?bool $isHintEnabled = null;
	private bool $areHintFieldsProvided = false;
	private bool $areHintFieldsValid = true;
	private ?string $gist = null;
	private array $clientTypeIds;
	private ?CallType $callType = null;
	private ?AutoCheckType $autoCheckType = null;
	private AvailabilityType $availabilityType = AvailabilityType::ALWAYS_ACTIVE;
	private array $availabilityData = [];
	private bool $isEnabled;
	private bool $isDefault = false;
	private bool $isAiImprovementEnabled = true;
	private int $jobId = 0;
	private string $status = QueueTable::EXECUTION_STATUS_PENDING;
	private ?string $code = null;
	private ?string $updatedAt = null;
	private int $lowBorder = self::LOW_BORDER_DEFAULT;
	private int $highBorder = self::HIGH_BORDER_DEFAULT;
	private ?array $criteria = null;

	public static function createFromEntity(CopilotCallAssessment $callAssessmentItem): self
	{
		$instance = new self();

		$instance->id = $callAssessmentItem->getId();
		$instance->title = $callAssessmentItem->getTitle();
		$instance->description = $callAssessmentItem->getDescription();
		$instance->prompt = $callAssessmentItem->getPrompt();
		$instance->isHintEnabled = $callAssessmentItem->getIsHintEnabled();
		$instance->gist = $callAssessmentItem->getGist();

		$instance->clientTypeIds = [];
		$clientTypes = $callAssessmentItem->getClientTypes() ?? [];
		/** @var EO_CopilotCallAssessmentClientType $clientType */
		foreach ($clientTypes as $clientType)
		{
			$instance->clientTypeIds[] = $clientType->getClientTypeId();
		}

		// a stored script may have no call direction yet, it must not break every list it is shown in
		$instance->callType = CallType::tryFrom((int)$callAssessmentItem->getCallType());
		$instance->autoCheckType = AutoCheckType::from($callAssessmentItem->getAutoCheckType());
		$instance->availabilityType = AvailabilityType::from($callAssessmentItem->getAvailabilityType());
		$instance->availabilityData = [];
		$availabilityData = $callAssessmentItem->getAvailabilityData() ?? [];
		/** @var EO_CopilotCallAssessmentAvailability $availabilityDataItem */
		foreach ($availabilityData as $availabilityDataItem)
		{
			$instance->availabilityData[] = [
				'startPoint' => $availabilityDataItem->getStartPoint(),
				'endPoint' => $availabilityDataItem->getEndPoint(),
				'weekdayType' => $availabilityDataItem->getWeekdayType(),
			];
		}

		$instance->isEnabled = $callAssessmentItem->getIsEnabled();
		$instance->isDefault = true; // not used in Crm.Copilot.CallAssessment
		$instance->isAiImprovementEnabled = $callAssessmentItem->getIsAiImprovementEnabled() ?? true;
		$instance->jobId = $callAssessmentItem->getJobId();
		$instance->status = $callAssessmentItem->getStatus();
		$instance->code = $callAssessmentItem->getCode();
		$instance->updatedAt = $callAssessmentItem->getUpdatedAt()?->format(DATE_ATOM);
		$instance->lowBorder = $callAssessmentItem->getLowBorder();
		$instance->highBorder = $callAssessmentItem->getHighBorder();

		return $instance;
	}

	public static function createFromArray(array $data): self
	{
		$instance = new self();
		$hasPrompt = array_key_exists('prompt', $data);
		$hasHintState = array_key_exists('isHintEnabled', $data);
		$instance->areHintFieldsProvided = $hasPrompt && $hasHintState;
		$instance->areHintFieldsValid = $hasPrompt === $hasHintState
			&& (!$hasPrompt || (is_string($data['prompt']) && is_bool($data['isHintEnabled'])))
		;

		$instance->id = $data['id'] ?? null;
		$instance->title = $data['title'] ?? '';
		$instance->description = $data['description'] ?? '';
		$instance->prompt = $hasPrompt && is_string($data['prompt']) ? $data['prompt'] : '';
		$instance->isHintEnabled = $hasHintState && is_bool($data['isHintEnabled'])
			? $data['isHintEnabled']
			: null
		;
		$instance->gist = $data['gist'] ?? null;
		$instance->clientTypeIds = $data['clientTypeIds'] ?? [];

		if (isset($data['callTypeId']))
		{
			$instance->callType = CallType::from($data['callTypeId']);
		}

		if (isset($data['autoCheckTypeId']))
		{
			$instance->autoCheckType = AutoCheckType::from($data['autoCheckTypeId']);
		}

		$instance->availabilityType = isset($data['availabilityType'])
			? AvailabilityType::from($data['availabilityType'])
			: AvailabilityType::ALWAYS_ACTIVE
		;

		$instance->availabilityData = $data['availabilityData'] ?? [];

		$instance->isEnabled = $instance->availabilityType !== AvailabilityType::INACTIVE;
		$instance->isDefault = true; // not used in Crm.Copilot.CallAssessment
		$instance->isAiImprovementEnabled = $data['isAiImprovementEnabled'] ?? true;
		$instance->jobId = $data['jobId'] ?? 0;
		$instance->status = $data['status'] ?? QueueTable::EXECUTION_STATUS_PENDING;
		$instance->code = $data['code'] ?? null;
		$instance->updatedAt = $data['promptUpdatedAt'] ?? null;
		$instance->lowBorder = $data['lowBorder'] ?? self::LOW_BORDER_DEFAULT;
		$instance->highBorder = $data['highBorder'] ?? self::HIGH_BORDER_DEFAULT;
		if (isset($data['criteria']) && is_array($data['criteria']))
		{
			$instance->criteria = $data['criteria'];
		}

		return $instance;
	}

	public function toArray(): array
	{
		$isHintEnabled = (new HintStateResolver())->resolve(
			$this->isHintEnabled,
			$this->prompt,
			$this->code,
		);
		$data = [
			'id' => $this->id,
			'title' => $this->title,
			'description' => $this->description,
			'prompt' => $this->prompt,
			'isHintEnabled' => $isHintEnabled,
			'hasHint' => $isHintEnabled && trim($this->prompt) !== '',
			'gist' => $this->gist,
			'clientTypeIds' => $this->clientTypeIds,
			'callTypeId' => $this->callType?->value,
			'autoCheckTypeId' => $this->autoCheckType?->value,
			'availabilityType' => $this->availabilityType->value,
			'availabilityData' => $this->availabilityData,
			'isEnabled' => $this->availabilityType !== AvailabilityType::INACTIVE,
			'isAiImprovementEnabled' => $this->isAiImprovementEnabled,
			'jobId' => $this->jobId,
			'status' => $this->status,
			'code' => $this->code,
			'promptUpdatedAt' => $this->updatedAt,
			'lowBorder' => $this->lowBorder,
			'highBorder' => $this->highBorder,
		];

		if ($this->criteria !== null)
		{
			$data['criteria'] = $this->criteria;
		}

		return $data;
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function setTitle(string $title): self
	{
		$this->title = $title;

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

	public function getPrompt(): string
	{
		return $this->prompt;
	}

	public function isHintEnabled(): ?bool
	{
		return $this->isHintEnabled;
	}

	public function setHintEnabled(?bool $isHintEnabled): self
	{
		$this->isHintEnabled = $isHintEnabled;

		return $this;
	}

	public function areHintFieldsProvided(): bool
	{
		return $this->areHintFieldsProvided;
	}

	public function areHintFieldsValid(): bool
	{
		return $this->areHintFieldsValid;
	}

	public function getGist(): ?string
	{
		return $this->gist;
	}

	public function setGist(?string $gist): self
	{
		$this->gist = $gist;

		return $this;
	}

	public function getClientTypeIds(): array
	{
		return $this->clientTypeIds;
	}

	public function getCallTypeId(): ?int
	{
		return $this->callType?->value;
	}

	public function getAutoCheckTypeId(): ?int
	{
		return $this->autoCheckType?->value;
	}

	public function getAvailabilityType(): string
	{
		return $this->availabilityType->value;
	}

	public function setAvailabilityType(AvailabilityType $availabilityType): self
	{
		$this->availabilityType = $availabilityType;

		return $this;
	}

	public function getAvailabilityData(): array
	{
		return $this->availabilityData;
	}

	public function isEnabled(): bool
	{
		return $this->getAvailabilityType() !== AvailabilityType::INACTIVE->value;
	}

	public function isDefault(): bool
	{
		return $this->isDefault;
	}

	public function isAiImprovementEnabled(): bool
	{
		return $this->isAiImprovementEnabled;
	}

	public function setAiImprovementEnabled(bool $value): self
	{
		$this->isAiImprovementEnabled = $value;

		return $this;
	}

	public function getJobId(): int
	{
		return $this->jobId;
	}

	public function setJobId(int $jobId): self
	{
		$this->jobId = $jobId;

		return $this;
	}

	public function getStatus(): string
	{
		return $this->status;
	}

	public function setStatus(string $status): self
	{
		$this->status = $status;

		return $this;
	}

	public function getCode(): ?string
	{
		return $this->code;
	}

	public function setCode(?string $code): self
	{
		$this->code = $code;

		return $this;
	}

	public function getLowBorder(): int
	{
		return $this->lowBorder;
	}

	public function getHighBorder(): int
	{
		return $this->highBorder;
	}

	public function getCriteria(): ?array
	{
		return $this->criteria;
	}

	public function setCriteria(array $criteria): self
	{
		$this->criteria = $criteria;

		return $this;
	}
}
