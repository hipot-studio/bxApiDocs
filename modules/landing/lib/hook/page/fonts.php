<?php
namespace Bitrix\Landing\Hook\Page;

use Bitrix\Landing;
use Bitrix\Landing\Field;
use Bitrix\Landing\Hook;
use Bitrix\Landing\Internals\HookDataTable;
use Bitrix\Landing\Manager;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\UI;

class Fonts extends \Bitrix\Landing\Hook\Page
{
	/**
	 * Default font for backward compatibility.
	 */
	public const DEFAULT_FONTS = [
		'g-font-open-sans' => [
			'name' => 'Open Sans',
			'family' => '"Open Sans", Helvetica, Arial, sans-serif',
			'url' => 'https://fonts.googleapis.com/css2?family=Open+Sans:wght@100;200;300;400;500;600;700;800;900&subset=cyrillic',
		],
		'g-font-roboto' => [
			'name' => 'Roboto',
			'family' => '"Roboto", Arial, sans-serif',
			'url' => 'https://fonts.googleapis.com/css2?family=Roboto:wght@100;200;300;400;500;600;700;800;900&subset=cyrillic,cyrillic-ext,latin-ext',
		],
		'g-font-roboto-slab' => [
			'name' => 'Roboto Slab',
			'family' => '"Roboto Slab", Helvetica, Arial, sans-serif',
			'url' => 'https://fonts.googleapis.com/css2?family=Roboto+Slab:wght@100;200;300;400;500;600;700;800;900&subset=cyrillic,cyrillic-ext,latin-ext',
		],
		'g-font-montserrat' => [
			'name' => 'Montserrat',
			'family' => '"Montserrat", Helvetica, Arial, sans-serif',
			'url' => 'https://fonts.googleapis.com/css2?family=Montserrat:wght@100;200;300;400;500;600;700;800;900&subset=cyrillic',
		],
		'g-font-alegreya-sans' => [
			'name' => 'Alegreya Sans',
			'family' => '"Alegreya Sans", sans-serif',
			'url' => 'https://fonts.googleapis.com/css2?family=Alegreya+Sans:wght@100;200;300;400;500;600;700;800;900&subset=cyrillic-ext,latin-ext',
		],
		'g-font-cormorant-infant' => [
			'name' => 'Cormorant Infant',
			'family' => '"Cormorant Infant", serif',
			'url' => 'https://fonts.googleapis.com/css2?family=Cormorant+Infant:wght@100;200;300;400;500;600;700;800;900&subset=cyrillic-ext,latin-ext',
		],
		'g-font-pt-sans-caption' => [
			'name' => 'PT Sans Caption',
			'family' => '"PT Sans Caption", sans-serif',
			'url' => 'https://fonts.googleapis.com/css2?family=PT+Sans+Caption:wght@100;200;300;400;500;600;700;800;900&subset=cyrillic-ext,latin-ext',
		],
		'g-font-pt-sans-narrow' => [
			'name' => 'PT Sans Narrow',
			'family' => '"PT Sans Narrow", sans-serif',
			'url' => 'https://fonts.googleapis.com/css2?family=PT+Sans+Narrow:wght@100;200;300;400;500;600;700;800;900&PT+Sans:wght@100;200;300;400;500;600;700;800;900&subset=cyrillic-ext,latin-ext',
		],
		'g-font-pt-sans' => [
			'name' => 'PT Sans',
			'family' => '"PT Sans", sans-serif',
			'url' => 'https://fonts.googleapis.com/css2?family=PT+Sans:wght@100;200;300;400;500;600;700;800;900&subset=cyrillic-ext,latin-ext',
		],
		'g-font-lobster' => [
			'name' => 'Lobster',
			'family' => '"Lobster", cursive',
			'url' => 'https://fonts.googleapis.com/css2?family=Lobster:wght@100;200;300;400;500;600;700;800;900&subset=cyrillic-ext,latin-ext',
		],
	];

	/**
	 * Default domain for Google Fonts.
	 */
	public const DEFAULT_DOMAIN = 'fonts.googleapis.com';

	/**
	 * Font codes requested by blocks on the current page.
	 * @var array<string, array>
	 */
	protected static $pageFontCodes = [];

	private const FONT_CODE_PATTERN = '/^g-font-[a-z0-9-]+$/';

	private const FONT_SNIPPET_REGEX = '#(<noscript>.*?<style.*?data-id="([^"]+)"[^>]*>[^<]+</style>)#is';

	/**
	 * Map of the field.
	 * @return array
	 */
	protected function getMap()
	{
		return [
			'CODE' => new Field\Textarea(
				'CODE', [
				'title' => Loc::getMessage('LNDNGHOOK_FONTS_FONT_BASE'),
			]),
		];
	}

	/**
	 * Enable or not the hook.
	 * @return boolean
	 */
	public function enabled()
	{
		return true;
	}

	/**
	 * Sets font code as using on the page.
	 * @param string $fontCode Font code.
	 * @return void
	 */
	public static function setFontCode(string $fontCode): void
	{
		if (!array_key_exists($fontCode, self::$pageFontCodes))
		{
			self::$pageFontCodes[$fontCode] = [];
		}
	}

	/**
	 * Exec hook.
	 * @return void
	 */
	public function exec()
	{
		if (!self::$pageFontCodes)
		{
			return;
		}

		$styleFound = preg_match_all(
			self::FONT_SNIPPET_REGEX,
			$this->fields['CODE'],
			$matches
		);

		$fonts = [];
		if ($styleFound)
		{
			foreach ($matches[2] as $i => $fontCode)
			{
				$normalizedFont = self::normalizeFontSnippet($matches[1][$i]);
				if ($normalizedFont !== '')
				{
					$fonts[$fontCode] = $normalizedFont;
				}
			}
		}
		$this->outputFonts($fonts);
	}

	/**
	 * Sets fonts data to the page.
	 * @param array $fonts Fonts data ([fontCode => fontStyle]).
	 * @return void
	 */
	protected function outputFonts(array $fonts): void
	{
		$fontHtmlChunks = [];

		foreach (self::$pageFontCodes as $fontCode => $foo)
		{
			if (isset($fonts[$fontCode]))
			{
				unset(self::$pageFontCodes[$fontCode]);
				$fontHtmlChunks[] = self::proxyFontUrl($fonts[$fontCode]);
			}
		}

		// set default fonts
		foreach (self::$pageFontCodes as $fontCode => $foo)
		{
			$fontHtmlChunks[] = self::outputDefaultFont($fontCode);
		}

		if ($fontHtmlChunks)
		{
			Landing\Assets\Manager::getInstance()->addString(implode('', $fontHtmlChunks));
		}
	}

	/**
	 * Outputs default font.
	 * @param string $code Font code.
	 * @return string
	 */
	public static function outputDefaultFont(string $code): string
	{
		if (isset(self::DEFAULT_FONTS[$code]))
		{
			$fontUrl = self::DEFAULT_FONTS[$code]['url'];
			$fontUrl = self::proxyFontUrl($fontUrl);

			return '<link 
						rel="preload" 
						as="style" 
						onload="this.removeAttribute(\'onload\');this.rel=\'stylesheet\'" 
						data-font="' . $code . '" 
						data-protected="true" 
						href="' . $fontUrl . '">
					<noscript>
						<link
							rel="stylesheet" 
							data-font="' . $code . '" 
							data-protected="true" 
							href="' . $fontUrl . '">
					</noscript>';
		}

		return '';
	}

	/**
	 * Normalizes incoming fonts and persists them in the FONTS hook storage.
	 * @param int $landingId Landing id.
	 * @param string $incomingContent Fonts HTML from client.
	 * @return void
	 */
	public static function saveFontsForLanding(int $landingId, string $incomingContent): void
	{
		$fields = [
			'ENTITY_ID' => $landingId,
			'ENTITY_TYPE' => Hook::ENTITY_TYPE_LANDING,
			'HOOK' => 'FONTS',
			'CODE' => 'CODE',
			'PUBLIC' => 'N',
		];

		$res = HookDataTable::getList([
			'select' => [
				'ID', 'VALUE',
			],
			'filter' => $fields,
		]);

		if ($row = $res->fetch())
		{
			$existsContent = self::mergeFontsIntoStorage((string)$row['VALUE'], $incomingContent);
			if ($existsContent !== $row['VALUE'])
			{
				HookDataTable::update(
					$row['ID'],
					['VALUE' => $existsContent]
				);
			}

			return;
		}

		$normalizedContent = self::mergeFontsIntoStorage('', $incomingContent);
		if ($normalizedContent !== '')
		{
			$fields['VALUE'] = $normalizedContent;
			HookDataTable::add($fields);
		}
	}

	/**
	 * Appends normalized incoming fonts to the stored HTML string.
	 * @param string $existingContent Fonts HTML already stored in DB.
	 * @param string $incomingContent Fonts HTML from client.
	 * @return string Updated fonts HTML for DB storage.
	 */
	public static function mergeFontsIntoStorage(string $existingContent, string $incomingContent): string
	{
		if ($incomingContent === '')
		{
			return $existingContent;
		}

		$found = preg_match_all(
			self::FONT_SNIPPET_REGEX,
			$incomingContent,
			$newFonts
		);
		if (!$found)
		{
			return $existingContent;
		}

		foreach ($newFonts[1] as $i => $newFont)
		{
			$fontCode = $newFonts[2][$i];
			$normalizedFont = self::normalizeFontSnippet($newFont);
			if (
				$normalizedFont !== ''
				&& mb_strpos($existingContent, '"' . $fontCode . '"') === false
			)
			{
				$existingContent .= $normalizedFont;
			}
		}

		return $existingContent;
	}

	/**
	 * Rebuilds font snippet from trusted font code and name.
	 * @param string $content Raw font HTML from client or storage.
	 * @return string Canonical safe font HTML or empty string.
	 */
	public static function normalizeFontSnippet(string $content): string
	{
		$fontCode = self::extractFontCode($content);
		if ($fontCode === null)
		{
			return '';
		}

		$fontName = self::resolveFontName($fontCode);
		if ($fontName === null)
		{
			return '';
		}

		return self::proxyFontUrl(self::generateFontTags($fontName));
	}

	/**
	 * Extracts font code from the font HTML snippet.
	 * @param string $content Raw font HTML.
	 * @return string|null
	 */
	protected static function extractFontCode(string $content): ?string
	{
		if (!preg_match('#<style[^>]*\sdata-id="([^"]+)"#is', $content, $match))
		{
			return null;
		}

		$fontCode = $match[1];
		if (!preg_match(self::FONT_CODE_PATTERN, $fontCode))
		{
			return null;
		}

		return $fontCode;
	}

	/**
	 * Resolves human-readable font name by font code.
	 * @param string $fontCode Font code.
	 * @return string|null
	 */
	protected static function resolveFontName(string $fontCode): ?string
	{
		if (isset(self::DEFAULT_FONTS[$fontCode]))
		{
			return self::DEFAULT_FONTS[$fontCode]['name'];
		}

		$slug = substr($fontCode, strlen('g-font-'));
		if ($slug === '')
		{
			return null;
		}

		$fontName = ucwords(str_replace('-', ' ', $slug));

		$bad = false;
		$fontName = (new Landing\Sanitizer())->sanitizeText($fontName, $bad);
		if ($bad || $fontName === '')
		{
			return null;
		}

		return $fontName;
	}

	/**
	 * Generates HTML and CSS tags to load and apply a specified font.
	 *
	 * @param string $fontName The name of the font to be loaded and applied.
	 *
	 * @return string HTML and CSS tags for embedding the specified font.
	 */
	public static function generateFontTags(string $fontName): string
	{
		$fontClass = 'g-font-' . strtolower(str_replace(' ', '-', trim($fontName)));

		return self::generateFontTagsByClass($fontClass, $fontName);
	}

	/**
	 * Generates HTML and CSS tags for a specified font class.
	 *
	 * @param string $fontClass The g-font-* class that should receive the font-family rule.
	 * @param string $fontName The Google Fonts family to load.
	 * @param string $genericFamily Generic CSS fallback family.
	 *
	 * @return string HTML and CSS tags for embedding the specified font.
	 */
	public static function generateFontTagsByClass(
		string $fontClass,
		string $fontName,
		string $genericFamily = 'sans-serif',
	): string
	{
		$proxyDomain = self::getProxyDomain();
		$fontClass = strtolower(trim($fontClass));
		if (!str_starts_with($fontClass, 'g-font-'))
		{
			$fontClass = 'g-font-' . $fontClass;
		}
		$fontClass = preg_replace('/[^a-z0-9-]/', '-', $fontClass) ?: $fontClass;
		$genericFamily = in_array($genericFamily, ['serif', 'sans-serif', 'monospace', 'cursive'], true)
			? $genericFamily
			: 'sans-serif';
		$fontName = trim($fontName);
		$fontUrl = "https://{$proxyDomain}/css2?family="
			. str_replace(' ', '+', $fontName)
			. ":wght@100;200;300;400;500;600;700;800;900";
		$escapedFontClass = \htmlspecialcharsbx($fontClass, ENT_QUOTES | ENT_SUBSTITUTE);
		$escapedFontName = \htmlspecialcharsbx($fontName, ENT_QUOTES | ENT_SUBSTITUTE);
		$escapedFontUrl = \htmlspecialcharsbx($fontUrl, ENT_QUOTES | ENT_SUBSTITUTE);

		return <<<HTML
			<noscript><link rel="stylesheet" href="$escapedFontUrl" data-font="$escapedFontClass"></noscript>
			<link rel="preload" href="$escapedFontUrl" data-font="$escapedFontClass" onload="this.removeAttribute('onload');this.rel='stylesheet'" as="style">
			<style data-id="$escapedFontClass">.$escapedFontClass { font-family: "$escapedFontName", $genericFamily; }</style>
		HTML;
	}

	/**
	 * Proxy font url to bitrix servers
	 * @param string $fontString - string of font with link, noscript or other tags
	 * @return string
	 */
	protected static function proxyFontUrl(string $fontString): string
	{
		$defaultDomain = self::DEFAULT_DOMAIN;
		$proxyDomain = self::getProxyDomain();

		return ($defaultDomain !== $proxyDomain)
			? str_replace($defaultDomain, $proxyDomain, $fontString)
			: $fontString
		;
	}

	/**
	 * Returns the proxy domain for fonts, depending on the current zone and UI module.
	 *
	 * @return string
	 */
	private static function getProxyDomain(): string
	{
		$proxyDomain = self::DEFAULT_DOMAIN;
		if (Loader::includeModule('ui'))
		{
			$proxyDomain = UI\Fonts\Proxy::resolveDomain(Manager::getZone());
		}

		return $proxyDomain;
	}
}
