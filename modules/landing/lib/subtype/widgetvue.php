<?php

namespace Bitrix\Landing\Subtype;

use Bitrix\Landing\Assets\Manager;
use Bitrix\Landing\Block;
use Bitrix\Landing\Repo;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\DOM\Document;
use Bitrix\Main\Web\Json;
use Bitrix\Rest\UsageStatTable;

/**
 * Block with Vue library
 */
class WidgetVue
{
	/**
	 * Prepare manifest.
	 * @param array $manifest Block's manifest.
	 * @param Block|null $block Block instance.
	 * @param array $params Additional params.
	 * @return array
	 */
	public static function prepareManifest(array $manifest, ?Block $block = null, array $params = []): array
	{
		if (!self::checkParams($block, $params))
		{
			return $manifest;
		}

		$manifest['nodes'] = [];
		$manifest['assets'] = [
			'ext' => ['landing.widgetvue'],
		];

		// todo: create manifest by white list
		// todo: which style?
		$manifest['style'] = [
			'block' => [
				'type' => ['widget'],
			],
		];

		$script = self::getVueScript($block, $params);
		if ($script)
		{
			$assets = Manager::getInstance();
			$assets->addString(
				"<script>{$script}</script>",
			);
		}

		// todo: or add inline to block? not in callback, always
		$langScript = self::getLangScript($block, $params);
		if ($langScript)
		{
			$assets = Manager::getInstance();
			$assets->addString(
				"<script>{$langScript}</script>",
			);
		}

		// add callbacks
		$manifest['callbacks'] = [
			'afterAdd' => function (Block $block) use ($params)
			{
				$content = $block->getContent();
				$protected = self::protectVueContentBeforeParse($content);

				$doc = new Document();
				try
				{
					$doc->loadHTML($content);
				}
				catch (\Exception $e) {}

				$rootNode = $doc->querySelector($params['rootNode']);
				if (!$rootNode)
				{
					return;
					// todo: error or what?
				}

				$newId = 'mp_widget' . $block->getId();
				$rootNode->setAttribute('id', $newId);

				$contentAfter = $doc->saveHTML();
				self::returnVueContentAfterParse($contentAfter, $protected);

				$block->saveContent($contentAfter);
				$block->save();
			},
		];

		return $manifest;
	}

	private static function checkParams (?Block $block, array $params): bool
	{
		if (!$block)
		{
			return false;
		}

		// todo: check exist node by dom
		if (!isset($params['rootNode']) || !is_string($params['rootNode']))
		{
			return false;
		}

		return true;
	}

	private static function getLangScript(Block $block, array $params): string
	{
		$script = '';
		$lang = Loc::getCurrentLang();
		if (
			is_array($params['lang'])
			&& isset($params['lang'][$lang])
			&& is_array($params['lang'][$lang])
		)
		{
			$phrases = Json::encode($params['lang'][$lang]);
			$script = "BX.message({$phrases});";
		}

		return $script;
	}

	private static function getVueScript(Block $block, array $params): string
	{
		$vueParams = [
			'blockId' => $block->getId(),
			'rootNode' => '#mp_widget' . $block->getId(),
		];

		if ($block->getRepoId())
		{
			$app = Repo::getAppInfo($block->getRepoId());
			if ($app['ID'])
			{
				$vueParams['appId'] = (int)$app['ID'];

				$vueParams['allowedByTariff'] = true;
				if ($app['PAYMENT_ALLOW'] !== 'Y')
				{
					$vueParams['allowedByTariff'] = false;
				}
			}
		}

		if (
			is_array($params['data'])
			&& !empty($params['data'])
		)
		{
			$vueParams['data'] = $params['data'];
		}

		if (
			isset($params['handler'])
			&& is_string($params['handler'])
			&& mb_strlen($params['handler']) > 0
		)
		{
			$vueParams['fetchable'] = true;
		}

		$vueParams= Json::encode($vueParams);

		return "
			BX.ready(function() {
				(new BX.Landing.WidgetVue(
					{$vueParams}
				)).mount();
			});
		";
	}

	/**
	 * HTML-parser broke some vue-constructions. Replace them before parse
	 * @param string $content
	 * @return array - array of replaced values
	 */
	private static function protectVueContentBeforeParse(string &$content): array
	{
		$protected = [];
		$replaces = [
			'/@click/' => 0,
			'/v-if="([^"]+)"/' => 1,
			'/v-for="([^"]+)"/' => 1,
		];

		foreach ($replaces as $pattern => $position)
		{
			$callback = function($matches) use (&$protected, $position) {
				$replace = '__bxreplace' . count($protected);
				$protected[$replace] = $matches[$position];

				return ($position === 0)
					? $replace
					: str_replace($matches[$position], $replace, $matches[0]);
			};

			$content = preg_replace_callback($pattern, $callback, $content);
		}

		return $protected;
	}

	/**
	 * Return replaced vue-constructions after html-parse
	 * @param string $content
	 * @param array $protected
	 * @return void
	 */
	private static function returnVueContentAfterParse(string &$content, array $protected): void
	{
		$content = str_replace(array_keys($protected), array_values($protected), $content);
	}
}
