<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class LandingBlocksMessageComponent extends \CBitrixComponent
{
	private const MESSAGE_TYPE_DEFAULT = 'status';
	private const MESSAGE_TYPES = ['status', 'alert'];

	private static string $lockTitlePrefix = '';
	private static int $lockTitleCounter = 0;

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

		$codes = [
			'HEADER', 'MESSAGE', 'BUTTON', 'LINK'
		];

		foreach ($codes as $code)
		{
			if (!isset($this->arParams[$code]))
			{
				$this->arParams[$code] = '';
			}
			if (!isset($this->arParams['~' . $code]))
			{
				$this->arParams['~' . $code] = '';
			}
		}

		$this->arParams['MESSAGE_TYPE'] = $this->resolveMessageType();
		$this->arResult['LOCK_TITLE_ID'] = self::nextLockTitleId();

		$this->includeComponentTemplate();
	}

	/**
	 * Returns the message type the template may print as a role, unknown values fall back to the default.
	 * @return string
	 */
	private function resolveMessageType(): string
	{
		$type = $this->arParams['MESSAGE_TYPE'] ?? '';

		return in_array($type, self::MESSAGE_TYPES, true) ? $type : self::MESSAGE_TYPE_DEFAULT;
	}

	/**
	 * Returns the id the locked template names its panel by: the panel may be rendered several
	 * times on the same page, and the counter tells those of one response apart.
	 *
	 * The counter alone would only hold inside one response, and a panel of a partial answer of the
	 * editor is inserted into a page already carrying panels numbered from one. Hence the prefix,
	 * drawn once per request: the draw costs one call per response instead of one per panel, and the
	 * panels of one response keep a common prefix.
	 * @return string
	 */
	private static function nextLockTitleId(): string
	{
		if (self::$lockTitlePrefix === '')
		{
			self::$lockTitlePrefix = \Bitrix\Main\Security\Random::getString(6);
		}

		self::$lockTitleCounter++;

		return 'landing-html-lock-title-' . self::$lockTitlePrefix . '-' . self::$lockTitleCounter;
	}
}
