<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex;

use JsonSerializable;

class PortRuleCollectionDto implements JsonSerializable
{
	public string $portId;
	/**
	 * @var array<RuleDto>
	 */
	public array $portRules;

	public function __construct(string $portId, array $portRules)
	{
		$this->portId = $portId;
		$this->portRules = $portRules;
	}

	public function jsonSerialize(): mixed
	{
		return [
			'portId' => $this->portId,
			'ruleCards' => $this->portRules,
		];
	}

	public static function fromArray(array $data): self
	{
		$rules = $data['ruleCards'] ?? [];

		$ruleDtoList = [];
		foreach ($rules as $rule)
		{
			$ruleDtoList[] = RuleDto::fromArray($rule);
		}

		return new self(
			$data['portId'],
			$ruleDtoList,
		);
	}
}
