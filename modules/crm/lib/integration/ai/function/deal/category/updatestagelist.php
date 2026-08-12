<?php

namespace Bitrix\Crm\Integration\AI\Function\Deal\Category;

use Bitrix\Crm\Integration\AI\Contract\AIFunction;
use Bitrix\Crm\Integration\AI\Function\Category\Stage\UpdateList;
use Bitrix\Crm\Integration\AI\Function\Deal\Dto\Category\Stage;
use Bitrix\Crm\Integration\AI\Function\Deal\Dto\Category\UpdateStageListParameters;
use Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics\Stage\CreateEvent;
use Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics\Stage\DeleteEvent;
use Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics\Stage\RenameEvent;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\Result;
use CCrmOwnerType;

final class UpdateStageList implements AIFunction
{
	public function __construct(
		private readonly int $currentUserId,
	)
	{
	}

	public function isAvailable(): bool
	{
		return true;
	}

	public function invoke(...$args): Result
	{
		$parameters = new UpdateStageListParameters($args);
		if ($parameters->hasValidationErrors())
		{
			return Result::fail($parameters->getValidationErrors());
		}

		$stagesAsArrays = array_map(
			static fn(Stage $stage) => ['name' => $stage->name, 'color' => $stage->color],
			$parameters->stages,
		);

		$operation = new UpdateList(currentUserId: $this->currentUserId);
		$result = $operation->invoke(
			entityTypeId: CCrmOwnerType::Deal,
			categoryId: $parameters->categoryId,
			stages: $stagesAsArrays,
		);

		$changeCounts = $result->getData()['changeCounts'] ?? null;
		$status = $result->isSuccess() ? Dictionary::STATUS_SUCCESS : Dictionary::STATUS_ERROR;
		$this->sendAnalyticsEvents($changeCounts, $status);

		return $result;
	}

	private function sendAnalyticsEvents(?array $changeCounts, string $status): void
	{
		if ($changeCounts === null)
		{
			return;
		}

		if ($changeCounts['added'] > 0)
		{
			(new CreateEvent(section: Dictionary::SECTION_AI, count: $changeCounts['added']))
				->setStatus($status)
				->buildEvent()
				->send()
			;
		}

		if ($changeCounts['renamed'] > 0)
		{
			(new RenameEvent(section: Dictionary::SECTION_AI, count: $changeCounts['renamed']))
				->setStatus($status)
				->buildEvent()
				->send()
			;
		}

		if ($changeCounts['deleted'] > 0)
		{
			(new DeleteEvent(section: Dictionary::SECTION_AI, count: $changeCounts['deleted']))
				->setStatus($status)
				->buildEvent()
				->send()
			;
		}
	}
}
