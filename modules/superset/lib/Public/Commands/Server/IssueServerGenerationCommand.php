<?php

namespace Bitrix\Superset\Public\Commands\Server;

use Bitrix\Main\Result;
use Bitrix\Superset\Internal\Services\ServerRuntimeService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Commands\Support\ServerResultDecorator;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class IssueServerGenerationCommand extends AbstractServerCommand
{
	public const FIELD_ISSUED = 'generationIssued';

	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly string $generation,
	)
	{
	}

	protected function execute(): Result
	{
		return ServerResultDecorator::decorateServerContextResult(
			(new ServerRuntimeService())->issueGenerationIfMissing(
				$this->resolveServer($this->server),
				$this->generation,
			)
		);
	}
}
