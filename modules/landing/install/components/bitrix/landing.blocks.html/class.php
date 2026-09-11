<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use \Bitrix\Landing\Manager;
use \Bitrix\Landing\Landing;

class LandingBlocksHtmlComponent extends \CBitrixComponent
{
	/**
	 * Sanitizes bad code.
	 * @param string $str Very bad html with <script>, etc.
	 * @return string
	 */
	public function sanitize($str)
	{
		static $sanitizer = null;

		if ($sanitizer === null)
		{
			$sanitizer = new \CBXSanitizer;
			$sanitizer->SetLevel($sanitizer::SECURE_LEVEL_LOW);
		}

		return $sanitizer->sanitizeHtml($str);
	}

	/**
	 * Is the html of the block passed through the sanitizer before the output?
	 *
	 * Raw html is allowed on a site with a domain of its own only. The sandboxed preview
	 * (signed link or device frame) is served on the portal host whatever the domain is: the
	 * opaque origin hides the portal session from the code of the author, but a form or a
	 * script drawn under the trusted portal address would still be believed by the visitor.
	 * So there the html is sanitized the same way as without a domain — same as the head-block
	 * hook, which is off in that mode (Hook\Page\HeadBlock::enabled()).
	 * @param int $domainId Domain id of the site, 0 when the site has no domain of its own.
	 * @return bool
	 */
	public function mustSanitize(int $domainId): bool
	{
		return !$domainId || Landing::getDevicePreviewMode();
	}

	/**
	 * Local htmlspecialcharsback funciton.
	 * @param string $code Code for decoding.
	 * @return string
	 */
	public function htmlspecialcharsback($code)
	{
		$code = \htmlspecialcharsback($code);
		$code = str_replace('&#39;', "'", $code);
		return $code;
	}

	/**
	 * Base executable method.
	 * @return void
	 */
	public function executeComponent()
	{
		if (!\Bitrix\Main\Loader::includeModule('landing'))
		{
			return;
		}

		$this->arParams['ENABLED'] = 'Y';
		$this->arParams['EDIT_MODE'] = Landing::getEditMode() ? 'Y' : 'N';
		$this->arParams['PREVIEW_MODE'] = Landing::getPreviewMode() ? 'Y' : 'N';

		// prepare params
		if (
			!isset($this->arParams['SKIP_MOVING_FALSE']) ||
			$this->arParams['SKIP_MOVING_FALSE'] != 'Y'
		)
		{
			$this->arParams['SKIP_MOVING_FALSE'] = 'N';
		}
		if (!isset($this->arParams['~HTML_CODE']))
		{
			$this->arParams['~HTML_CODE'] = '';
		}

		// skip moving js to bottom of the page
		if ($this->arParams['SKIP_MOVING_FALSE'] != 'Y')
		{
			$this->arParams['~HTML_CODE'] = str_replace(
				'&lt;script',
				'&lt;script data-skip-moving="true"',
				$this->arParams['~HTML_CODE']
			);
		}

		// tariff feature
		if (
			isset($this->arParams['ONLY_PAYED']) &&
			$this->arParams['ONLY_PAYED'] == 'Y'
		)
		{
			$this->arParams['ONLY_PAYED'] = 'Y';
		}
		else
		{
			$this->arParams['ONLY_PAYED'] = 'N';
		}

		// if enabled this feature
		if ($this->arParams['ONLY_PAYED'] == 'Y')
		{
			$this->arParams['ENABLED'] = Manager::checkFeature(
				Manager::FEATURE_ENABLE_ALL_HOOKS,
				['hook' => 'headblock']
			) ? 'Y' : 'N';
		}

		$this->IncludeComponentTemplate();
	}
}