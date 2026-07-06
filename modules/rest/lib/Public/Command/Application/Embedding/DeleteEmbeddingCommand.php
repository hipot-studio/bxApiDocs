<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application\Embedding;

use Bitrix\Main;
use Bitrix\Rest\Internal\Entity\Application\App;
use Bitrix\Rest\RestException;

class DeleteEmbeddingCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		public readonly App $app,
		public readonly int $currentUserId,
		public readonly string $placement,
		public readonly ?string $handler,
		public readonly ?int $userId,
	)
	{
	}

	protected function execute(): Main\Result
	{
		$result = new Main\Result();

		try
		{
			$result->setData([
				'deleted' => (new DeleteEmbeddingCommandHandler())($this),
			]);
		}
		catch(RestException $e)
		{
			$result->addError(new Main\Error($e->getMessage(), $e->getErrorCode()));
		}

		return $result;
	}
}
