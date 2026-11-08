<?php

namespace Bitrix\BIConnector\DataSourceConnector\Aggregation;

/**
 * Semantic flags a dialect publishes in capabilities (design §2.1).
 */
final class DialectSemantics
{
	public function __construct(
		public readonly bool $binaryStringOperations,
		public readonly bool $floatNanOrderingCompatible,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'binaryStringOperations' => $this->binaryStringOperations,
			'floatNanOrderingCompatible' => $this->floatNanOrderingCompatible,
		];
	}
}
