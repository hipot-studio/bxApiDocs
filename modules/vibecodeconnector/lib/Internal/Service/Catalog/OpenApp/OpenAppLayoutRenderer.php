<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\OpenApp;

use Bitrix\Main\Security\Random;
use Bitrix\Main\Web\Json;
use Bitrix\Vibecodeconnector\Internal\Dto\Catalog\OpenApp\OpenAppPayload;

final class OpenAppLayoutRenderer
{
	public function render(OpenAppPayload $payload): string
	{
		$suffix = Random::getString(8, true);
		$formName = 'vibecodeconnector_open_app_form_' . $suffix;
		$frameName = 'vibecodeconnector_open_app_frame_' . $suffix;
		$formNameJson = Json::encode($formName);
		$actionUrlJson = Json::encode($payload->actionUrl);

		$inputs = '';
		foreach ($payload->fields as $name => $value)
		{
			$inputs .= sprintf(
				'<input type="hidden" name="%s" value="%s">',
				htmlspecialcharsbx((string)$name),
				htmlspecialcharsbx((string)$value),
			);
		}

		return sprintf(
			'<div class="vibecodeconnector-open-app-layout" style="height:100%%;min-height:520px;">'
			. '<form name="%s" action="%s" method="POST" target="%s" style="display:none;">%s</form>'
			. '<iframe name="%s" frameborder="0"'
			. ' sandbox="allow-forms allow-scripts allow-same-origin allow-popups"'
			. ' style="display:block;width:100%%;height:100%%;min-height:520px;border:0;"></iframe>'
			. '<script>'
			. '(function(){'
			. 'var submit=function(){var form=document.forms[%s];if(form){'
			. 'if(window.BXDesktopSystem&&window.BXDesktopSystem.AllowFrame){window.BXDesktopSystem.AllowFrame(%s);}'
			. 'form.submit();}};'
			. 'if(window.BX&&BX.ready){BX.ready(submit);}else{submit();}'
			. '})();'
			. '</script>'
			. '</div>',
			htmlspecialcharsbx($formName),
			htmlspecialcharsbx($payload->actionUrl),
			htmlspecialcharsbx($frameName),
			$inputs,
			htmlspecialcharsbx($frameName),
			$formNameJson,
			$actionUrlJson,
		);
	}

	public function renderError(string $code, string $title, string $message): string
	{
		return sprintf(
			'<div class="vibecodeconnector-open-app-error" data-error-code="%s" style="padding:24px;">'
			. '<h3 style="margin:0 0 8px;">%s</h3>'
			. '<p style="margin:0;">%s</p>'
			. '</div>',
			htmlspecialcharsbx($code),
			htmlspecialcharsbx($title),
			htmlspecialcharsbx($message),
		);
	}

	public function renderPage(OpenAppPayload $payload): string
	{
		return $this->wrapPage($this->render($payload));
	}

	public function renderErrorPage(string $code, string $title, string $message): string
	{
		return $this->wrapPage($this->renderError($code, $title, $message));
	}

	private function wrapPage(string $body): string
	{
		return '<!doctype html>'
			. '<html>'
			. '<head>'
			. '<meta charset="UTF-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">'
			. '<style>html,body{height:100%;margin:0}</style>'
			. '</head>'
			. '<body>' . $body . '</body>'
			. '</html>';
	}
}
