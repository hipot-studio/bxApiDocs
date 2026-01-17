<?php

namespace Bitrix\Rest\V3\Attribute;

#[\Attribute]
class RelationToOne extends AbstractAttribute
{
	/**
	 * @param string $thisField
	 * @param string $refField
	 */
	public function __construct(
		public readonly string $thisField,
		public readonly string $refField,
	) {
	}
}
