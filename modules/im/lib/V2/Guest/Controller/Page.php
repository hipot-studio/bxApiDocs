<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest\Controller;

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
			return new Redirect('/');
		}

		$chat = $result->getChat();
		$dialogId = $chat ? (string)$chat->getDialogId() : '';

		if ($result->getJoinStatus() === JoinStatus::PORTAL_USER)
		{
			return new Redirect('/online/?IM_DIALOG=' . $dialogId);
		}

		$isGuestWelcome = $this->consumeGuestWelcomeFlag($result->getUser()?->getId());

		// Release the session flock before HTML render so the JS AJAX burst doesn't
		// queue behind this request's OnAfterEpilog.
		Application::getInstance()->getKernelSession()->save();

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
