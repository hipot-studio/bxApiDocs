<?php

namespace Bitrix\Mobile\Internal\Dto\Project;

final class ProjectSaveResultDto implements \JsonSerializable
{
	private const RESULT_STATUS_SUCCESS = 'success';

	public function __construct(
		public readonly int $projectId,
		public readonly ?int $chatId = null,
		public readonly string $resultStatus = self::RESULT_STATUS_SUCCESS,
		public readonly ?bool $isTrialTurnedOn = null,
	)
	{
	}

	public function withIsTrialTurnedOn(bool $isTrialTurnedOn): self
	{
		return new self(
			projectId: $this->projectId,
			chatId: $this->chatId,
			resultStatus: $this->resultStatus,
			isTrialTurnedOn: $isTrialTurnedOn,
		);
	}

	public function jsonSerialize(): array
	{
		return array_filter([
			'resultStatus' => $this->resultStatus,
			'projectId' => $this->projectId,
			'chatId' => $this->chatId,
			'isTrialTurnedOn' => $this->isTrialTurnedOn,
		], static fn(mixed $value): bool => $value !== null);
	}
}
