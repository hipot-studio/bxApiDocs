<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Deadline\Service;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Deadline\Command\Trait\SendPushTrait;
use Bitrix\Tasks\Deadline\Entity\DeadlineUserOption;
use Bitrix\Tasks\Deadline\Internals\Repository\DeadlineUserOptionRepositoryInterface;
use Bitrix\Tasks\V2\Public\Provider\StateFlagsProvider;

class DefaultDeadlineValueService
{
	use SendPushTrait;

	public function __construct(
		private readonly DeadlineUserOptionRepositoryInterface $deadlineUserOptionRepository,
		private readonly StateFlagsProvider $stateFlagsProvider,
		private readonly EffectiveDeadlineCalculator $effectiveDeadlineCalculator,
	)
	{
	}

	public function set(
		int $userId,
		int $defaultDeadlineInSeconds,
		bool $isExactDeadlineTime,
		DateTime $now,
	): ?DateTime
	{
		$deadlineUserOption = $this->deadlineUserOptionRepository->saveDefaultDeadlineValue(
			$userId,
			$defaultDeadlineInSeconds,
			$isExactDeadlineTime,
		);
		$defaultDeadline = $this->effectiveDeadlineCalculator->calculate(
			deadlineUserOption: $deadlineUserOption,
			matchesWorkTime: $this->stateFlagsProvider->getMatchesWorkTime($userId),
			now: $now,
		);
		$deadlineUserOptionData = $this->buildDeadlineUserOptionData($deadlineUserOption, $defaultDeadline);

		$this->sendDefaultDeadlineUpdatedPush(
			$userId,
			[
				'deadline' => $deadlineUserOption->defaultDeadlineInSeconds,
				'defaultDeadlineDate' => $deadlineUserOptionData['defaultDeadlineDate'],
				'canChangeDeadline' => $deadlineUserOption->canChangeDeadline,
				'maxDeadlineChangeDate' => $deadlineUserOption->maxDeadlineChangeDate ?: null,
				'maxDeadlineChanges' => $deadlineUserOption->maxDeadlineChanges,
				'requireDeadlineChangeReason' => $deadlineUserOption->requireDeadlineChangeReason,
				'deadlineUserOption' => $deadlineUserOptionData,
			],
		);

		return $defaultDeadline;
	}

	private function buildDeadlineUserOptionData(
		DeadlineUserOption $deadlineUserOption,
		?DateTime $defaultDeadline,
	): array
	{
		$defaultDeadlineDate = $defaultDeadline === null
			? null
			: (clone $defaultDeadline)->toUserTime()->format('Y-m-d H:i')
		;

		return [
			'id' => $deadlineUserOption->id,
			'userId' => $deadlineUserOption->userId,
			'defaultDeadlineInSeconds' => $deadlineUserOption->defaultDeadlineInSeconds,
			'isExactDeadlineTime' => $deadlineUserOption->isExactDeadlineTime,
			'skipNotificationPeriod' => $deadlineUserOption->skipNotificationPeriod->value,
			'skipNotificationStartDate' => $deadlineUserOption->skipNotificationStartDate,
			'defaultDeadlineDate' => $defaultDeadlineDate,
			'canChangeDeadline' => $deadlineUserOption->canChangeDeadline,
			'maxDeadlineChangeDate' => $deadlineUserOption->maxDeadlineChangeDate?->format(DateTime::getFormat()),
			'maxDeadlineChanges' => $deadlineUserOption->maxDeadlineChanges,
			'requireDeadlineChangeReason' => $deadlineUserOption->requireDeadlineChangeReason,
		];
	}
}
