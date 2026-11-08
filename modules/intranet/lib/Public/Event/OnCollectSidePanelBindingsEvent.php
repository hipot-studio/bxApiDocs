<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Event;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class OnCollectSidePanelBindingsEvent extends Event
{
	public function __construct()
	{
		parent::__construct('intranet', 'onCollectSidePanelBindings');
	}

	public function addExtension(string $extension): void
	{
		$this->addResult(new EventResult(EventResult::SUCCESS, ['extension' => $extension]));
	}

	/**
	 * @return string[]
	 */
	public function getExtensions(): array
	{
		$extensions = [];

		foreach ($this->getResults() as $eventResult)
		{
			if ($eventResult->getType() === EventResult::ERROR)
			{
				continue;
			}

			$extension = $eventResult->getParameters()['extension'] ?? null;
			if (is_string($extension) && $extension !== '')
			{
				$extensions[] = $extension;
			}
		}

		return $extensions;
	}
}
