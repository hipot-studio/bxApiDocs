<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application\Embedding;

use Bitrix\Main;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Validation\ValidationError;
use Bitrix\Rest\Exceptions\ArgumentException;
use Bitrix\Rest\Internal\Entity\Application\App;

class AddEmbeddingCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		public readonly App $app,
		public readonly int $currentUserId,
		public readonly string $placement,
		public readonly string $handler,
		public readonly int $userId = 0,
		public readonly ?string $title = null,
		public readonly ?string $description = null,
		public readonly ?string $groupName = null,
		public readonly ?array $options = null,
		public readonly ?array $languages = null,
	)
	{
	}

	/**
	 * @throws ArgumentException
	 * @throws PersistenceException
	 * @throws Main\ArgumentException
	 */
	protected function execute(): Main\Result
	{
		$result = new Main\Result();

		$result->setData([
			(new AddEmbeddingCommandHandler())($this),
		]);

		return $result;
	}

	protected function validate(): Main\Validation\ValidationResult
	{
		$result = parent::validate();

		if ($this->languages !== null)
		{
			foreach (array_keys($this->languages) as $key)
			{
				if (!is_string($key))
				{
					$result->addError(
						(new ValidationError(
							'Invalid language key: expected string (e.g., "ru", "en")',
						))->setCode('languages'),
					);

					break;
				}
			}
		}

		return $result;
	}
}
