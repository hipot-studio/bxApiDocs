<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Notification;

use Bitrix\Socialnetwork\Collab\Integration\IM\ActionType;
use Bitrix\Socialnetwork\V2\Internal\Entity\Notification\NotificationType;

/**
 * Resolves the counter/toast policy for a notification type in a given project.
 *
 * ALG-01 (NORMATIVE):
 *   resolve(typeId, projectId) -> CounterDecision
 *     type = registry.findById(typeId)           — or findByActionType for member
 *     if type == null: return Passthrough         — type outside registry
 *     if not gate.isAvailable(projectId): return Passthrough  — not a converted project
 *     effective = type.default
 *     overrides = optionReader.read(projectId)   — wire-key -> bool map
 *     if overrides.has(type.wireKey): effective = overrides.get(type.wireKey)
 *     return effective ? CounterOn : CounterOff
 *
 * The resolver only returns a decision — it does not modify pipelines (phases 4-5).
 * Passthrough = "feature does not intervene"; caller keeps its pre-feature behaviour.
 */
class CounterPolicyResolver
{
	public function __construct(
		private readonly NotificationAvailabilityService $availabilityGate,
		private readonly ProjectNotificationSettingsService $settings,
	)
	{
	}

	/**
	 * Resolves the counter policy by notification type id.
	 * Use this for feed-side notifications.
	 */
	public function resolveById(string $typeId, int $projectId): CounterDecision
	{
		$type = NotificationTypeRegistry::findById($typeId);

		return $this->resolveForType($type, $projectId);
	}

	/**
	 * Resolves the counter policy by ActionType from the member pipeline.
	 * Returns Passthrough for ActionTypes outside the configurable registry.
	 */
	public function resolveByActionType(ActionType $actionType, int $projectId): CounterDecision
	{
		$type = NotificationTypeRegistry::findByActionType($actionType);

		return $this->resolveForType($type, $projectId);
	}

	private function resolveForType(?NotificationType $type, int $projectId): CounterDecision
	{
		if ($type === null)
		{
			return CounterDecision::Passthrough;
		}

		if (!$this->availabilityGate->isAvailable($projectId))
		{
			return CounterDecision::Passthrough;
		}

		$effective = $this->settings->getEffectiveCounter($projectId, $type);

		return $effective ? CounterDecision::CounterOn : CounterDecision::CounterOff;
	}
}
