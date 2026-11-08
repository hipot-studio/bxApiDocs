<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Embedding;

use Bitrix\Main;
use Bitrix\Rest\Exceptions\ArgumentException as RestArgumentException;
use Bitrix\Rest\Public\Dto\Embedding\EmbeddingLanguageDto;
use Bitrix\Rest\Public\Dto\Embedding\EmbeddingOwnerDto;
use Bitrix\Rest\Public\Exception\Embedding\EmbeddingAlreadyExistsException;
use Bitrix\Rest\Public\Exception\Embedding\EmbeddingBindingNotSupportedException;
use Bitrix\Rest\RestException;

final class BindEmbeddingCommand extends Main\Command\AbstractCommand
{
	/** @var list<EmbeddingLanguageDto> */
	public readonly array $languages;

	/** @param list<EmbeddingLanguageDto> $languages */
	public function __construct(
		public readonly EmbeddingOwnerDto $owner,
		public readonly string $placement,
		public readonly string $handler,
		public readonly int $targetUserId = 0,
		public readonly array $options = [],
		array $languages = [],
		public readonly ?int $iconId = null,
	)
	{
		$this->languages = $languages;
	}

	protected function execute(): Main\Result
	{
		$result = new Main\Result();

		try
		{
			$embeddingId = (new BindEmbeddingCommandHandler())($this);
			$result->setData(['embeddingId' => $embeddingId]);
		}
		catch (EmbeddingAlreadyExistsException|EmbeddingBindingNotSupportedException $exception)
		{
			$result->addError(new Main\Error($exception->getMessage(), $exception::ERROR_CODE));
		}
		catch (RestException $exception)
		{
			if ($exception instanceof RestArgumentException)
			{
				throw $exception;
			}

			$result->addError(new Main\Error($exception->getMessage(), $exception->getErrorCode()));
		}

		return $result;
	}
}
