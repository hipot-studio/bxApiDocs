<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule;

use Bitrix\Main\Validation\Rule\NotEmpty;

class ActionExpressionDto extends BaseExpressionDto
{
	#[NotEmpty]
	public ?string $actionId;
	public ?array $rawActivityData;

	#[NotEmpty]
	public ?array $activityData;

	public ?string $document;

	public function __construct(
		?string $actionId,
		?array $rawActivityData,
		?array $activityData,
		?string $document,
	)
	{
		$this->actionId = $actionId;
		$this->rawActivityData = $rawActivityData;
		$this->activityData = $activityData;
		$this->document = $document;
	}

	public function jsonSerialize(): array
	{
		return [
			'actionId' => $this->actionId,
			'rawActivityData' => $this->rawActivityData,
			'activityData' => $this->activityData,
			'document' => $this->document,
		];
	}

	public static function fromArray(array $data): self
	{
		return new self(
			$data['actionId'] ?? null,
			$data['rawActivityData'] ?? null,
			$data['activityData'] ?? null,
			$data['document'] ?? null,
		);
	}
}
