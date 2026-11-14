<?php

declare(strict_types=1);

namespace Bitrix\Crm\Controller\Tracking\Ad;

use Bitrix\Main;

final class Authorization extends Main\Engine\Controller
{
	public const ACTION = 'crm.tracking.ad.authorization.authorize';

	public function configureActions(): array
	{
		// Browser checks below return an empty response with the same security headers on every refusal.
		return ['authorize' => ['prefilters' => []]];
	}

	public function authorizeAction(): Main\HttpResponse
	{
		if ($this->getScope() !== self::SCOPE_AJAX)
		{
			return $this->deny();
		}
		$request = $this->getRequest();
		$session = Main\Application::getInstance()->getSession();
		$cookie = $_COOKIE[$session->getName()] ?? null;
		if (!is_string($cookie) || $cookie === '' || !hash_equals($session->getId(), $cookie)
			|| !$GLOBALS['USER']->IsAuthorized()
			|| $request->getHeader('Authorization') !== null
			|| isset($_SERVER['PHP_AUTH_USER']) || isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']))
		{
			return $this->deny();
		}
		foreach (['auth', 'access_token', 'auth_id', 'AUTH_ID', 'USER_LOGIN', 'USER_PASSWORD', 'bx_hit_hash'] as $parameter)
		{
			if ($request->get($parameter) !== null)
			{
				return $this->deny();
			}
		}
		$authContext = $GLOBALS['USER']->getContext();
		if ($authContext->getApplicationId() !== null || $authContext->getApplicationPasswordId() !== null
			|| in_array($authContext->getMethod(), [
				Main\Authentication\Method::Unspecified,
				Main\Authentication\Method::AppPassword,
				Main\Authentication\Method::HitHash,
			], true))
		{
			return $this->deny();
		}
		if ($GLOBALS['USER']->UpdateSessionData($authContext) === false)
		{
			return $this->deny();
		}
		$method = $request->getRequestMethod();
		$ticket = $method === 'POST' ? $request->getPost('ticket') : $request->getQuery('ticket');
		if (!in_array($method, ['GET', 'POST'], true) || !is_string($ticket)
			|| !preg_match('/^[a-zA-Z0-9_-]{43}$/D', $ticket))
		{
			return $this->deny();
		}
		if ($method === 'GET')
		{
			Main\Localization\Loc::loadMessages(__FILE__);
			$formUrl = (string)Main\Engine\UrlManager::getInstance()->create(self::ACTION);
			return $this->response()->setContent('<!doctype html><html><head><meta charset="utf-8"></head><body>'
				. '<form method="post" action="' . htmlspecialcharsbx($formUrl) . '">'
				. '<input type="hidden" name="ticket" value="' . htmlspecialcharsbx($ticket) . '">'
				. '<input type="hidden" name="sessid" value="' . htmlspecialcharsbx(bitrix_sessid()) . '">'
				. '<button type="submit">'
				. htmlspecialcharsbx(Main\Localization\Loc::getMessage('CRM_TRACKING_AD_AUTH_CONTINUE'))
				. '</button></form></body></html>');
		}
		if (!check_bitrix_sessid() || $request->getPost('sessid') !== bitrix_sessid())
		{
			return $this->deny();
		}
		try
		{
			$url = (new \Bitrix\Crm\V2\Public\Command\Tracking\Ad\AuthorizeBrowser())->execute(
				(int)$GLOBALS['USER']->GetID(), $ticket, hash('sha256', $session->getId()),
			);
			if (preg_match('/[\r\n]/', $url))
			{
				return $this->deny();
			}
			return $this->response()->setStatus('303 See Other')->addHeader('Location', $url);
		}
		catch (\Throwable)
		{
			return $this->deny();
		}
	}

	private function deny(): Main\HttpResponse
	{
		return $this->response()->setStatus('403 Forbidden');
	}

	private function response(): Main\HttpResponse
	{
		return (new Main\HttpResponse())
			->addHeader('Cache-Control', 'no-store')
			->addHeader('Referrer-Policy', 'no-referrer')
			->addHeader('X-Frame-Options', 'DENY')
			->addHeader('Content-Security-Policy',
				"default-src 'none'; form-action 'self' https:; frame-ancestors 'none'; base-uri 'none'")
			->addHeader('Content-Type', 'text/html; charset=UTF-8')
		;
	}
}
