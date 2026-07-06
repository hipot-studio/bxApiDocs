<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Application\Embedding;

use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Rest\Api\Placement;
use Bitrix\Rest\Internal\Entity\Application\App;
use Bitrix\Rest\Internal\Entity\Application\Embedding\Embedding;
use Bitrix\Rest\Internal\Entity\Application\Embedding\EmbeddingLanguage;
use Bitrix\Rest\Internal\Entity\Application\Embedding\EmbeddingLanguageCollection;
use CRestServer;
use ReflectionClass;

class EmbeddingInstaller
{
	use EmbeddingLegacyTrait;

	/**
	 * @throws PersistenceException
	 */
	public function install(App $app, Embedding $embedding): bool
	{
		return $this->installLegacy($app, $embedding);
	}

	private function installLegacy(App $app, Embedding $embedding): bool
	{
		$server = new CRestServer([]);
		$reflectedServer = new ReflectionClass($server);

		$this->setServerServiceDescription($server, $reflectedServer);
		$this->setServerAuthData($server, $reflectedServer, $app->getScope());
		$this->setServerClientId($server, $reflectedServer, $app->getClientId());

		$convertedParams = $this->convertParams($embedding);

		$installResult = Placement::bind($convertedParams, null, $server);
		if (is_array($installResult))
		{
			throw new PersistenceException(
				(string)($installResult['error_description'] ?? 'Unable to set placement handler'),
			);
		}

		return $installResult;
	}

	private function convertParams(Embedding $embedding): array
	{
		return [
			'PLACEMENT' => $embedding->getPlacement(),
			'HANDLER' => $embedding->getHandler(),
			'OPTIONS' => $embedding->getOptions(),
			'USER_ID' => $embedding->getUserId(),
			'TITLE' => $embedding->getTitle(),
			'DESCRIPTION' => $embedding->getDescription(),
			'GROUP_NAME' => $embedding->getGroupName(),
			'LANG_ALL' => $this->convertLanguages($embedding->getLanguages()),
		];
	}

	private function convertLanguages(EmbeddingLanguageCollection $languageCollection): array
	{
		$result = [];
		foreach ($languageCollection as $language)
		{
			/**
			 * @var EmbeddingLanguage $language
			 */
			$result[$language->getLanguageId()] = [
				'TITLE' => $language->getTitle(),
				'DESCRIPTION' => $language->getDescription(),
				'GROUP_NAME' => $language->getGroupName(),
			];
		}

		return $result;
	}
}
