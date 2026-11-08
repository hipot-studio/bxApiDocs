<?php

declare(strict_types=1);

namespace Bitrix\CrmMobile\Kanban\View;

enum ItemViewType: string
{
	case Default = 'default';
	case Compact = 'compact';

	public static function fromStringOrDefault(?string $value): self
	{
		if ($value === null)
		{
			return self::Default;
		}

		return self::tryFrom($value) ?? self::Default;
	}
}
