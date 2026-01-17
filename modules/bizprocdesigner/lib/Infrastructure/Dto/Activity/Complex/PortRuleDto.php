<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex;

use JsonSerializable;

class PortRuleDto implements JsonSerializable
{
	/**
	 * @param string $portId
	 * @param list<RuleDto> $rules
	 */
	public function __construct(
		public string $portId,
		public array $rules,
	)
	{
		
	}

	public function jsonSerialize(): array
	{
		return [
			'portId' => $this->portId,
			'ruleCards' => $this->rules,
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
			portId: $data['portId'],
			rules: $ruleDtoList,
		);
	}
}
