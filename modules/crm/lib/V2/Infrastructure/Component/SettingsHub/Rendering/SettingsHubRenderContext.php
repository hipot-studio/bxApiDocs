<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Component\SettingsHub\Rendering;

use Bitrix\Main\Page\Asset;
use CMain;

final class SettingsHubRenderContext
{
	public function __construct(
		public readonly CMain $application,
		public readonly Asset $asset,
	)
	{
	}
}
