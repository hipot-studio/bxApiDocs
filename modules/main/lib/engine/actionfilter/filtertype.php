<?php

declare(strict_types=1);

namespace Bitrix\Main\Engine\ActionFilter;

enum FilterType: string
{
	case Filter = 'filters';
	case Prefilter = 'prefilters';
	case Postfilter = 'postfilters';

	case EnableFilter = '+filters';
	case DisableFilter = '-filters';

	case EnablePrefilter = '+prefilters';
	case DisablePrefilter = '-prefilters';

	case EnablePostfilter = '+postfilters';
	case DisablePostfilter = '-postfilters';

	public function isNegative(): bool
	{
		return in_array($this, [self::DisableFilter, self::DisablePrefilter, self::DisablePostfilter], true);
	}
}
