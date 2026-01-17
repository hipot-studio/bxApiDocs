<?php

namespace Bitrix\Crm\Service\Timeline\Item\AI\CopilotButton;

use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Activity\Provider\RepeatSale;
use Bitrix\Crm\Service\Timeline\Context;
use Bitrix\Crm\Service\Timeline\Item\AI\CopilotButton\Type\CopilotButtonCall;
use Bitrix\Crm\Service\Timeline\Item\AI\CopilotButton\Type\CopilotButtonOpenLine;
use Bitrix\Crm\Service\Timeline\Item\AI\CopilotButton\Type\CopilotButtonRepeatSale;
use Bitrix\Crm\Service\Timeline\Item\AssociatedEntityModel;

final class ButtonFactory
{
	public static function create(AssociatedEntityModel $model, Context $context): ?BaseButton
	{
		$activityId =(int)($model->get('ID') ?? 0);
		if ($activityId <= 0)
		{
			return null;
		}

		$providerId = (string)($model->get('PROVIDER_ID') ?? '');

		return match ($providerId)
		{
			Call::getId() => new CopilotButtonCall($activityId, $context, $model),
			OpenLine::getId() => new CopilotButtonOpenLine($activityId, $context, $model),
			RepeatSale::getId() => new CopilotButtonRepeatSale($activityId, $context, $model),
			default => null,
		};
	}
}
