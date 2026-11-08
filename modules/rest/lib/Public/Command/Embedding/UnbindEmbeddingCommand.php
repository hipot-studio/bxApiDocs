<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Embedding;

use Bitrix\Main;
use Bitrix\Rest\Public\Exception\Embedding\EmbeddingNotFoundException;

final class UnbindEmbeddingCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		public readonly int $embeddingId,
	)
	{
	}

	protected function execute(): Main\Result
	{
		$result = new Main\Result();

		try
		{
			(new UnbindEmbeddingCommandHandler())($this);
		}
		catch (EmbeddingNotFoundException $exception)
		{
			$result->addError(new Main\Error($exception->getMessage(), $exception::ERROR_CODE));
		}

		return $result;
	}
}
