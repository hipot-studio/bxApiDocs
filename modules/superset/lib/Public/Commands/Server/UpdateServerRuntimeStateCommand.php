<?php

namespace Bitrix\Superset\Public\Commands\Server;

use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Superset\Internal\Services\ServerRuntimeService;
use Bitrix\Superset\Public\Commands\Support\AbstractServerCommand;
use Bitrix\Superset\Public\Commands\Support\ServerResultDecorator;
use Bitrix\Superset\Public\Dto\ServerReferenceDto;

final class UpdateServerRuntimeStateCommand extends AbstractServerCommand
{
	public function __construct(
		public readonly ServerReferenceDto $server,
		public readonly array $fields,
	)
	{
	}

	protected function execute(): Result
	{
		$fields = $this->fields;
		if (isset($fields['dateStartAttempt']) && $fields['dateStartAttempt'] instanceof DateTime)
		{
			$fields['dateStartAttempt'] = $fields['dateStartAttempt']->format('Y-m-d H:i:s');
		}

		return ServerResultDecorator::decorateServerContextResult(
			(new ServerRuntimeService())->update(
				$this->resolveServer($this->server),
				$fields,
			)
		);
	}
}
