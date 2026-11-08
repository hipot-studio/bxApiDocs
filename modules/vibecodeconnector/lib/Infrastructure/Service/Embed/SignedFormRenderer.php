<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Service\Embed;

use Bitrix\Main\Security\Random;
use Bitrix\Main\Web\Json;
use Bitrix\Vibecodeconnector\Internal\Dto\Embed\SignedFormPayload;

final class SignedFormRenderer
{
	private readonly string $cssPrefix;
	private readonly string $namePrefix;

	public function __construct(string $surface)
	{
		$this->cssPrefix = 'vibecodeconnector-' . $surface;
		$this->namePrefix = 'vibecodeconnector_' . str_replace('-', '_', $surface);
	}

	public function renderForm(SignedFormPayload $payload): string
	{
		$suffix = Random::getString(8, true);
		$formName = $this->namePrefix . '_form_' . $suffix;
		$frameName = $this->namePrefix . '_frame_' . $suffix;
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
			'<div class="%s-layout" style="height:100%%;min-height:520px;">'
			. '<form name="%s" action="%s" method="POST" target="%s" style="display:none;">%s</form>'
			. '<iframe name="%s"%s frameborder="0"'
			. ' sandbox="allow-forms allow-scripts allow-same-origin allow-popups allow-downloads"'
			. ' allow="%s"'
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
			htmlspecialcharsbx($this->cssPrefix),
			htmlspecialcharsbx($formName),
			htmlspecialcharsbx($payload->actionUrl),
			htmlspecialcharsbx($frameName),
			$inputs,
			htmlspecialcharsbx($frameName),
			$this->renderFrameTitle($payload->frameTitle),
			htmlspecialcharsbx($this->frameAllowAttribute($payload)),
			$formNameJson,
			$actionUrlJson,
		);
	}

	private function frameAllowAttribute(SignedFormPayload $payload): string
	{
		$allow = 'geolocation *; microphone *; camera *; autoplay *;';
		if (!$payload->clipboardWrite)
		{
			return $allow;
		}

		$origin = $this->originOf($payload->actionUrl);
		if ($origin === '')
		{
			return $allow;
		}

		return $allow . ' clipboard-write ' . $origin . ';';
	}

	private function originOf(string $url): string
	{
		$parts = parse_url($url);
		if (!is_array($parts) || ($parts['scheme'] ?? '') === '' || ($parts['host'] ?? '') === '')
		{
			return '';
		}

		$origin = $parts['scheme'] . '://' . $parts['host'];

		return isset($parts['port']) ? $origin . ':' . $parts['port'] : $origin;
	}

	private function renderFrameTitle(string $frameTitle): string
	{
		return $frameTitle === ''
			? ''
			: sprintf(' title="%s"', htmlspecialcharsbx($frameTitle));
	}

	public function renderError(string $code, string $title, string $message): string
	{
		return sprintf(
			'<div class="%s-error" data-error-code="%s" style="padding:24px;">'
			. '<h3 style="margin:0 0 8px;">%s</h3>'
			. '<p style="margin:0;">%s</p>'
			. '</div>',
			htmlspecialcharsbx($this->cssPrefix),
			htmlspecialcharsbx($code),
			htmlspecialcharsbx($title),
			htmlspecialcharsbx($message),
		);
	}
}
