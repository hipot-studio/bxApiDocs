<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Mobile\Config\FeatureFlag;
use Bitrix\Main\Config\Option;

final class CallsFeature extends FeatureFlag
{
	public function isEnabled(): bool
	{
		return (bool)Option::get('call', 'call_list_enabled', false);
	}

	public function enable(): void
	{
		Option::set('call', 'call_list_enabled', true);
	}

	public function disable(): void
	{
		Option::delete('call', ['name' => 'call_list_enabled']);
	}
}
