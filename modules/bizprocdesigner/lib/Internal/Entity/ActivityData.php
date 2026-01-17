<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

class ActivityData implements Arrayable
{
	public function __construct(
		public readonly string $name = '',
		public readonly string $type = '',
		public readonly bool $activated = true,
		public readonly array $properties = [],
		public readonly ?array $children = null,
		public readonly array $returnProperties = [],
		public readonly ?string $document = null,
	) {}

	public function toArray(): array
	{
		$result = [
			'Name' => $this->name,
			'Type' => $this->type,
			'Activated' => $this->activated ? 'Y' : 'N',
			'Properties' => $this->properties,
			'ReturnProperties' => $this->returnProperties,
			'Document' => $this->document,
		];

		if (is_array($this->children))
		{
			$result['Children'] = $this->children;
		}

		return $result;
	}

	public static function createFromArray(array $data): self
	{
		$children = is_array($data['Children'] ?? null) ? $data['Children'] : null;

		return new self(
			name: (string)($data['Name'] ?? ''),
			type: (string)($data['Type'] ?? ''),
			activated: ($data['Activated'] ?? 'Y') === 'Y',
			properties: (array)($data['Properties'] ?? []),
			children: $children,
			returnProperties: (array)($data['ReturnProperties'] ?? []),
			document: isset($data['Document']) ? (string)$data['Document'] : null,
		);
	}
}
