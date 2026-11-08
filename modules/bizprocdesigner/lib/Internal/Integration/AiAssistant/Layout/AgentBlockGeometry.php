<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Layout;

use Bitrix\Bizproc\Activity\Enum\ActivityNodeType;
use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\ActivityRegistry;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlock;

/**
 * The real on-canvas size of a node by its widget type - the single backend mirror of the numbers the chart
 * widgets hardcode, so the frame-overlay geometry ({@see FrameGeometry}) matches what the user actually sees.
 *
 * The canvas sizes nodes from their widget, saved dimensions and port count. The numbers below stay the
 * measuring rule for frame membership: the forward converter and the post-layout
 * checker both measure members through this class, so the geometry that is validated is - by construction -
 * the geometry that is persisted.
 *
 * The widths are exact (from the widgets); the heights are an approximation of the widgets' port/rule layout.
 * Every number is a named constant calibrated on live template 406.
 */
final class AgentBlockGeometry
{
	// Widths by widget type. Source of truth: chart block widgets (block-simple/operator/complex).
	public const SIMPLE_WIDTH = 300;      // block-simple.js
	public const OPERATOR_WIDTH = 180;    // block-operator.js
	public const COMPLEX_WIDTH = 260;     // block-complex.js DEFAULT_BLOCK_WIDTH
	public const SWITCH_NODE_WIDTH = 180; // block-complex.js SWITCH_NODE_WIDTH

	// Heights. Simple/Trigger are fixed; operator/complex grow by a per-row step. Approximation of the
	// widgets' port/rule layout, calibrated on live template 406.
	public const SIMPLE_HEIGHT = 58;        // block-simple.js (fixed)
	public const OPERATOR_BASE_HEIGHT = 58;
	public const OPERATOR_ROW_HEIGHT = 36;
	public const COMPLEX_BASE_HEIGHT = 60;
	public const COMPLEX_ROW_HEIGHT = 60;
	public const SWITCH_NODE_MIN_RULES = 3; // block-complex.js SWITCH_NODE_MIN_RULES

	private const SWITCH_NODE_TYPE = 'switchnode';

	/**
	 * Full rendered size of a node by its classified type and row count (operator ports / complex rules).
	 * Pure - the single width/height formula the frame bounding box is built from.
	 *
	 * @return array{width: int, height: int}
	 */
	public static function sizeByType(
		ActivityNodeType $nodeType,
		bool $isComplex,
		bool $isSwitchNode,
		int $rowCount,
	): array
	{
		if ($isComplex)
		{
			$rows = $isSwitchNode ? max($rowCount, self::SWITCH_NODE_MIN_RULES) : $rowCount;

			return [
				'width' => $isSwitchNode ? self::SWITCH_NODE_WIDTH : self::COMPLEX_WIDTH,
				'height' => self::COMPLEX_BASE_HEIGHT + max(1, $rows) * self::COMPLEX_ROW_HEIGHT,
			];
		}

		if ($nodeType === ActivityNodeType::OPERATORS)
		{
			return [
				'width' => self::OPERATOR_WIDTH,
				'height' => self::OPERATOR_BASE_HEIGHT + max(0, $rowCount - 1) * self::OPERATOR_ROW_HEIGHT,
			];
		}

		return ['width' => self::SIMPLE_WIDTH, 'height' => self::SIMPLE_HEIGHT];
	}

	/**
	 * Full rendered rectangle size of an agent block: classifies it via the shared ActivityRegistry and
	 * counts its ports/rules the same way the forward converter builds them, so the converter and the
	 * post-layout checker measure every member identically (checked geometry == persisted geometry).
	 *
	 * @return array{width: int, height: int}
	 */
	public static function measure(AgentBlock $block, ActivityRegistry $registry): array
	{
		$isComplex = $registry->isComplexWrapper($block->type);
		$isSwitchNode = $isComplex && self::isSwitchNode($block->type);

		return self::sizeByType(
			$registry->getNodeType($block->type),
			$isComplex,
			$isSwitchNode,
			self::rowCount($block, $registry, $isComplex),
		);
	}

	/**
	 * Type-only rectangle used for the centre-inside membership hit-test ({@see FrameGeometry::contains}).
	 * Independent of the port/rule count: the reverse projection reconstructs membership from the saved
	 * template, where those counts are not readily available, so it must derive the identical block centre
	 * from the block type alone to keep the round-trip idempotent. Uses each type's base (smallest) height,
	 * whose centre always lies inside the member's full rectangle and therefore inside the frame bounding box.
	 *
	 * @return array{width: int, height: int}
	 */
	public static function hitTestSizeForType(string $blockType, ActivityRegistry $registry): array
	{
		$isComplex = $registry->isComplexWrapper($blockType);
		if ($isComplex)
		{
			return [
				'width' => self::isSwitchNode($blockType) ? self::SWITCH_NODE_WIDTH : self::COMPLEX_WIDTH,
				'height' => self::COMPLEX_BASE_HEIGHT,
			];
		}

		if ($registry->getNodeType($blockType) === ActivityNodeType::OPERATORS)
		{
			return ['width' => self::OPERATOR_WIDTH, 'height' => self::OPERATOR_BASE_HEIGHT];
		}

		return ['width' => self::SIMPLE_WIDTH, 'height' => self::SIMPLE_HEIGHT];
	}

	/**
	 * Whether a block type is the SwitchNode complex node (the narrow 180-wide "condition block").
	 * Case-insensitive: the catalog advertises a lowercased type while the agent may echo the class name.
	 */
	public static function isSwitchNode(string $blockType): bool
	{
		return mb_strtolower($blockType) === self::SWITCH_NODE_TYPE;
	}

	private static function rowCount(AgentBlock $block, ActivityRegistry $registry, bool $isComplex): int
	{
		if ($isComplex)
		{
			// A complex node grows with its branch (output) rules; null rules -> a bare wrapper.
			return $block->rules === null ? 0 : count($block->rules->getOutputPortIds());
		}

		// An operator grows with the taller of its two port columns (inputs left, outputs right).
		$inputs = 0;
		$outputs = 0;
		foreach ($registry->getDefaultPorts($block->type) as $port)
		{
			$portType = $port['type'] ?? '';
			if ($portType === 'input')
			{
				$inputs++;
			}
			elseif ($portType === 'output')
			{
				$outputs++;
			}
		}

		return max($inputs, $outputs);
	}
}
