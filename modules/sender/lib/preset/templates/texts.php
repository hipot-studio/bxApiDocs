<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage sender
 * @copyright 2001-2012 Bitrix
 */
namespace Bitrix\Sender\Preset\Templates;

use Bitrix\Main\Localization\Loc;

use Bitrix\Sender\Message;
use Bitrix\Sender\Integration;

Loc::loadMessages(__FILE__);

/**
 * Class Texts
 * @package Bitrix\Sender\Preset\Templates
 */
class Texts
{
	const IMAGE_DIR = '/images/sender/preset/template_v2/';

	private const HTML_VALUE_ESCAPES = [
		'&' => '&amp;',
		'<' => '&lt;',
		'>' => '&gt;',
		'"' => '&quot;',
		"'" => '&#039;',
		'?' => '&#63;',
		'%' => '&#37;',
		'#' => '&#35;',
	];

	private static ?array $replacements = null;

	/**
	 * Get codes.
	 *
	 * @return array|null
	 */
	public static function getCodes()
	{
		return array(
			'hot_unplanned',
			'unplanned',
			'hot_lead2client',
			'lead2client',
			'hot_inc_profits',
			'inc_profits',
			'hot_brand2brain',
			'brand2brain',
			'hot_repeated',
			'repeated',
			'hot_push2deal',
			'push2deal',
			'hot_wakeup',
			'wakeup',
			'hot_satisfy',
			'satisfy',
			'hot_birthday',
			'birthday',
			'hot_inform_event',
			'inform_event',
			'hot_invite2event',
			'invite2event',
		);
	}

	/**
	 * Get texts list by type.
	 *
	 * @param string $type Type.
	 * @return array
	 */
	public static function getListByType($type)
	{
		$result = array();
		foreach (self::getCodes() as $code)
		{
			$item = self::getByCode($code);
			if (empty($item['TYPES'][$type]))
			{
				continue;
			}

			$fields = $item['TYPES'][$type];
			unset($item['TYPES']);
			foreach ($fields as $key => $value)
			{
				$item[$key] = $value;
			}

			$result[] = $item;
		}

		return $result;
	}

	/**
	 * Replace company requisites placeholders with raw values (plain text channels).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function replace($text = ''): string
	{
		return strtr((string)$text, self::getReplacements()['text']);
	}

	/**
	 * Replace company requisites placeholders with HTML-escaped values.
	 *
	 * @param string $html Html.
	 * @return string
	 */
	public static function replaceHtml(string $html): string
	{
		return strtr($html, self::getReplacements()['html']);
	}

	/**
	 * Override the company card; null resets to the card of the portal.
	 *
	 * @internal For tests only.
	 * @param array|null $card Card ['COMPANY_NAME' => string, 'PHONE' => string].
	 * @return void
	 */
	public static function setCompanyCard(?array $card): void
	{
		self::$replacements = ($card === null) ? null : self::buildReplacements($card);
	}

	protected static function getMessage($code, bool $html = false): string
	{
		$message = (string)Loc::getMessage($code);

		return $html ? self::replaceHtml($message) : self::replace($message);
	}

	private static function getReplacements(): array
	{
		self::$replacements ??= self::buildReplacements(self::loadCompanyCard());

		return self::$replacements;
	}

	private static function loadCompanyCard(): array
	{
		$card = [];
		foreach (Integration\EventHandler::onSenderCompanyCard() as $card)
		{
			if (!isset($card['DATA']) || !is_array($card['DATA']))
			{
				continue;
			}
			$card = $card['DATA'];

			if (!$card['COMPANY_NAME'])
			{
				continue;
			}

			break;
		}

		return is_array($card) ? $card : [];
	}

	private static function buildReplacements(array $card): array
	{
		$companyName = empty($card['COMPANY_NAME'])
			? Loc::getMessage('SENDER_PRESET_TEMPLATE_DEFAULT_COMPANY')
			: $card['COMPANY_NAME']
		;
		$phone = empty($card['PHONE'])
			? Loc::getMessage('SENDER_PRESET_TEMPLATE_DEFAULT_PHONE')
			: $card['PHONE']
		;

		$text = [
			'%COMPANY%' => (string)$companyName,
			'%PHONE_FORMATTED%' => (string)$phone,
			'%PHONE%' => preg_replace('/[^\d]/', '', (string)$phone),
		];

		// strtr() prefers the longest key, so "<" right before a placeholder is escaped in the same pass
		$html = [];
		foreach ($text as $placeholder => $value)
		{
			$value = self::escapeHtmlValue($value);
			$html[$placeholder] = $value;
			$html['<' . $placeholder] = '&lt;' . $value;
		}

		return [
			'text' => $text,
			'html' => $html,
		];
	}

	private static function escapeHtmlValue(string $value): string
	{
		return strtr($value, self::HTML_VALUE_ESCAPES);
	}

	/**
	 * Get texts by code.
	 *
	 * @param string $code Code.
	 * @return array|null
	 */
	public static function getByCode($code)
	{
		$dictionary = array(
			Message\iBase::CODE_MAIL => array(
				'SUBJECT',
				'TEXT_HEAD',
				'TEXT_BODY'
			),
			Message\iBase::CODE_SMS => array(
				'TEXT'
			),
			Message\iBase::CODE_IM => array(
				'TEXT'
			),
			Message\iBase::CODE_AUDIO_CALL => array(
				'AUDIO_FILE'
			),
		);

		$isHot = mb_strpos($code, 'hot_') === 0;
		$iconPath = $isHot? mb_substr($code, 4) : $code;
		$iconPath = BX_ROOT . self::IMAGE_DIR . $iconPath;

		$code = mb_strtoupper($code);
		$result = array(
			'CODE' => $code,
			'NAME' => Loc::getMessage('SENDER_PRESET_TEMPLATE_' . $code . '_NAME'),
			'DESC' => Loc::getMessage('SENDER_PRESET_TEMPLATE_' . $code . '_DESC'),
			'HOT' => $isHot,
			'ICON' => $iconPath . '.webp',
			'ICON_2X' => $iconPath . '@2x.webp',
			'TYPES' => array(),
		);

		foreach ($dictionary as $type => $keys)
		{
			if (isset($result['TYPES'][$type])
				&& !is_array($result['TYPES'][$type]))
			{
				$result['TYPES'][$type] = array();
			}

			foreach ($keys as $key)
			{
				$msgId = 'SENDER_PRESET_' . $type . '_' . $code . '_' . $key;
				$isHtml = $type === Message\iBase::CODE_MAIL && in_array($key, ['TEXT_HEAD', 'TEXT_BODY'], true);
				$result['TYPES'][$type][$key] = self::getMessage(mb_strtoupper($msgId), $isHtml);
			}
		}

		return (mb_strlen($result['NAME']) && !empty($result['TYPES'])) ? $result : null;
	}
}
