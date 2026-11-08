<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

/**
 * A single construction inside a complex-node rule (DTO-01), symmetric to the domain
 * {@see \Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConstructionDto}.
 *
 * The kind is one of {@see self::TYPES}; conditions collapse the domain
 * `condition:if`/`condition:and`/`condition:or` into a single `condition` whose joiner lives in the
 * expression (DTO-03). `code`/`php` are not expressible in the allowlist by construction. The
 * type-specific payload is kept as a normalized array - the same shape the agent sent - which the
 * forward converter maps to the domain expression DTOs.
 */
final class AgentComplexConstruction implements Arrayable
{
	public const TYPE_CONDITION = 'condition';
	public const TYPE_ACTION = 'action';
	public const TYPE_FILTER = 'filter';
	public const TYPE_OUTPUT = 'output';

	/** @var list<string> closed allowlist of construction kinds */
	public const TYPES = [
		self::TYPE_CONDITION,
		self::TYPE_ACTION,
		self::TYPE_FILTER,
		self::TYPE_OUTPUT,
	];

	/**
	 * @param string $type one of {@see self::TYPES}
	 * @param array $expression type-specific payload: condition (DTO-03) / action / filter / output
	 */
	public function __construct(
		public readonly string $type,
		public readonly array $expression,
	) {}

	/**
	 * Output port id (oN) declared by an `output` construction; `null` for any other kind.
	 * Used to project the complex node's dynamic output ports from its own rules.
	 */
	public function getOutputPortId(): ?string
	{
		if ($this->type !== self::TYPE_OUTPUT)
		{
			return null;
		}

		$portId = $this->expression['portId'] ?? null;

		return is_string($portId) && $portId !== '' ? $portId : null;
	}

	/**
	 * Auxiliary (tool) port id (aN) an `action` construction binds via `auxPortId`; `null` for any other kind
	 * or an action without one. Like an `output` port it is a source endpoint of a connection, so it is part of
	 * the complex node's dynamic output-port projection used to validate links leaving the node.
	 */
	public function getAuxPortId(): ?string
	{
		if ($this->type !== self::TYPE_ACTION)
		{
			return null;
		}

		$portId = $this->expression['auxPortId'] ?? null;

		return is_string($portId) && $portId !== '' ? $portId : null;
	}

	public function toArray(): array
	{
		return [
			'type' => $this->type,
			$this->type => $this->expression,
		];
	}
}
