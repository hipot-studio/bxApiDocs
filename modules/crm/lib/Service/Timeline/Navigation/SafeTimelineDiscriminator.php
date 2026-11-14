<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Navigation;

final class SafeTimelineDiscriminator
{
	private const FIELDS = [
		'TYPE_ID',
		'TYPE_CATEGORY_ID',
		'ASSOCIATED_ENTITY_TYPE_ID',
		'ASSOCIATED_ENTITY_CLASS_NAME',
		'ACTIVITY_TYPE_ID',
		'PROVIDER_ID',
		'PROVIDER_TYPE_ID',
		'FIXED_MARKER',
	];

	private function __construct(private readonly array $fields)
	{
	}

	public static function supports(?string $field): bool
	{
		return $field !== null && in_array($field, self::FIELDS, true);
	}

	public static function fromStoredFields(array $fields): self
	{
		return new self(array_intersect_key($fields, array_flip(self::FIELDS)));
	}

	public function get(string $field): mixed
	{
		if (!self::supports($field))
		{
			throw new \InvalidArgumentException('Unsafe timeline discriminator field');
		}

		return $this->fields[$field] ?? null;
	}
}
