<?php

declare(strict_types=1);

namespace Bitrix\BIConnectorMobile\Filter;

use Bitrix\Mobile;

final class FieldDescriptorNormalizer extends Mobile\Filter\FieldDescriptorNormalizer
{
	protected function getEntityMap(): array
	{
		// Only the author field has a mobile picker. The four BI selectors
		// (biconnector-superset-dashboard, -dashboard-tag, -group, -scope) are left unmapped
		// on purpose: EntitySelectorFactory knows no BI type, so those fields stay locked -
		// the value shows and applies, the row does not open for editing.
		// biconnector-superset-params needs no picker: its dialog carries the values themselves.
		return [
			'user' => 'user',
		];
	}
}
