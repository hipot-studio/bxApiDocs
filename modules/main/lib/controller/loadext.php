<?php

namespace Bitrix\Main\Controller;

use Bitrix\Main\Engine;
use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\UI\Extension\Serializer;

/**
 * Class LoadExt
 * @package Bitrix\Main\Controller
 */
class LoadExt extends Engine\Controller
{
	/**
	 * Configures ajax actions
	 * @return array
	 */
	public function configureActions()
	{
		return [
			'getExtensions' => [
				'+prefilters' => [
					new CloseSession()
				],
				'-prefilters' => [
					Authentication::class
				]
			],
			'resolveExtensions' => [
				'+prefilters' => [
					new CloseSession()
				],
				'-prefilters' => [
					Authentication::class
				]
			]
		];
	}

	/**
	 * @param array $extension
	 * @return array
	 * @throws \Bitrix\Main\IO\FileNotFoundException
	 * @throws \Bitrix\Main\LoaderException
	 */
	public function getExtensionsAction($extension = [])
	{
		$result = [];

		if (!empty($extension) && is_array($extension))
		{
			foreach ($extension as $key => $item)
			{
				$result[] = [
					'extension' => $item,
					'config' => Extension::getBundleConfig($item),
					'html' => Extension::getHtml($item),
				];
			}
		}

		return $result;
	}

	/**
	 * Resolves a batch of extensions into an ordered, deduplicated list of
	 * structured descriptions instead of pre-rendered HTML.
	 *
	 * @param string[] $extension
	 * @param string $coreLoaded 'Y' when the client already runs the full core
	 *     bundle; anything else (including requests of older client bundles that
	 *     do not send the flag) keeps the core assets in the response, and the
	 *     client deduplicates them by URL.
	 * @param string[] $loadedExtension Extensions the client already has
	 *     registered; their branches are left out of the response. An empty list
	 *     (older client bundles do not send it) resolves the whole graph.
	 * @return array|null
	 */
	public function resolveExtensionsAction(
		array $extension = [],
		string $coreLoaded = 'N',
		array $loadedExtension = [],
	): ?array
	{
		$result = (new Serializer())->resolve($extension, $coreLoaded === 'Y', $loadedExtension);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $result->getData();
	}
}
