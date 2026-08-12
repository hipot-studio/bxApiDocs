<?php

namespace Bitrix\Superset\Public\Commands\UnusedElements;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\UnusedElementsService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class DeleteUnusedElementsCommand extends AbstractServerCommand
{
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly array $elements,
	)
	{
	}

	protected function execute(): Result
	{
		return (new UnusedElementsService($this->resolveServer($this->server)))->delete($this->elements);
	}
}
