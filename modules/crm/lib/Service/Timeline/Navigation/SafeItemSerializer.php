<?php

namespace Bitrix\Crm\Service\Timeline\Navigation;

use Bitrix\Crm\Service\Timeline\Navigation\Visibility\ProjectionResult;

final class SafeItemSerializer
{
	public function serialize(ProjectionResult $projection): ?array
	{
		if (!$projection->isVisible())
		{
			return null;
		}
		if ($projection->disposition === ProjectionResult::FULL)
		{
			$source = $projection->source;
			if ($source instanceof \JsonSerializable)
			{
				return (array)$source->jsonSerialize();
			}
			if (is_array($source))
			{
				return $source;
			}
		}

		return $projection->safeDescriptor;
	}
}
