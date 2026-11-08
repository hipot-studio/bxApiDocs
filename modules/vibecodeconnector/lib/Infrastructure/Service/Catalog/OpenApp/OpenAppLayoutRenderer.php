<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Service\Catalog\OpenApp;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Web\Json;
use Bitrix\Vibecodeconnector\Infrastructure\Service\Embed\SignedFormRenderer;
use Bitrix\Vibecodeconnector\Internal\Entity\Vibecode\VibecodeBrand;
use Bitrix\Vibecodeconnector\Internal\Dto\Catalog\OpenApp\OpenAppPayload;
use Bitrix\Vibecodeconnector\Internal\Dto\Embed\SignedFormPayload;
use Bitrix\Vibecodeconnector\Internal\Service\Vibecode\VibecodeBrandResolver;

final class OpenAppLayoutRenderer
{
	private const SURFACE = 'open-app';

	private readonly SignedFormRenderer $renderer;

	public function __construct(
		?SignedFormRenderer $renderer = null,
		private readonly bool $loadingScreenEnabled = true,
		private readonly VibecodeBrandResolver $brandResolver = new VibecodeBrandResolver(),
	) {
		$this->renderer = $renderer ?? new SignedFormRenderer(self::SURFACE);
	}

	public function render(OpenAppPayload $payload): string
	{
		return $this->renderer->renderForm(new SignedFormPayload(
			$payload->actionUrl,
			$payload->fields,
			$this->resolveAppTitle($payload),
		));
	}

	public function renderError(string $code, string $title, string $message): string
	{
		if (!in_array($code, ['ACCESS_DENIED', 'ITEM_NOT_FOUND'], true))
		{
			return $this->renderer->renderError($code, $title, $message);
		}

		$isAlaio = $this->brandResolver->resolve() === VibecodeBrand::Alaio;
		$logo = $isAlaio ? 'logo-alaio.svg' : 'logo.svg';
		$illustration = $isAlaio
			? 'error-state-alert-access-denied-intl.webp'
			: 'error-state-alert-access-denied.webp'
		;
		$css = AccessDeniedAssets::load();

		return ($css === null ? '' : '<style>' . $css . '</style>')
			. '<div class="vibecodeconnector-open-app-error" data-error-code="' . htmlspecialcharsbx($code) . '">'
			. '<div class="vibecodeconnector-open-app-error__composition">'
			. '<img class="vibecodeconnector-open-app-error__logo"'
			. ' src="/bitrix/js/vibecodeconnector/open-app-loader/images/' . $logo . '"'
			. ' alt="" aria-hidden="true" width="120" height="32">'
			. '<img class="vibecodeconnector-open-app-error__illustration"'
			. ' src="/bitrix/js/vibecodeconnector/open-app-access-denied/images/' . $illustration . '"'
			. ' alt="" aria-hidden="true" width="120" height="120">'
			. '<h3 class="vibecodeconnector-open-app-error__title">' . htmlspecialcharsbx($title) . '</h3>'
			. '<p class="vibecodeconnector-open-app-error__message">' . htmlspecialcharsbx($message) . '</p>'
			. '</div></div>'
		;
	}

	public function renderPage(OpenAppPayload $payload): string
	{
		return $this->wrapPage(
			$this->render($payload),
			$this->resolveAppTitle($payload),
			$this->loadingScreenEnabled ? LoadingScreenAssets::load($this->brandResolver->resolve()) : null,
		);
	}

	public function renderErrorPage(string $code, string $title, string $message): string
	{
		return $this->wrapPage($this->renderError($code, $title, $message), $title);
	}

	public function renderTopLevelPage(OpenAppPayload $payload): string
	{
		$formName = 'vibecodeconnector_transition_form_' . Random::getString(8, true);

		return $this->wrapPage(sprintf(
			'<div class="vibecodeconnector-transition-layout" style="padding:24px;">'
			. '<p style="margin:0;">%s</p>'
			. '<form name="%s" action="%s" method="POST">%s'
			. '<noscript><button type="submit" style="margin-top:12px;">%s</button></noscript>'
			. '</form>'
			. '<script>'
			. '(function(){'
			. 'var submit=function(){var form=document.forms[%s];if(form){form.submit();}};'
			. 'if(document.readyState===\'loading\'){'
			. 'document.addEventListener(\'DOMContentLoaded\',submit);}else{submit();}'
			. '})();'
			. '</script>'
			. '</div>',
			htmlspecialcharsbx((string)Loc::getMessage('VIBECODECONNECTOR_OPEN_APP_TRANSITION_REDIRECT')),
			htmlspecialcharsbx($formName),
			htmlspecialcharsbx($payload->actionUrl),
			$this->renderHiddenInputs($payload),
			htmlspecialcharsbx((string)Loc::getMessage('VIBECODECONNECTOR_OPEN_APP_TRANSITION_SUBMIT')),
			Json::encode($formName),
		), $this->resolveAppTitle($payload));
	}

	private function renderHiddenInputs(OpenAppPayload $payload): string
	{
		$inputs = '';
		foreach ($payload->fields as $name => $value)
		{
			$inputs .= sprintf(
				'<input type="hidden" name="%s" value="%s">',
				htmlspecialcharsbx((string)$name),
				htmlspecialcharsbx((string)$value),
			);
		}

		return $inputs;
	}

	private function resolveAppTitle(OpenAppPayload $payload): string
	{
		$appTitle = trim($payload->appTitle);

		return $appTitle === ''
			? trim((string)Loc::getMessage('VIBECODECONNECTOR_OPEN_APP_PAGE_DEFAULT_TITLE'))
			: $appTitle;
	}

	// the screen is inlined, not linked: it has to be painted from the first byte of the page
	private function wrapPage(string $body, string $documentTitle, ?LoadingScreenAssets $loadingScreen = null): string
	{
		$htmlClass = '';
		$loadingScreenStyle = '';
		if ($loadingScreen !== null)
		{
			$htmlClass = 'vibecodeconnector-open-app-page';
			$loadingScreenStyle = '<style>' . $loadingScreen->css . '</style>';
			$body = $this->renderLoadingScreen($loadingScreen->logoSvg)
				. $body
				. '<div class="vibecodeconnector-open-app-loader-spacer"></div>'
				. '<script>' . $loadingScreen->js . '</script>';
		}

		return '<!doctype html>'
			. $this->renderHtmlTag($htmlClass)
			. '<head>'
			. '<meta charset="UTF-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. $this->renderDocumentTitle($documentTitle)
			. '<style>html,body{height:100%;margin:0}</style>'
			. $loadingScreenStyle
			. '</head>'
			. '<body>' . $body . '</body>'
			. '</html>';
	}

	private function renderLoadingScreen(string $logoSvg): string
	{
		return '<div class="vibecodeconnector-open-app-loader" role="status">'
			. sprintf(
				'<span class="vibecodeconnector-open-app-loader__label">%s</span>',
				htmlspecialcharsbx((string)Loc::getMessage('VIBECODECONNECTOR_OPEN_APP_PAGE_LOADING')),
			)
			. $logoSvg
			. '</div>';
	}

	private function renderHtmlTag(string $class = ''): string
	{
		$attributes = '';

		$language = trim((string)Loc::getCurrentLang());
		if ($language !== '')
		{
			$attributes .= sprintf(' lang="%s"', htmlspecialcharsbx($language));
		}
		if ($class !== '')
		{
			$attributes .= sprintf(' class="%s"', htmlspecialcharsbx($class));
		}

		return '<html' . $attributes . '>';
	}

	private function renderDocumentTitle(string $documentTitle): string
	{
		return $documentTitle === ''
			? ''
			: sprintf('<title>%s</title>', htmlspecialcharsbx($documentTitle));
	}
}
