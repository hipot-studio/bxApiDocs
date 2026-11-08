<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Dictionary;

/**
 * A CRM field a form may be built of, named together with the entity owning it.
 *
 * The dictionary is flat on purpose: the entity of a field travels with the field itself, and a
 * tree over it is a view the client is free to build.
 *
 * @internal
 */
final readonly class EntityField
{
	/**
	 * @param list<array{id: string, label: string}> $items values of a field that enumerates them
	 */
	public function __construct(
		public string $name,
		public string $label,
		public string $type,
		public bool $required,
		public bool $multiple,
		public string $entityName,
		public string $entityLabel,
		public array $items = [],
	)
	{
	}

	/**
	 * @param array<mixed> $field {@see \Bitrix\Crm\WebForm\EntityFieldProvider::getFields()}
	 */
	public static function fromPlainField(array $field): self
	{
		return new self(
			name: (string)($field['name'] ?? ''),
			label: (string)($field['caption'] ?? ''),
			type: (string)($field['type'] ?? ''),
			required: (bool)($field['required'] ?? false),
			multiple: (bool)($field['multiple'] ?? false),
			entityName: (string)($field['entity_name'] ?? ''),
			entityLabel: (string)($field['entity_caption'] ?? ''),
			items: self::items($field),
		);
	}

	/**
	 * @param array<mixed> $field
	 * @return list<array{id: string, label: string}>
	 */
	private static function items(array $field): array
	{
		$items = $field['items'] ?? null;
		if (!is_array($items))
		{
			return [];
		}

		$values = [];
		foreach ($items as $item)
		{
			if (!is_array($item) || !isset($item['ID']))
			{
				continue;
			}

			$values[] = [
				'id' => (string)$item['ID'],
				'label' => (string)($item['VALUE'] ?? ''),
			];
		}

		return $values;
	}
}
