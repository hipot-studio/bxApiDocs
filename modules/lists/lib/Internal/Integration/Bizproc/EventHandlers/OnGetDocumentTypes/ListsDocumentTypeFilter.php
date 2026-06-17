<?php

declare(strict_types=1);

namespace Bitrix\Lists\Internal\Integration\Bizproc\EventHandlers\OnGetDocumentTypes;

use Bitrix\Bizproc\Public\Event\Document\OnGetDocumentTypeEvent\DocumentTypeFilter;

class ListsDocumentTypeFilter extends DocumentTypeFilter
{
	protected array $parameters = [];

	public function __construct()
	{
		$this->parameters = [
			'onlyProcesses' => false,
			'onlyLists' => false,
			'onlySocNetLists' => false,
		];
	}

	public function loadFromArray(array $parameters): void
	{
		$this->parameters = [
			'onlyProcesses' => isset($parameters['onlyProcesses']) && (bool)$parameters['onlyProcesses'],
			'onlyLists' => isset($parameters['onlyLists']) && (bool)$parameters['onlyLists'],
			'onlySocNetLists' => isset($parameters['onlySocNetLists']) && (bool)$parameters['onlySocNetLists'],
		];
	}

	public function isOnlyProcesses(): bool
	{
		return (bool)($this->parameters['onlyProcesses'] ?? false);
	}

	public function isOnlyLists(): bool
	{
		return (bool)($this->parameters['onlyLists'] ?? false);
	}

	public function isOnlySocNetLists(): bool
	{
		return (bool)($this->parameters['onlySocNetLists'] ?? false);
	}
}
