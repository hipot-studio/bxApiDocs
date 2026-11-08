<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Menu\MyCompany\Card;

use Bitrix\Main\Context;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Loader;
use Bitrix\Mobile\Config\Feature;
use Bitrix\Mobile\Feature\BitrixAnswersSandboxFeature;

/**
 * Address of the page the "Bitrix24 Answers" screen opens. The page lives on the helpdesk domain, so
 * the ui module is the whole condition of having an address at all - unless the debug switch puts
 * the sandbox page of the bridge in its place, and that one lies on the portal itself.
 */
final class BitrixAnswersCardProvider
{
	private const PAGE_PATH = '/widget2/mobile/';

	/** The page renders its Air design by this parameter. */
	private const PAGE_PARAMS = ['air' => 'Y'];

	/**
	 * Page of the bridge sandbox: dev/public of the module is mounted into the portal as /dev/mobile/.
	 * Static on purpose - the url scope of the app session leaves dev/ out, and a page that boots the
	 * kernel answers the webview with an empty 403 instead of itself.
	 */
	private const SANDBOX_PAGE_PATH = '/dev/mobile/my-company/bridge-sandbox.html';

	/**
	 * Address of the page, or null without the ui module.
	 *
	 * The debug switch stands in front of everything, the module check included: the sandbox page is
	 * served by the portal itself, so a portal without the ui module keeps the debug circuit.
	 *
	 * The domain follows the interface language - the mode Helpdesk\Url is built in by default and
	 * the one Helpdesk\Request uses. Nothing but the path and the design parameter travels in the
	 * address: the answer of the section is cached on the device, and the identity and the signature
	 * Helpdesk\Request would add have no place in that cache.
	 */
	public function getUrl(): ?string
	{
		if (Feature::isEnabled(BitrixAnswersSandboxFeature::class))
		{
			return $this->getSandboxUrl();
		}

		if (!Loader::includeModule('ui'))
		{
			return null;
		}

		return (string)(new \Bitrix\UI\Helpdesk\Url())
			->getByPath(self::PAGE_PATH)
			->addParams(self::PAGE_PARAMS)
		;
	}

	/**
	 * Address of the sandbox page of the bridge, absolute: the screen hands the address to WebView as
	 * it came, and a bare path has no base to be resolved against there. Public because the dev page
	 * of the section shows the address to open in a browser next to the switch itself.
	 */
	public function getSandboxUrl(): string
	{
		return $this->getRequestOrigin() . self::SANDBOX_PAGE_PATH;
	}

	/**
	 * The address the client reached the portal by, not the one the portal calls itself. A device
	 * opens the sandbox over the same connection it asks the section for, and the configured server
	 * name of a development stand is often a name only the server itself resolves - a phone given
	 * that name gets a blank view. Outside an http request there is no client to ask, so the
	 * configured host stays as the fallback.
	 */
	private function getRequestOrigin(): string
	{
		$request = Context::getCurrent()?->getRequest();
		$host = (string)($request?->getHttpHost() ?? '');

		if ($host === '')
		{
			return UrlManager::getInstance()->getHostUrl();
		}

		$port = (int)$request->getServerPort();
		$hostHasPort = str_starts_with($host, '[')
			? str_contains($host, ']:')
			: str_contains($host, ':')
		;
		$portSuffix = (!$hostHasPort && $port && !in_array($port, [80, 443], true)) ? ':' . $port : '';

		return ($request->isHttps() ? 'https' : 'http') . '://' . $host . $portSuffix;
	}
}
