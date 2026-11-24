<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\Bizproc\Activity\Enum\ActivityPortRole;
use Bitrix\BizprocDesigner\Internal\Entity\BlockTypeDetail;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlock;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentComplexConstruction;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSetting;

/**
 * Whether a block only sorts the flow of the graph, and whether a node is a place a condition could be moved into.
 *
 * Both answers are read off the shape of a block rather than off a list of types: branching blocks are installed by
 * modules, a portal carries the ones it carries, and a list written here would answer for a portal it was never run
 * on. Two shapes sort. A complex node sorts when its rules carry conditions and no work at all - an `action` or a
 * `filter` in them means moving the conditions out would leave that work behind, which is a different graph. An
 * ordinary block sorts when it carries a condition of its own AND has somewhere to sort into: several ways out, no
 * body of a loop among them, and not a trigger, which decides whether the process starts rather than where it goes.
 */
final class BranchNodeMatcher
{
	/** A block sorts nowhere while it has a single way out, whatever condition it carries. */
	private const MIN_BRANCH_OUTPUTS = 2;

	/**
	 * Both answers the shape of the block leaves open are asked for rather than handed over, and the order of the
	 * checks is the order of their cost: the ways out are counted off the description already in hand, while the
	 * condition kinds are a walk of the whole activity catalog
	 * ({@see \Bitrix\Bizproc\Integration\AiAssistant\ConditionSettingNames::all()}), so they are read last and
	 * only where they decide the answer. A complex node reads neither.
	 *
	 * @param \Closure(): list<string> $conditionSettingNames installed condition kinds by setting name
	 * @param \Closure(string): bool $isTrigger whether a type of block decides that the process starts
	 */
	public static function isBranch(
		AgentBlock $block,
		?BlockTypeDetail $detail,
		\Closure $conditionSettingNames,
		\Closure $isTrigger,
	): bool
	{
		if ($block->rules !== null)
		{
			return self::rulesOnlySort($block);
		}

		return count($detail?->getPortIds()['output'] ?? []) >= self::MIN_BRANCH_OUTPUTS
			&& !self::hasLoopBodyOutput($detail)
			&& !$isTrigger($block->type)
			&& self::carriesCondition($block, $conditionSettingNames())
		;
	}

	/**
	 * Whether a condition could live in this node instead of in a block before it. The environment of the node
	 * decides, and it is the same flag the server judges a condition construction by on save
	 * ({@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ValidateSingleRuleCommand}), so a target
	 * named here is a target the write would accept.
	 */
	public static function isConditionTarget(?BlockTypeDetail $detail): bool
	{
		return $detail?->complexDetail?->conditionSupported === true;
	}

	private static function rulesOnlySort(AgentBlock $block): bool
	{
		$carriesCondition = false;
		foreach ($block->rules->portRules as $rules)
		{
			foreach ($rules as $rule)
			{
				foreach ($rule->constructions as $construction)
				{
					if (
						$construction->type === AgentComplexConstruction::TYPE_ACTION
						|| $construction->type === AgentComplexConstruction::TYPE_FILTER
					)
					{
						return false;
					}

					$carriesCondition = $carriesCondition
						|| $construction->type === AgentComplexConstruction::TYPE_CONDITION
					;
				}
			}
		}

		return $carriesCondition;
	}

	/** @param list<string> $conditionSettingNames */
	private static function carriesCondition(AgentBlock $block, array $conditionSettingNames): bool
	{
		$wanted = array_map(static fn(string $name): string => mb_strtolower($name), $conditionSettingNames);
		foreach ($block->settings as $setting)
		{
			/** @var AgentSetting $setting */
			if (in_array(mb_strtolower($setting->name), $wanted, true))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The way into the body of a loop is an output with the LoopBody role ({@see BlockTypeDetail::getPortIds()}
	 * reads ids alone, so the roles are read here from the same ports it reads).
	 */
	private static function hasLoopBodyOutput(?BlockTypeDetail $detail): bool
	{
		foreach ($detail?->nodeSettings?->ports?->output?->ports ?? [] as $port)
		{
			if ($port->role === ActivityPortRole::LoopBody)
			{
				return true;
			}
		}

		return false;
	}
}
