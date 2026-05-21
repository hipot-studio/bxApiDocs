<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action;

use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\Type\ConfirmFields;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\Type\FillFieldsInCall;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\Type\FillFieldsInChat;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\Type\FillRepeatSaleTips;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\Type\ScoreCall;
use Bitrix\Crm\Service\Timeline\Item\AssociatedEntityModel;

final class AIActionRegistry
{
	/** @var class-string<AIAction>[] */
	private const ACTION_CLASSES = [
		FillFieldsInCall::class,
		FillFieldsInChat::class,
		ScoreCall::class,
		ConfirmFields::class,
		FillRepeatSaleTips::class,
	];

	private function __construct() {}

	/**
	 * Creates AI action by scenario name and activity provider.
	 * Returns null if scenario is not supported or required data is missing.
	 */
	public static function create(
		string $scenario,
		int $activityId,
		Context $context,
		?AssociatedEntityModel $model = null
	): ?AIAction
	{
		if (!$model)
		{
			return null;
		}

		$providerId = (string)($model->get('PROVIDER_ID') ?? '');

		foreach (self::ACTION_CLASSES as $class)
		{
			if (
				$class::getScenario() === $scenario
				&& in_array($providerId, $class::getSupportedProviders(), true)
			)
			{
				return new $class($activityId, $context, $model);
			}
		}

		return null;
	}
}
