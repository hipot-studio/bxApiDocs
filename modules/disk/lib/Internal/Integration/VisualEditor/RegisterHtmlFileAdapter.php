<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Integration\VisualEditor;

use Bitrix\Disk\Public\Adapter\HtmlFileAdapter;
use Bitrix\Main\Event;
use Bitrix\VisualEditor\Public\Adapter\AdapterRegistry;

final class RegisterHtmlFileAdapter
{
	public static function handle(Event $event): void
	{
		$registry = $event->getParameter('registry');
		if ($registry instanceof AdapterRegistry)
		{
			$registry->register('disk.html-file', new HtmlFileAdapter());
		}
	}
}
