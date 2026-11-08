<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Trait;

use Bitrix\Bizproc\Activity\Enum\ActivityType;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\BaseSettingsExpressionDto;

trait NodeActionResolver
{
	/**
	 * Decide whether a BASE_SETTINGS construction is bound to a node-action (node-action proxy mode)
	 * instead of being host-merged.
	 *
	 * A construction is treated as a node-action proxy when it carries an explicit actionId
	 * (primary signal, set by the editor/preset) OR when its backing activity type resolves to a
	 * node-action activity (defensive fallback for direct payloads). The node-level catalog
	 * membership of that action is enforced separately by ValidateSingleRuleCommand.
	 *
	 * @param BaseSettingsExpressionDto $expression
	 * @param string $backingActivityType Backing type resolved by the caller from its own payload
	 *   stage (activityData.Type after normalisation, rawActivityData.activityType before it).
	 */
	protected function isNodeActionBaseSettings(
		BaseSettingsExpressionDto $expression,
		string $backingActivityType,
	): bool
	{
		if ($expression->actionId !== null && $expression->actionId !== '')
		{
			return true;
		}

		return $this->isNodeActionBackingType($backingActivityType);
	}

	/**
	 * Whether a BASE_SETTINGS construction carries the settings of the host node itself rather than those of
	 * an action of another activity.
	 *
	 * Asked before {@see self::isNodeActionBaseSettings()} and answered by whose class the payload carries,
	 * because that predicate says only whether a class CAN serve as an action. An activity declaring both
	 * NODE and NODE_ACTION (IMNotifyActivity, the agent activities of aiassistant) is put on the canvas as a
	 * node of its own, and its own form would otherwise be read as a proxy of a foreign action: the settings
	 * would become a child activity and the node itself would stay an empty container of its own type - it
	 * would never pass its own validateProperties() and, being no composite, would never run that child.
	 *
	 * @param BaseSettingsExpressionDto $expression Construction payload; one bound to an action is never the
	 *   form of the host, whatever class it carries.
	 * @param string $backingActivityType Backing type resolved by the caller from its own payload stage.
	 * @param string $hostActivityType Type of the node the container belongs to.
	 */
	protected function isHostOwnBaseSettings(
		BaseSettingsExpressionDto $expression,
		string $backingActivityType,
		string $hostActivityType,
	): bool
	{
		if ($expression->actionId !== null && $expression->actionId !== '')
		{
			return false;
		}

		if ($backingActivityType === '' || $hostActivityType === '')
		{
			return false;
		}

		$searcher = Container::instance()->getActivitySearcherService();

		return $searcher->normalizeActivityCode($backingActivityType)
			=== $searcher->normalizeActivityCode($hostActivityType)
		;
	}

	protected function isNodeActionBackingType(string $activityType): bool
	{
		if ($activityType === '')
		{
			return false;
		}

		$description = Container::instance()->getActivitySearcherService()->searchByCode($activityType);
		if ($description === null)
		{
			return false;
		}

		return in_array(ActivityType::NODE_ACTION->value, $description->getType(), true);
	}
}
