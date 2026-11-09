<?php
/**
 * Bitrix Framework
 * @package bitrix
 * @subpackage sender
 * @copyright 2001-2012 Bitrix
 */
namespace Bitrix\Sender\Preset\Templates;

use Bitrix\Main;
use Bitrix\Main\IO\File;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

use Bitrix\Sender\Message;
use Bitrix\Sender\Templates\Type;
use Bitrix\Sender\Integration;

Loc::loadMessages(__FILE__);

/**
 * Class Mail
 * @package Bitrix\Sender\Preset\Templates
 */
class Mail
{
	const LOCAL_DIR = '/modules/sender/preset/template_v2/';
	private const PRESET_BANNER_PATH = '/bitrix/images/sender/preset/template_v2/banner';
	private const PRESET_BANNER_IMAGE_CLASS = 'bxImage';

	/**
	 * Return base templates.
	 *
	 * @param string|null $templateType Template type.
	 * @param string|null $templateId Template ID.
	 * @param string|null $messageCode Message code.
	 * @return array
	 */
	public static function onPresetTemplateList($templateType = null, $templateId = null, $messageCode = null)
	{
		if($templateType && $templateType !== 'BASE')
		{
			return array();
		}
		if($messageCode && $messageCode !== Message\iBase::CODE_MAIL)
		{
			return array();
		}

		return self::getTemplates($templateId);
	}

	private static function getFileContent($fileName)
	{
		$path = Loader::getLocal(self::LOCAL_DIR . bx_basename($fileName) . '.php');
		if ($path && File::isFileExists($path))
		{
			return File::getFileContents($path);
		}

		return '';
	}

	/**
	 * Get template html.
	 *
	 * @param array $replace Replace data.
	 * @return mixed|null
	 */
	public static function replaceTemplateHtml($content, $replace = [])
	{
		$content = str_replace(
			array(
				'%TEXT%',
				'%IMAGE_PATH%',
				'%BUTTON_TEXT%',
				'%UNSUB_LINK%',
			),
			array(
				$replace['TEXT'],
				'/bitrix/images/sender/preset/template_v2/banner.png?1',
				Loc::getMessage('SENDER_PRESET_TEMPLATE_MAIL_BUTTON_GO'),
				Loc::getMessage(
					'SENDER_PRESET_TEMPLATE_MAIL_UNSUBSCRIBE',
					array(
						'%btn_start%' => '<a style="color: #0054a5;" href="#' . 'UNSUBSCRIBE_LINK' . '#">',
						'%btn_end%' => '</a>',
					)
				)
			),
			$content
		);

		return Texts::replaceHtml($content);
	}

	/**
	 * Adds absolute WebP candidates for the built-in mail banner right before sending.
	 *
	 * @param string $html Mail body HTML.
	 * @param string|null $protocol Final mail link protocol.
	 * @param string|null $domain Final mail link domain.
	 * @return string
	 */
	public static function addPresetBannerWebpSources(
		string $html,
		?string $protocol,
		?string $domain
	): string
	{
		if (strpos($html, self::PRESET_BANNER_PATH . '.png?') === false)
		{
			return $html;
		}

		$baseUrl = self::getAbsolutePresetBannerBaseUrl($protocol, $domain);
		if ($baseUrl === null)
		{
			return $html;
		}

		$pattern =
			'#'
			. '<picture\b[^>]*>.*?</picture>'
			. '|(<img\b[^>]*>)'
			. '#is'
		;
		$result = preg_replace_callback(
			$pattern,
			static function (array $matches) use ($baseUrl): string {
				$image = $matches[1] ?? null;
				if ($image === null || $image === '')
				{
					return $matches[0];
				}

				$version = self::getPresetBannerVersion($image);
				if ($version === null)
				{
					return $matches[0];
				}

				$webp1x = $baseUrl . ".webp?{$version}";
				$webp2x = $baseUrl . "@2x.webp?{$version}";

				return '<picture><source type="image/webp" srcset="'
					. $webp1x . ' 1x, '
					. $webp2x . ' 2x">'
					. $image
					. '</picture>'
				;
			},
			$html,
		);

		return $result ?? $html;
	}

	private static function getAbsolutePresetBannerBaseUrl(?string $protocol, ?string $domain): ?string
	{
		$protocol = self::normalizeLinkProtocol($protocol);
		if ($protocol === null)
		{
			return null;
		}

		$domain = self::normalizeLinkDomain($domain, $protocol);
		if ($domain === null)
		{
			return null;
		}

		return $protocol . '://' . $domain . self::PRESET_BANNER_PATH;
	}

	private static function normalizeLinkProtocol(?string $protocol): ?string
	{
		$protocol = strtolower(rtrim(trim((string)$protocol), ':/'));
		if (!in_array($protocol, ['http', 'https'], true))
		{
			return null;
		}

		return $protocol;
	}

	private static function normalizeLinkDomain(?string $domain, string $protocol): ?string
	{
		$domain = trim((string)$domain);
		if ($domain === '')
		{
			return null;
		}

		$uri = new Main\Web\Uri($protocol . '://' . $domain);
		if (
			$uri->getHost() === ''
			|| $uri->getUserInfo() !== ''
			|| $uri->getPath() !== ''
			|| $uri->getQuery() !== ''
			|| $uri->getFragment() !== ''
		)
		{
			return null;
		}

		$host = trim($uri->getHost(), '[]');
		if (filter_var($host, FILTER_VALIDATE_IP) === false)
		{
			$conversionResult = $uri->convertToPunycode();
			if ($conversionResult instanceof Main\Error)
			{
				return null;
			}
		}

		$domain = $uri->getAuthority();
		if (preg_match('/^[A-Za-z0-9.:\[\]-]+$/D', $domain) !== 1)
		{
			return null;
		}

		return $domain;
	}

	private static function getPresetBannerVersion(string $image): ?string
	{
		$classes = self::getHtmlAttributeValues($image, 'class');
		if (!in_array(self::PRESET_BANNER_IMAGE_CLASS, $classes, true))
		{
			return null;
		}

		$source = self::getHtmlAttribute($image, 'src');
		if ($source === null)
		{
			return null;
		}

		$sourcePattern =
			'#^'
			. preg_quote(self::PRESET_BANNER_PATH, '#')
			. '\.png\?([0-9]+)$#D'
		;
		if (preg_match($sourcePattern, $source, $versionMatch) !== 1)
		{
			return null;
		}

		return $versionMatch[1];
	}

	private static function getHtmlAttributeValues(string $html, string $attribute): array
	{
		$value = self::getHtmlAttribute($html, $attribute);
		if ($value === null)
		{
			return [];
		}

		return preg_split('/\s+/', trim($value)) ?: [];
	}

	private static function getHtmlAttribute(string $html, string $attribute): ?string
	{
		$pattern = '/\s' . preg_quote($attribute, '/') . '\s*=\s*(["\'])(.*?)\1/is';
		if (preg_match($pattern, $html, $matches) !== 1)
		{
			return null;
		}

		return $matches[2];
	}

	/**
	 * Get template html.
	 *
	 * @return string|null
	 */
	public static function getTemplateHtml()
	{
		$fileTheme = self::getFileContent('theme');
		$fileSimple = self::getFileContent('image_text_button');

		if (!$fileTheme || !$fileSimple)
		{
			return null;
		}

		$fileSocial = self::getFileContent('social');
		$fileSocialRu = self::getFileContent('social_ru');
		$fileSocialEn = '';
		if (Integration\Bitrix24\Service::isCloud() && !Integration\Bitrix24\Service::isCloudRegionRussian())
		{
			$fileSocialRu = '';
			$fileSocialEn = self::getFileContent('social_en');
		}

		$fileSocial = str_replace(
			['%SOCIAL_RU%', '%SOCIAL_EN%'],
			[$fileSocialRu, $fileSocialEn],
			$fileSocial
		);
		$fileContent = str_replace(
			['%TEMPLATE_CONTENT%', '%TEMPLATE_SOCIAL%'],
			[$fileSimple, $fileSocial],
			$fileTheme
		);

		return $fileContent;
	}

	private static function getTemplates($templateId = null)
	{
		$fileContent = self::getTemplateHtml();
		if (!$fileContent)
		{
			return [];
		}

		$result = [
			[
				'ID' => 'empty',
				'TYPE' => Type::getCode(Type::BASE),
				'MESSAGE_CODE' => Message\iBase::CODE_MAIL,
				'VERSION' => 2,
				'HOT' => false,
				'ICON' => '',

				'NAME' => Loc::getMessage('SENDER_PRESET_TEMPLATE_MAIL_HTML_NAME'),
				'DESC' => Loc::getMessage('SENDER_PRESET_TEMPLATE_MAIL_HTML_DESC'),
				'FIELDS' => array(
					'SUBJECT' => array(
						'CODE' => 'SUBJECT',
						'VALUE' => Loc::getMessage('SENDER_PRESET_TEMPLATE_MAIL_HTML_SUBJECT'),
					),
					'MESSAGE' => array(
						'CODE' => 'MESSAGE',
						'VALUE' => '<html><body></body></html>',
						'ON_DEMAND' => false
					),
				),
			]
		];

		$result = array_merge(
			$result,
			Integration\EventHandler::onTemplateList(Message\iBase::CODE_MAIL)
		);

		foreach (Texts::getListByType(Message\iBase::CODE_MAIL) as $item)
		{
			$code = mb_strtolower("mail_".$item['CODE']);
			if($templateId && $code !== $templateId)
			{
				continue;
			}

			$fileContent = self::replaceTemplateHtml(
				$fileContent,
				[
					'TEXT' => "<br><h2>{$item['TEXT_HEAD']}</h2><br>{$item['TEXT_BODY']}<br><br>"
				]
			);

			$result[] = array(
				'ID' => $code,
				'TYPE' => Type::getCode(Type::BASE),
				'MESSAGE_CODE' => Message\iBase::CODE_MAIL,
				'VERSION' => 2,
				'HOT' => $item['HOT'],
				'ICON' => $item['ICON'],
				'ICON_2X' => $item['ICON_2X'] ?? null,

				'NAME' => $item['NAME'],
				'DESC' => $item['DESC'],
				'FIELDS' => array(
					'SUBJECT' => array(
						'CODE' => 'SUBJECT',
						'VALUE' => $item['SUBJECT'],
					),
					'MESSAGE' => array(
						'CODE' => 'MESSAGE',
						'VALUE' => $fileContent,
						'ON_DEMAND' => true
					),
				),
			);
		}

		return $result;
	}
}
