<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application\Embedding;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Rest\Internal\Entity\Application\Embedding\Embedding;
use Bitrix\Rest\Internal\Entity\Application\Embedding\EmbeddingLanguage;
use Bitrix\Rest\Internal\Entity\Application\Embedding\EmbeddingLanguageCollection;
use Bitrix\Rest\Internal\Service\Application\Embedding\EmbeddingInstaller;

class AddEmbeddingCommandHandler
{
	public function __construct(private EmbeddingInstaller $installer = new EmbeddingInstaller())
	{
	}

	/**
	 * @throws PersistenceException
	 * @throws ArgumentException
	 */
	public function __invoke(AddEmbeddingCommand $command): bool
	{
		$languageCollection = new EmbeddingLanguageCollection();
		if ($command->languages !== null)
		{
			foreach ($command->languages as $languageId => $lang)
			{
				if (!is_string($languageId))
				{
					throw new ArgumentException('Invalid language key: expected string (e.g., "ru", "en")');
				}

				$languageCollection->add(
					new EmbeddingLanguage(
						embeddingId: 0,
						languageId: $languageId,
						title: $lang['title'] ?? '',
						description: $lang['description'] ?? '',
						groupName: $lang['groupName'] ?? '',
					),
				);
			}
		}

		$embedding = new Embedding(
			placement: $command->placement,
			handler: $command->handler,
			appId: $command->app->getId(),
			userId: $command->userId,
			title: $command->title,
			groupName: $command->groupName,
			description: $command->description,
			options: $command->options,
			languages: $languageCollection,
		);

		return $this->installer->install(
			app: $command->app,
			embedding: $embedding,
		);
	}
}
