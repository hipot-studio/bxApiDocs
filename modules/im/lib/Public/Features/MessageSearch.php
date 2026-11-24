<?php
declare(strict_types=1);

namespace Bitrix\Im\Public\Features;

use Bitrix\Im\V2\Pull\Event\UpdateFeature;

final class MessageSearch extends LegacyFeatureFlag
{
	protected function onEnable(): void
	{
		(new UpdateFeature('messageSearchAvailable', true))->send();
	}

	protected function onDisable(): void
	{
		(new UpdateFeature('messageSearchAvailable', false))->send();
	}
}
