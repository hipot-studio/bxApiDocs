<?php

namespace Bitrix\Landing\Controller;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response;
use Bitrix\Main\Error;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Landing;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Assets;
use Bitrix\Rest;

class Vibe extends Controller
{
	private const SUBTYPE_WIDGET = 'widgetvue';
	// seconds of inactivity, not of the whole request
	private const HANDLER_TIMEOUT = 5;
	private const HANDLER_BODY_LENGTH_MAX = 1024 * 1024;

	// codes HttpClient reports when it refuses the destination itself, see HttpClient::checkRequest()
	private const HANDLER_ADDRESS_ERROR_CODES = ['PRIVATE_IP', 'URI_SCHEME', 'URI_HOST', 'URI_PUNICODE'];

	/**
	 * Get core extensions and styles configs, load relations, load lang phrases
	 *
	 * @return array - array of assets by type
	 */
	public function getCoreConfigAction(): array
	{
		$coreExts = [
			'main.core',
			'ui.design-tokens',
		];

		$assetsManager = (new Assets\Manager())
			->enableSandbox()
			->addAsset($coreExts)
		;

		$siteTemplatePath
			= (defined('SITE_TEMPLATE_PATH') ? SITE_TEMPLATE_PATH : '/bitrix/templates/bitrix24');
		$style = $siteTemplatePath . '/dist/bitrix24.bundle.css';
		$assetsManager->addAsset($style);

		return $assetsManager->getOutput();
	}

	/**
	 * Get extensions configs, load relations, load lang phrases
	 *
	 * @param array $extCodes - array of extensions codes
	 * @return array - array of assets by type
	 */
	public function getAssetsConfigAction(array $extCodes): array
	{
		$assetsManager = (new Assets\Manager())
			->enableSandbox()
			->addAsset($extCodes)
		;

		return $assetsManager->getOutput();
	}

	/**
	 * Fetches widget data for a block (HTTP request to manifest handler).
	 *
	 * @param int $blockId Block ID
	 * @param array $params Request payload
	 */
	public function fetchDataAction(int $blockId, array $params = [])
	{
		$block = new Landing\Block($blockId);
		if (!$block->getId())
		{
			$this->addError(
				new Error(Loc::getMessage('LANDING_WIDGET_BLOCK_NOT_FOUND'), 'BLOCK_NOT_FOUND'),
			);

			return null;
		}

		if (!Loader::includeModule('rest'))
		{
			$this->addError(
				new Error(Loc::getMessage('LANDING_WIDGET_REST_NOT_FOUND'), 'REST_NOT_FOUND'),
			);

			return null;
		}

		// check app
		$repoId = $block->getRepoId();
		$app = Landing\Repo::getAppInfo($repoId);
		if (
			!$repoId
			|| empty($app)
			|| !isset($app['CLIENT_ID'])
		)
		{
			$this->addError(
				new Error(Loc::getMessage('LANDING_WIDGET_APP_NOT_FOUND'), 'APP_NOT_FOUND'),
			);

			return null;
		}

		// get auth
		$appHasAccess = \CRestUtil::checkAppAccess($app['ID'] ?? 0);
		if (!$appHasAccess)
		{
			$this->addError(
				new Error(Loc::getMessage('LANDING_WIDGET_APP_NO_ACCESS'), 'APP_NO_ACCESS'),
			);

			return null;
		}

		$auth = Rest\Application::getAuthProvider()?->get(
			$app['CLIENT_ID'],
			'landing',
			[],
			Manager::getUserId(),
		);
		if ($auth && isset($auth['error']))
		{
			$this->addError(
				new Error(
					$auth['error_description'] ?? '',
					'APP_AUTH_ERROR__' . $auth['error'],
				),
			);

			return null;
		}
		// auth goes to the handler only: $params keeps what the caller sent, the usage stat type below reads it
		$requestParams = $params;
		$requestParams['auth'] = $auth;

		// check subtype
		$manifest = $block->getManifest();
		if (
			!in_array(self::SUBTYPE_WIDGET, (array)$manifest['block']['subtype'], true)
			|| !is_array($manifest['block']['subtype_params'])
			|| !isset($manifest['block']['subtype_params']['handler'])
		)
		{
			$this->addError(
				new Error(Loc::getMessage('LANDING_WIDGET_HANDLER_NOT_FOUND_2'), 'HANDLER_NOT_FOUND'),
			);

			return null;
		}

		// request
		$data = $this->requestWidgetHandler(
			(string)$manifest['block']['subtype_params']['handler'],
			$requestParams,
		);
		if ($data === null)
		{
			return null;
		}

		$type = empty($params) ? 'fetch' : 'fetch_params';
		Rest\UsageStatTable::logLandingWidget($app['CLIENT_ID'], $type);
		Rest\UsageStatTable::finalize();

		return $data;
	}

	/**
	 * Requests the widget handler; adds an error and returns null when there is no answer to return.
	 *
	 * @param string $handler Handler url from the block manifest
	 * @param array $params Request payload
	 * @return string|null Raw response body, the caller of the action parses it
	 */
	protected function requestWidgetHandler(string $handler, array $params): ?string
	{
		// manifests saved before the handler validation appeared are checked here, not on write
		$url = Landing\Sanitizer::sanitizeWidgetHandlerUrl($handler);
		if ($url === '')
		{
			$this->addError($this->createHandlerAddressError());

			return null;
		}

		$httpClient = $this->createHandlerHttpClient();
		$data = $httpClient->post($url, $params);

		if ($data === false)
		{
			$this->addError(
				$this->isAddressRefused($httpClient)
					? $this->createHandlerAddressError()
					: new Error(Loc::getMessage('LANDING_WIDGET_HANDLER_NOT_ALLOW'), 'HANDLER_NOT_ALLOW'),
			);

			return null;
		}

		$status = $httpClient->getStatus();
		if ($status !== 200)
		{
			$this->addError(
				new Error(
					Loc::getMessage('LANDING_WIDGET_HANDLER_ANSWER_STATUS', ['#status#' => $status]),
					'HANDLER_ANSWER_STATUS'
				),
			);

			return null;
		}

		// an empty body reaches the client as a parse error of its own, which tells nothing
		if ($data === '')
		{
			$this->addError(
				new Error(Loc::getMessage('LANDING_WIDGET_HANDLER_ANSWER_EMPTY'), 'HANDLER_ANSWER_EMPTY'),
			);

			return null;
		}

		return $data;
	}

	/**
	 * Builds the client for the handler url: it comes from a REST application, so private
	 * addresses must be refused on every redirect hop and a slow answer must not hold a worker.
	 *
	 * @return HttpClient
	 */
	protected function createHandlerHttpClient(): HttpClient
	{
		return (new HttpClient())
			->setPrivateIp(false)
			->setTimeout(self::HANDLER_TIMEOUT)
			->setStreamTimeout(self::HANDLER_TIMEOUT)
			->setBodyLengthMax(self::HANDLER_BODY_LENGTH_MAX)
		;
	}

	private function isAddressRefused(HttpClient $httpClient): bool
	{
		$errors = $httpClient->getError();
		foreach (self::HANDLER_ADDRESS_ERROR_CODES as $code)
		{
			if (isset($errors[$code]))
			{
				return true;
			}
		}

		return false;
	}

	private function createHandlerAddressError(): Error
	{
		return new Error(
			Loc::getMessage('LANDING_WIDGET_HANDLER_ADDRESS_NOT_ALLOWED'),
			'HANDLER_ADDRESS_NOT_ALLOWED',
		);
	}

	public function publishAction(string $moduleId, string $embedId): Response\AjaxJson
	{
		$errorCollection = new ErrorCollection();
		$vibe = new Landing\Vibe\Vibe($moduleId, $embedId);

		if ($vibe->canEdit())
		{
			$vibe->publish();

			return Response\AjaxJson::createSuccess();
		}

		$errorCollection->setError(new Error('Access denied'));

		return Response\AjaxJson::createError($errorCollection);
	}

	public function withdrawAction(string $moduleId, string $embedId): Response\AjaxJson
	{
		$errorCollection = new ErrorCollection();
		$vibe = new Landing\Vibe\Vibe($moduleId, $embedId);

		if (
			Loader::includeModule('intranet')
			&& CurrentUser::get()->isAdmin()
			&& $vibe->canView()
		)
		{
			$vibe->withdraw();

			return Response\AjaxJson::createSuccess();
		}

		$errorCollection->setError(new Error('Access denied'));

		return Response\AjaxJson::createError($errorCollection);
	}

	/**
	 * Show settings component for a single vibe (AJAX); pass moduleId and embedId.
	 */
	public function renderSettingsAction(string $moduleId, string $embedId): Response\Component
	{
		$vibes = $this->collectAvailableVibesFromEmbedPairs([[$moduleId, $embedId]]);

		return new Response\Component(
			'bitrix:landing.mainpage.settings',
			'',
			['vibes' => $vibes],
		);
	}

	/**
	 * Show settings HTML for multiple vibes (AJAX); items is a list of [moduleId, embedId] pairs.
	 *
	 * @param array $items List of two-element string arrays
	 */
	public function renderSettingsGroupAction(array $items = []): Response\Component
	{
		$vibes = $this->collectAvailableVibesFromEmbedPairs($items);

		return new Response\Component(
			'bitrix:landing.mainpage.settings',
			'',
			['vibes' => $vibes],
		);
	}

	/**
	 * Builds Vibe instances from [moduleId, embedId] pairs; skips unavailable vibes.
	 *
	 * @param iterable<array> $items Iterable of two-string arrays
	 * @return Landing\Vibe\Vibe[] array of available Vibe objects
	 */
	private function collectAvailableVibesFromEmbedPairs(iterable $items): array
	{
		$vibes = [];
		foreach ($items as $item)
		{
			if (!is_array($item) || count($item) !== 2)
			{
				continue;
			}

			$moduleId = (string)$item[0];
			$embedId = (string)$item[1];
			if ($moduleId === '' || $embedId === '')
			{
				continue;
			}

			$vibe = new Landing\Vibe\Vibe($moduleId, $embedId);
			if (!$vibe->isAvailable())
			{
				continue;
			}

			$vibes[] = $vibe;
		}

		return $vibes;
	}
}
