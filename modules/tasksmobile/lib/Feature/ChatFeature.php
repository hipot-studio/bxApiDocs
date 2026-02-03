<?php

declare(strict_types=1);

namespace Bitrix\TasksMobile\Feature;

use Bitrix\Main\Config\Option;
use Bitrix\Mobile\Config\FeatureFlag;

final class ChatFeature extends FeatureFlag
{
	public function isEnabled(): bool
	{
		return Option::get('tasksmobile', 'feature_chat_enabled', 'on') === 'on';
	}

	public function enable(): void
	{
		Option::set('tasksmobile', 'feature_chat_enabled', 'on');
	}

	public function disable(): void
	{
		Option::set('tasksmobile', 'feature_chat_enabled', 'off');
	}
}
