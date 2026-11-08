<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

/**
 * Nested rules projection of a complex node in the agent input (DTO-01): a "port -> rules ->
 * constructions" model mapped one-to-one onto the domain
 * {@see \Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\PortRuleDto} /
 * {@see \Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\RuleDto} so the server-side
 * assembly (forward converter) matches the manual editor path.
 *
 * Additive and optional: simple/operator nodes never carry it ({@see AgentBlock::$rules} stays null).
 * A complex node's ports are dynamic and derived from this projection - input ports are the map keys,
 * output ports are the port ids declared by `output` constructions - not from the static catalog
 * topology.
 */
final class AgentComplexRules implements Arrayable
{
	/**
	 * @param array<string, list<AgentComplexRule>> $portRules ordered map keyed by input port id (iN)
	 */
	public function __construct(
		public readonly array $portRules,
	) {}

	/**
	 * @return list<string> input ports carrying rules (iN) - the node's dynamic input ports
	 */
	public function getInputPortIds(): array
	{
		return array_keys($this->portRules);
	}

	/**
	 * @return list<string> distinct output ports (oN) declared by `output` constructions, in first-seen
	 *         order - the node's dynamic output ports
	 */
	public function getOutputPortIds(): array
	{
		$portIds = [];
		foreach ($this->portRules as $rules)
		{
			foreach ($rules as $rule)
			{
				foreach ($rule->constructions as $construction)
				{
					$portId = $construction->getOutputPortId();
					if ($portId !== null && !in_array($portId, $portIds, true))
					{
						$portIds[] = $portId;
					}
				}
			}
		}

		return $portIds;
	}

	/**
	 * @return list<string> distinct auxiliary (tool) ports (aN) bound by `action` constructions via
	 *         `auxPortId`, in first-seen order. They are additional source endpoints of the complex node
	 *         (a link leaves the node from an aux port, e.g. an AI tool branch), kept apart from the
	 *         `output` ports (oN) so the frontend port projection and geometry stay unchanged - only the
	 *         connections validator treats them as valid source ports.
	 */
	public function getAuxPortIds(): array
	{
		$portIds = [];
		foreach ($this->portRules as $rules)
		{
			foreach ($rules as $rule)
			{
				foreach ($rule->constructions as $construction)
				{
					$portId = $construction->getAuxPortId();
					if ($portId !== null && !in_array($portId, $portIds, true))
					{
						$portIds[] = $portId;
					}
				}
			}
		}

		return $portIds;
	}

	public function toArray(): array
	{
		$rules = [];
		foreach ($this->portRules as $portId => $portRuleList)
		{
			$rules[$portId] = array_map(
				static fn(AgentComplexRule $rule): array => $rule->toArray(),
				$portRuleList,
			);
		}

		return $rules;
	}
}
