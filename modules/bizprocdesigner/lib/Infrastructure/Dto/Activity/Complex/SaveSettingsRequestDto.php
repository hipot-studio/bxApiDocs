<?php

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex;

use JsonSerializable;

class SaveSettingsRequestDto implements JsonSerializable
{
	public function __construct(
		public readonly string $title,
		public readonly string $description,
		public readonly array $portRuleCollectionDictionary,
	){}

	public function jsonSerialize(): array
	{
		return [
			'title' => $this->title,
			'description' => $this->description,
			'rules' => $this->portRuleCollectionDictionary,
		];
	}
	
	public static function fromArray(array $array): self
	{
		return new self(
			$array['title'] ?? '',
			$array['description'] ?? '',
			$array['rules'] ?? [],
		);
	}
}
