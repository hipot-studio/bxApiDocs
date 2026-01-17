<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex;

use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConstructionDto;
use JsonSerializable;

class RuleDto implements JsonSerializable
{
	/**
	 * @param string $id
	 * @param list<ConstructionDto> $constructions
	 */
	public function __construct(
		public string $id,
		public array $constructions,
	)
	{
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'constructions' => $this->constructions,
		];
	}
	
	public static function fromArray(array $data): self
	{
		$constructionList = [];
		
		foreach ($data['constructions'] as $construction)
		{
			$constructionList[] = ConstructionDto::fromArray($construction);
		}

		return new self(
			$data['id'],
			$constructionList,
		);
	}
}
