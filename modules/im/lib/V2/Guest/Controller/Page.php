<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest\Controller;

use Bitrix\Im\V2\Guest\Auth\AuthorizationService;
use Bitrix\Im\V2\Guest\Auth\JoinStatus;
use Bitrix\Im\V2\Guest\Auth\Token;
use Bitrix\Im\V2\Guest\GuestService;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response\Redirect;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

/**
 * Public /guest/{code} page. SITE_TEMPLATE_ID = 'bitrix24' is forced by the route
 * middleware in intranet/install/routes/intranet.php — intranet JS/CSS gated on it
 * stays active without bitrix24/header.php's full chrome.
 */
class Page extends Controller
{
	protected function getDefaultPreFilters(): array
	{
		return [];
	}

	public function viewAction(string $code): HttpResponse
	{
		global $APPLICATION;

		Loader::requireModule('intranet');

		$token = Token::createFromRequest();
		$result = GuestService::getInstance()->joinByCode($code, $token);

		if (!$result->isSuccess())
		{
			// A visitor of another contour is refused right here: the portal root would end the session they
			// came with, and a mistyped invite code must not cost them a conversation held elsewhere.
			if (GuestService::getInstance()->currentSessionBelongsToAnotherContour())
			{
				return $this->buildRefusalResponse();
			}

			return new Redirect('/');
		}

		$chat = $result->getChat();
		$dialogId = $chat ? (string)$chat->getDialogId() : '';

		if ($result->getJoinStatus() === JoinStatus::PORTAL_USER)
		{
			return new Redirect('/online/?IM_DIALOG=' . $dialogId);
		}

		$isGuestWelcome = $this->consumeGuestWelcomeFlag($result->getUser()?->getId());

		$this->releaseSessionLocks();

		$APPLICATION->SetTitle(Loc::getMessage('IM_GUEST_PAGE_TITLE'));

		// Manual buffer instead of $this->renderView(withSiteTemplate: false): the latter
		// switches Asset to ajax mode and ships ~200 messenger stylesheets via BX.loadCSS,
		// causing a flash of unstyled content.
		$APPLICATION->RestartBuffer();

		$arParams = [
			'dialogId' => $dialogId,
			'isGuestWelcome' => $isGuestWelcome,
		];
		require __DIR__ . '/views/guest_view.php';

		$content = $APPLICATION->EndBufferContentMan();

		$response = new HttpResponse();
		$response->setContent($content);

		return $response;
	}

	/**
	 * The answer the prolog gave on this path before the guest page joined the public scope, only not
	 * with an empty body. Unstyled on purpose: a designed page for a refused link is a separate task.
	 */
	private function buildRefusalResponse(): HttpResponse
	{
		$message = htmlspecialcharsbx((string)Loc::getMessage('IM_GUEST_PAGE_LINK_UNAVAILABLE'));
		$charset = defined('SITE_CHARSET') ? SITE_CHARSET : 'UTF-8';

		$response = new HttpResponse();
		$response->setStatus('403 Forbidden');
		$response->setContent(
			'<!DOCTYPE html><html><head><meta charset="' . htmlspecialcharsbx($charset) . '"/>'
			. '<meta name="viewport" content="width=device-width, initial-scale=1.0"/>'
			. '<title>' . $message . '</title></head><body><p>' . $message . '</p></body></html>'
		);

		return $response;
	}

	/**
	 * Both locks go before the HTML render, so the JS AJAX burst doesn't queue behind OnAfterEpilog. In
	 * the 'separated' mode the general session is a second lock the kernel save does not release.
	 */
	private function releaseSessionLocks(): void
	{
		Application::getInstance()->getKernelSession()->save();
		AuthorizationService::getInstance()->releaseSessionLock();
	}

	private function consumeGuestWelcomeFlag(?int $guestUserId): bool
	{
		if (!$guestUserId)
		{
			return false;
		}

		if (\CUserOptions::GetOption('im', 'guest_welcome_shown', 'N', $guestUserId) === 'Y')
		{
			return false;
		}

		\CUserOptions::SetOption('im', 'guest_welcome_shown', 'Y', false, $guestUserId);

		return true;
	}
}
