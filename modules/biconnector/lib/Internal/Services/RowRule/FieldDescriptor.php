<?php

namespace Bitrix\BIConnector\Internal\Services\RowRule;

/**
 * A field of a table description as row rules see it.
 */
final class FieldDescriptor
{
	/**
	 * @param string $code Key of the field in the table description.
	 * @param string $name Human-readable name, the code when the description has none.
	 * @param string $type Field type, resolved the same way as for the data output.
	 * @param string $kind '' for an ordinary field or one of the FieldKind kinds.
	 * @param string|null $ownerField Employee field whose department a department field holds.
	 * @param bool $isSupported Whether a rule may be set on the field.
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $name,
		public readonly string $type,
		public readonly string $kind,
		public readonly ?string $ownerField,
		public readonly bool $isSupported,
	)
	{
	}
}
