<?php

namespace Bitrix\Crm\RepeatSale\Segment;

use Bitrix\Crm\RepeatSale\Segment\Entity\RepeatSaleSegment;
use Bitrix\Main\Localization\Loc;
use CCrmOwnerType;

final class SegmentItem
{
	private ?int $id;
	private string $title;
	private string $prompt;
	private bool $isEnabled = true;
	private bool $isSystem = false;
	private ?string $code = null;
	private ?string $baseSegmentCode = null;
	private int $entityTypeId = CCrmOwnerType::Deal;
	private int $entityCategoryId = 0;
	private string $entityStageId = '';
	private ?string $entityTitlePattern = null;
	private ?int $assignmentTypeId = 1;
	private ?int $callAssessmentId = null;
	private bool $isAiEnabled = true;
	private ?int $clientFound = null;
	private ?int $clientCoverage = null;
	private array $assignmentUserIds = [];

	public static function createFromEntity(RepeatSaleSegment $segmentItem): self
	{
		$instance = new self();

		$instance->id = $segmentItem->getId();
		$instance->title = $segmentItem->getTitle();
		$instance->prompt = $segmentItem->getPrompt();
		$instance->isEnabled = $segmentItem->getIsEnabled();
		$instance->isSystem = $segmentItem->getIsSystem();
		$instance->code = $segmentItem->getCode();
		$instance->baseSegmentCode = $segmentItem->getBaseSegmentCode();
		$instance->entityTypeId = $segmentItem->getEntityTypeId();
		$instance->entityCategoryId = $segmentItem->getEntityCategoryId();
		$instance->entityStageId = $segmentItem->getEntityStageId();
		$instance->entityTitlePattern = $segmentItem->getEntityTitlePattern();
		$instance->assignmentTypeId = $segmentItem->getAssignmentTypeId();
		$instance->callAssessmentId = $segmentItem->getCallAssessmentId();
		$instance->isAiEnabled = $segmentItem->getIsAiEnabled();
		$instance->clientFound = $segmentItem->getClientFound();
		$instance->clientCoverage = $segmentItem->getClientCoverage();

		foreach ( $segmentItem->getAssignmentUsers() as $assignmentUser)
		{
			$instance->assignmentUserIds[] = $assignmentUser->getUserId();
		}

		return $instance;
	}

	public static function createFromArray(array $data): self
	{
		$instance = new self();

		$instance->id = $data['id'] ?? null;
		$instance->title = $data['title'] ?? '';
		$instance->prompt = $data['prompt'] ?? '';
		$instance->isEnabled = $data['isEnabled'] ?? true;
		$instance->isSystem = $data['isSystem'] ?? false;
		$instance->code = $data['code'] ?? null;
		$instance->baseSegmentCode = $data['baseSegmentCode'] ?? null;
		$instance->entityTypeId = $data['entityTypeId'] ?? 0;
		$instance->entityCategoryId = $data['entityCategoryId'] ?? 0;
		$instance->entityStageId = $data['entityStageId'] ?? '';
		$instance->entityTitlePattern = $data['entityTitlePattern'] ?? null;
		$instance->assignmentTypeId = $data['assignmentTypeId'] ?? AssignmentType::byUser->value;
		$instance->callAssessmentId = $data['callAssessmentId'] ?? null;
		$instance->isAiEnabled = $data['isAiEnabled'] ?? true;
		$instance->clientFound = $data['clientFound'] ?? null;
		$instance->clientCoverage = $data['clientCoverage'] ?? null;
		$instance->assignmentUserIds = $data['assignmentUserIds'] ?? [];

		return $instance;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->title,
			'prompt' => $this->prompt,
			'description' => $this->getDescription(),
			'isEnabled' => $this->isEnabled,
			'code' => $this->code,
			'entityTypeId' => $this->entityTypeId,
			'entityCategoryId' => $this->entityCategoryId,
			'entityStageId' => $this->entityStageId,
			'entityTitlePattern' => $this->entityTitlePattern,
			'assignmentTypeId' => $this->assignmentTypeId,
			'callAssessmentId' => $this->callAssessmentId,
			'isAiEnabled' => $this->isAiEnabled,
			'clientFound' => $this->clientFound,
			'clientCoverage' => $this->clientCoverage,
			'assignmentUserIds' => $this->assignmentUserIds,
		];
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function isEnabled(): bool
	{
		return $this->isEnabled;
	}

	public function setIsEnabled(bool $isEnabled): self
	{
		$this->isEnabled = $isEnabled;

		return $this;
	}

	public function getSegmentCode(): ?SegmentCode
	{
		return SegmentCode::tryFrom($this->getCode());
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

	public function getEntityTypeId(): int
	{
		return $this->entityTypeId;
	}

	public function getEntityCategoryId(): int
	{
		return $this->entityCategoryId;
	}

	public function setEntityCategoryId(int $entityCategoryId): self
	{
		$this->entityCategoryId = $entityCategoryId;

		return $this;
	}

	public function getClientFound(): ?int
	{
		return $this->clientFound;
	}

	public function getClientCoverage(): ?int
	{
		return $this->clientCoverage;
	}

	public function setClientCoverage(?int $clientCoverage): self
	{
		$this->clientCoverage = $clientCoverage;

		return $this;
	}

	public function getPrompt(): string
	{
		return $this->prompt;
	}

	public function getEntityStageId(): string
	{
		return $this->entityStageId;
	}

	public function setEntityStageId(string $entityStageId): self
	{
		$this->entityStageId = $entityStageId;

		return $this;
	}

	public function getAssignmentUserIds(): array
	{
		return $this->assignmentUserIds;
	}

	public function setAssignmentUserIds(array $assignmentUserIds): self
	{
		$this->assignmentUserIds = $assignmentUserIds;

		return $this;
	}

	public function getEntityTitlePattern(): ?string
	{
		return $this->entityTitlePattern;
	}

	public function getAssignmentTypeId(): ?int
	{
		return $this->assignmentTypeId;
	}

	public function getCallAssessmentId(): ?int
	{
		return $this->callAssessmentId;
	}

	public function isAiEnabled(): bool
	{
		return $this->isAiEnabled;
	}

	public function setIsAiEnabled(bool $isEnabled): self
	{
		$this->isAiEnabled = $isEnabled;

		return $this;
	}

	public function isSystem(): bool
	{
		return $this->isSystem;
	}

	public function setIsSystem(bool $isSystem): self
	{
		$this->isSystem = $isSystem;

		return $this;
	}

	public function getBaseSegmentCode(): ?string
	{
		return $this->baseSegmentCode;
	}

	public function setBaseSegmentCode(?string $baseSegmentCode): self
	{
		$this->baseSegmentCode = $baseSegmentCode;

		return $this;
	}

	public function getDescription(): ?string
	{
		return match ($this->code)
		{
			SegmentCode::DEAL_EVERY_MONTH->value => Loc::getMessage('CRM_SEGMENT_ITEM_SYSTEM_DEAL_EVERY_MONTH'),
			SegmentCode::DEAL_EVERY_HALF_YEAR->value => Loc::getMessage('CRM_SEGMENT_ITEM_SYSTEM_DEAL_EVERY_HALF_YEAR'),
			SegmentCode::DEAL_EVERY_YEAR->value => Loc::getMessage('CRM_SEGMENT_ITEM_SYSTEM_DEAL_EVERY_YEAR'),
			SegmentCode::LOST_CLIENT->value => Loc::getMessage('CRM_SEGMENT_ITEM_SYSTEM_DEAL_LOST_MORE_12_MONTH'),
			SegmentCode::SLEEPING_CLIENT->value => Loc::getMessage('CRM_SEGMENT_ITEM_SYSTEM_LAST_ACTIVITY_LESS_12_MONTH'),
			SegmentCode::AI_SCREENING->value => Loc::getMessage('CRM_SEGMENT_ITEM_AI_SCREENING'),
			SegmentCode::AI_APPROVE->value => Loc::getMessage('CRM_SEGMENT_ITEM_AI_APPROVE'),
			default => null,
		};
	}
}
