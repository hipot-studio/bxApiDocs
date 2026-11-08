<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\LastValues\Dto;

/**
 * A single captured field value. Serialized shape is the wire contract of the snapshot: it is stored
 * in VALUES_DATA as is and handed to the editor without renaming.
 */
final class CapturedValue implements \JsonSerializable
{
	/**
	 * @param string|int|float|bool|array $value scalar, a flat list of scalars or a list of document identifiers
	 * @param string $type bizproc field type
	 * @param bool $multiple when true, $value is a list
	 * @param bool $truncated value was cut by the capture limit
	 * @param int|null $totalCount number of captured items before the limit cut, null for a single value
	 */
	public function __construct(
		public readonly string|int|float|bool|array $value,
		public readonly string $type,
		public readonly bool $multiple = false,
		public readonly bool $truncated = false,
		public readonly ?int $totalCount = null,
	)
	{
	}

	public function jsonSerialize(): array
	{
		return [
			'value' => $this->value,
			'type' => $this->type,
			'multiple' => $this->multiple,
			'truncated' => $this->truncated,
			'totalCount' => $this->totalCount,
		];
	}
}
