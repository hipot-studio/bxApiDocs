<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application\Embedding;

use Bitrix\Main;
use Bitrix\Rest\Exceptions\ArgumentNullException;
use Bitrix\Rest\Exceptions\ArgumentTypeException;
use Bitrix\Rest\Internal\Service\Application\Embedding\EmbeddingUninstaller;

class DeleteEmbeddingCommandHandler
{
	public function __construct(private EmbeddingUninstaller $uninstaller = new EmbeddingUninstaller())
	{
	}

	/**
	 * @throws ArgumentNullException
	 * @throws ArgumentTypeException
	 */
	public function __invoke(DeleteEmbeddingCommand $command): int
	{
		return $this->uninstaller->uninstall(
			app: $command->app,
			placement: $command->placement,
			handler: $command->handler,
			userId: $command->userId,
		);
	}
}
