<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Service\Activity;

use Bitrix\Bizproc\Activity\ActivityDescription;
use Bitrix\Bizproc\Activity\Enum\NodeBlockType;
use Bitrix\Bizproc\Internal\Service\Container as BizprocContainer;
use Bitrix\BizprocDesigner\Internal\Config\Feature;

/**
 * Single answer to "is the unified panel the surface serving this node", shared by every controller that
 * has to know it: the settings endpoints of the panel and the controls endpoint building the properties
 * map of a node.
 *
 * The rollout option belongs to this module ({@see Feature}), which is why the composition lives here and
 * not next to the verdict of bizproc it completes. Stateless and deliberately left out of the container:
 * there is no dependency worth substituting, and a second place answering this question is exactly what
 * the class exists to prevent.
 */
final class UnifiedPanelSurfaceAvailability
{
	/**
	 * Rollout gate of the panel, applied to every surface of it alike: a node whose complex-settings
	 * descriptor was completed for it - a trigger, a base node - is served by the panel only while the
	 * feature is rolled out, so with the flag off the editor stays on the legacy form of that node and the
	 * server refuses the unified settings a client would send for it. A complex node, declaring its
	 * descriptor itself, is not what the flag decides about, and settings the panel saved earlier keep
	 * running and keep publishing either way
	 * ({@see \Bitrix\Bizproc\Internal\Service\Activity\UnifiedPanelDescriptorProvider::isSurfaceAvailableForNode()}).
	 */
	public static function isAvailableForNode(string $activityCode, ActivityDescription $nodeDescription): bool
	{
		return BizprocContainer::instance()->getUnifiedPanelDescriptorProvider()->isSurfaceAvailableForNode(
			$activityCode,
			$nodeDescription,
			Feature::instance()->areComplexNodeConnectionsAvailable(),
		);
	}

	/**
	 * Whether the external condition block is offered to this node, and therefore whether the node may leave
	 * a condition property of its own out of the form it builds. Published in the properties map context.
	 *
	 * Neither half is the verdict on its own: taking the descriptor alone would announce the block on a
	 * portal where the panel is off and no external block exists at all.
	 */
	public static function isConditionBlockAvailableForNode(
		string $activityCode,
		?ActivityDescription $nodeDescription,
	): bool
	{
		$nodeSettings = $nodeDescription?->getComplexActivitySettings();
		if ($nodeDescription === null || $nodeSettings === null)
		{
			return false;
		}

		// A node declaring no block composition falls back to the set bizproc offers by default, where the
		// condition block is available - the same reading the capability catalog publishes.
		$conditionOffered = $nodeSettings->availableBlocks?->isAvailable(NodeBlockType::CONDITION) ?? true;

		return $conditionOffered && self::isAvailableForNode($activityCode, $nodeDescription);
	}
}
