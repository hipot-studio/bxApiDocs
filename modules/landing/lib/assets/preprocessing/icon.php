<?php
namespace Bitrix\Landing\Assets\PreProcessing;

use \Bitrix\Landing\Block;
use \Bitrix\Landing\Node;
use \Bitrix\Landing\Config;
use \Bitrix\Landing\Manager;
use \Bitrix\Landing\Landing;
use \Bitrix\Landing\Assets;
use \Bitrix\Landing\Internals\BlockTable;
use \Bitrix\Main\IO\File;

class Icon
{
	/**
	 * File name with icon content.
	 */
	const CONTENT_ICON_FILE_NAME = 'content.css';

	/**
	 * File name with icon css rules.
	 */
	const RULE_ICON_FILE_NAME = 'style.css';

	/**
	 * File names of two types of fonts
	 */
	const ICON_FONT_FILE_NAME = 'font.woff';
	const ICON_FONT_FILE_NAME_2 = 'font.woff2';

	protected const VENDOR_UPDATES = [
		'fa' => 'far',
	];
	protected const ICON_UPDATES = [
		'fa' => [
			'fa-500px' => ['vendor' => 'fab'],
			'fa-adn' => ['vendor' => 'fab'],
			'fa-amazon' => ['vendor' => 'fab'],
			'fa-android' => ['vendor' => 'fab'],
			'fa-angellist' => ['vendor' => 'fab'],
			'fa-apple' => ['vendor' => 'fab'],
			'fa-bandcamp' => ['vendor' => 'fab'],
			'fa-behance' => ['vendor' => 'fab'],
			'fa-behance-square' => ['vendor' => 'fab'],
			'fa-bitbucket' => ['vendor' => 'fab'],
			'fa-bitbucket-square' => [
				'vendor' => 'fab',
				'content' => '\\f171',
			],
			'fa-bitcoin' => [
				'vendor' => 'fab',
				'content' => '\\f379',
			],
			'fa-black-tie' => ['vendor' => 'fab'],
			'fa-bluetooth' => ['vendor' => 'fab'],
			'fa-bluetooth-b' => ['vendor' => 'fab'],
			'fa-btc' => ['vendor' => 'fab'],
			'fa-buysellads' => ['vendor' => 'fab'],
			'fa-cc-amex' => ['vendor' => 'fab'],
			'fa-cc-diners-club' => ['vendor' => 'fab'],
			'fa-cc-discover' => ['vendor' => 'fab'],
			'fa-cc-jcb' => ['vendor' => 'fab'],
			'fa-cc-mastercard' => ['vendor' => 'fab'],
			'fa-cc-paypal' => ['vendor' => 'fab'],
			'fa-cc-stripe' => ['vendor' => 'fab'],
			'fa-cc-visa' => ['vendor' => 'fab'],
			'fa-chrome' => ['vendor' => 'fab'],
			'fa-codepen' => ['vendor' => 'fab'],
			'fa-codiepie' => ['vendor' => 'fab'],
			'fa-connectdevelop' => ['vendor' => 'fab'],
			'fa-contao' => ['vendor' => 'fab'],
			'fa-creative-commons' => ['vendor' => 'fab'],
			'fa-css3' => ['vendor' => 'fab'],
			'fa-dashcube' => ['vendor' => 'fab'],
			'fa-delicious' => ['vendor' => 'fab'],
			'fa-deviantart' => ['vendor' => 'fab'],
			'fa-digg' => ['vendor' => 'fab'],
			'fa-dribbble' => ['vendor' => 'fab'],
			'fa-dropbox' => ['vendor' => 'fab'],
			'fa-drupal' => ['vendor' => 'fab'],
			'fa-edge' => ['vendor' => 'fab'],
			'fa-eercast' => [
				'vendor' => 'fab',
				'content' => '\\f2da',
			],
			'fa-empire' => ['vendor' => 'fab'],
			'fa-envira' => ['vendor' => 'fab'],
			'fa-etsy' => ['vendor' => 'fab'],
			'fa-expeditedssl' => ['vendor' => 'fab'],
			'fa-fa' => [
				'vendor' => 'fab',
				'content' => '\\f2b4',
			],
			'fa-facebook' => ['vendor' => 'fab'],
			'fa-facebook-f' => [
				'vendor' => 'fab',
				'content' => '\\f39e',
			],
			'fa-facebook-official' => [
				'vendor' => 'fab',
				'content' => '\\f09a',
			],
			'fa-facebook-square' => ['vendor' => 'fab'],
			'fa-firefox' => ['vendor' => 'fab'],
			'fa-first-order' => ['vendor' => 'fab'],
			'fa-flickr' => ['vendor' => 'fab'],
			'fa-font-awesome' => ['vendor' => 'fab'],
			'fa-fonticons' => ['vendor' => 'fab'],
			'fa-fort-awesome' => ['vendor' => 'fab'],
			'fa-forumbee' => ['vendor' => 'fab'],
			'fa-foursquare' => ['vendor' => 'fab'],
			'fa-free-code-camp' => ['vendor' => 'fab'],
			'fa-ge' => [
				'vendor' => 'fab',
				'content' => '\\f1d1',
			],
			'fa-get-pocket' => ['vendor' => 'fab'],
			'fa-gg' => ['vendor' => 'fab'],
			'fa-gg-circle' => ['vendor' => 'fab'],
			'fa-git' => ['vendor' => 'fab'],
			'fa-git-square' => ['vendor' => 'fab'],
			'fa-github' => ['vendor' => 'fab'],
			'fa-github-alt' => ['vendor' => 'fab'],
			'fa-github-square' => ['vendor' => 'fab'],
			'fa-gitlab' => ['vendor' => 'fab'],
			'fa-gittip' => [
				'vendor' => 'fab',
				'content' => '\\f184',
			],
			'fa-glide' => ['vendor' => 'fab'],
			'fa-glide-g' => ['vendor' => 'fab'],
			'fa-google' => ['vendor' => 'fab'],
			'fa-google-plus' => [
				'vendor' => 'fab',
				'content' => '\\f2b3',
			],
			'fa-google-plus-circle' => [
				'vendor' => 'fab',
				'content' => '\\f2b3',
			],
			'fa-google-plus-official' => [
				'vendor' => 'fab',
				'content' => '\\f2b3',
			],
			'fa-google-plus-square' => ['vendor' => 'fab'],
			'fa-google-wallet' => ['vendor' => 'fab'],
			'fa-gratipay' => ['vendor' => 'fab'],
			'fa-grav' => ['vendor' => 'fab'],
			'fa-hacker-news' => ['vendor' => 'fab'],
			'fa-houzz' => ['vendor' => 'fab'],
			'fa-html5' => ['vendor' => 'fab'],
			'fa-imdb' => ['vendor' => 'fab'],
			'fa-instagram' => ['vendor' => 'fab'],
			'fa-internet-explorer' => ['vendor' => 'fab'],
			'fa-ioxhost' => ['vendor' => 'fab'],
			'fa-joomla' => ['vendor' => 'fab'],
			'fa-jsfiddle' => ['vendor' => 'fab'],
			'fa-lastfm' => ['vendor' => 'fab'],
			'fa-lastfm-square' => ['vendor' => 'fab'],
			'fa-leanpub' => ['vendor' => 'fab'],
			'fa-linkedin' => [
				'vendor' => 'fab',
				'content' => '\\f08c',
			],
			'fa-linkedin-square' => [
				'vendor' => 'fab',
				'content' => '\\f08c',
			],
			'fa-linode' => ['vendor' => 'fab'],
			'fa-linux' => ['vendor' => 'fab'],
			'fa-maxcdn' => ['vendor' => 'fab'],
			'fa-meanpath' => [
				'vendor' => 'fab',
				'content' => '\\f2b4',
			],
			'fa-medium' => ['vendor' => 'fab'],
			'fa-meetup' => ['vendor' => 'fab'],
			'fa-mixcloud' => ['vendor' => 'fab'],
			'fa-modx' => ['vendor' => 'fab'],
			'fa-odnoklassniki' => ['vendor' => 'fab'],
			'fa-odnoklassniki-square' => ['vendor' => 'fab'],
			'fa-opencart' => ['vendor' => 'fab'],
			'fa-openid' => ['vendor' => 'fab'],
			'fa-opera' => ['vendor' => 'fab'],
			'fa-optin-monster' => ['vendor' => 'fab'],
			'fa-pagelines' => ['vendor' => 'fab'],
			'fa-paypal' => ['vendor' => 'fab'],
			'fa-pied-piper' => ['vendor' => 'fab'],
			'fa-pied-piper-alt' => ['vendor' => 'fab'],
			'fa-pied-piper-pp' => ['vendor' => 'fab'],
			'fa-pinterest' => ['vendor' => 'fab'],
			'fa-pinterest-p' => ['vendor' => 'fab'],
			'fa-pinterest-square' => ['vendor' => 'fab'],
			'fa-product-hunt' => ['vendor' => 'fab'],
			'fa-qq' => ['vendor' => 'fab'],
			'fa-quora' => ['vendor' => 'fab'],
			'fa-ra' => [
				'vendor' => 'fab',
				'content' => '\\f1d0',
			],
			'fa-ravelry' => ['vendor' => 'fab'],
			'fa-rebel' => ['vendor' => 'fab'],
			'fa-reddit' => ['vendor' => 'fab'],
			'fa-reddit-alien' => ['vendor' => 'fab'],
			'fa-reddit-square' => ['vendor' => 'fab'],
			'fa-renren' => ['vendor' => 'fab'],
			'fa-resistance' => [
				'vendor' => 'fab',
				'content' => '\\f1d0',
			],
			'fa-safari' => ['vendor' => 'fab'],
			'fa-scribd' => ['vendor' => 'fab'],
			'fa-sellsy' => ['vendor' => 'fab'],
			'fa-shirtsinbulk' => ['vendor' => 'fab'],
			'fa-simplybuilt' => ['vendor' => 'fab'],
			'fa-skyatlas' => ['vendor' => 'fab'],
			'fa-skype' => ['vendor' => 'fab'],
			'fa-slack' => ['vendor' => 'fab'],
			'fa-slideshare' => ['vendor' => 'fab'],
			'fa-snapchat' => ['vendor' => 'fab'],
			'fa-snapchat-ghost' => [
				'vendor' => 'fab',
				'content' => '\\f2ab',
			],
			'fa-snapchat-square' => ['vendor' => 'fab'],
			'fa-soundcloud' => ['vendor' => 'fab'],
			'fa-spotify' => ['vendor' => 'fab'],
			'fa-stack-exchange' => ['vendor' => 'fab'],
			'fa-stack-overflow' => ['vendor' => 'fab'],
			'fa-steam' => ['vendor' => 'fab'],
			'fa-steam-square' => ['vendor' => 'fab'],
			'fa-stumbleupon' => ['vendor' => 'fab'],
			'fa-stumbleupon-circle' => ['vendor' => 'fab'],
			'fa-superpowers' => ['vendor' => 'fab'],
			'fa-telegram' => ['vendor' => 'fab'],
			'fa-tencent-weibo' => ['vendor' => 'fab'],
			'fa-themeisle' => ['vendor' => 'fab'],
			'fa-trello' => ['vendor' => 'fab'],
			'fa-tumblr' => ['vendor' => 'fab'],
			'fa-tumblr-square' => ['vendor' => 'fab'],
			'fa-twitch' => ['vendor' => 'fab'],
			'fa-twitter' => ['vendor' => 'fab'],
			'fa-twitter-square' => ['vendor' => 'fab'],
			'fa-usb' => ['vendor' => 'fab'],
			'fa-viacoin' => ['vendor' => 'fab'],
			'fa-viadeo' => ['vendor' => 'fab'],
			'fa-viadeo-square' => ['vendor' => 'fab'],
			'fa-vimeo' => [
				'vendor' => 'fab',
				'content' => '\\f40a',
			],
			'fa-vimeo-square' => ['vendor' => 'fab'],
			'fa-vine' => ['vendor' => 'fab'],
			'fa-vk' => ['vendor' => 'fab'],
			'fa-wechat' => [
				'vendor' => 'fab',
				'content' => '\\f1d7',
			],
			'fa-weibo' => ['vendor' => 'fab'],
			'fa-weixin' => ['vendor' => 'fab'],
			'fa-whatsapp' => ['vendor' => 'fab'],
			'fa-wikipedia-w' => ['vendor' => 'fab'],
			'fa-windows' => ['vendor' => 'fab'],
			'fa-wordpress' => ['vendor' => 'fab'],
			'fa-wpbeginner' => ['vendor' => 'fab'],
			'fa-wpexplorer' => ['vendor' => 'fab'],
			'fa-wpforms' => ['vendor' => 'fab'],
			'fa-xing' => ['vendor' => 'fab'],
			'fa-xing-square' => ['vendor' => 'fab'],
			'fa-y-combinator' => ['vendor' => 'fab'],
			'fa-y-combinator-square' => [
				'vendor' => 'fab',
				'content' => '\\f1d4',
			],
			'fa-yahoo' => ['vendor' => 'fab'],
			'fa-yc' => [
				'vendor' => 'fab',
				'content' => '\\f1d4',
			],
			'fa-yc-square' => [
				'vendor' => 'fab',
				'content' => '\\f1d4',
			],
			'fa-yelp' => ['vendor' => 'fab'],
			'fa-yoast' => ['vendor' => 'fab'],
			'fa-youtube' => ['vendor' => 'fab'],
			'fa-youtube-play' => [
				'vendor' => 'fab',
				'content' => '\\f167',
			],
			'fa-youtube-square' => [
				'vendor' => 'fab',
				'content' => '\\f431',
			],

			'fa-plus' => ['content' => '\2b'],
			'fa-usd' => ['content' => '\24'],
			'fa-dollar' => ['content' => '\24'],
			'fa-rupee' => ['content' => '\e1bc'],
			'fa-inr' => ['content' => '\e1bc'],
			'fa-wheelchair-alt' => ['content' => '\\e2ce',],
		],
	];

	/**
	 * Tries to resolve and returns icon file path.
	 * @param string $vendorName Vendor folder code.
	 * @return string|null
	 */
	protected static function getIconsPath(string $vendorName): ?string
	{
		$iconSrc = Config::get('icon_src');
		$iconSrc = Manager::getDocRoot() . $iconSrc . $vendorName;
		if (is_dir($iconSrc))
		{
			return $iconSrc;
		}

		return null;
	}

	/**
	 * Returns the preload font file that matches vendor CSS.
	 *
	 * @param string $vendorName Vendor folder code.
	 *
	 * @return string|null
	 */
	protected static function getPreferredFontPath(string $vendorName): ?string
	{
		static $fontPathByVendor = [];

		if (!array_key_exists($vendorName, $fontPathByVendor))
		{
			$preferredFontPath = null;
			$iconsPath = self::getIconsPath($vendorName);
			$iconSrc = Config::get('icon_src');

			if ($iconsPath && $iconSrc)
			{
				$stylesFile = $iconsPath . '/' . self::RULE_ICON_FILE_NAME;
				$cssContent = File::isFileExists($stylesFile) ? File::getFileContents($stylesFile) : '';
				$fontCandidates = [];

				if (
					$cssContent
					&& preg_match(
						'/' . preg_quote(self::ICON_FONT_FILE_NAME_2, '/') . '(?![[:alnum:]])/',
						$cssContent,
					)
				)
				{
					$fontCandidates[] = self::ICON_FONT_FILE_NAME_2;
				}

				if (
					$cssContent
					&& preg_match(
						'/' . preg_quote(self::ICON_FONT_FILE_NAME, '/') . '(?![[:alnum:]])/',
						$cssContent,
					)
				)
				{
					$fontCandidates[] = self::ICON_FONT_FILE_NAME;
				}

				if (!$fontCandidates)
				{
					$fontCandidates = [
						self::ICON_FONT_FILE_NAME_2,
						self::ICON_FONT_FILE_NAME,
					];
				}

				foreach ($fontCandidates as $fontFileName)
				{
					if (File::isFileExists($iconsPath . '/' . $fontFileName))
					{
						$preferredFontPath = $iconSrc . $vendorName . '/' . $fontFileName;
						break;
					}
				}
			}

			$fontPathByVendor[$vendorName] = $preferredFontPath;
		}

		return $fontPathByVendor[$vendorName] ?? null;
	}

	/**
	 * Returns the vendor stylesheet content with its own @font-face rule stripped out.
	 * Used together with buildInlineFontFace(): linking the file as is would keep a networked
	 * font URL on the page, which the opaque origin of the sandboxed preview turns into a
	 * blocked cross-origin request even though the inlined font already renders the icons.
	 * Vendor stylesheets carry no other url() references, so inlining needs no path rewriting.
	 *
	 * @param string $vendorName Vendor folder code.
	 *
	 * @return string|null
	 */
	protected static function buildInlineVendorStyles(string $vendorName): ?string
	{
		$iconsPath = self::getIconsPath($vendorName);
		if (!$iconsPath)
		{
			return null;
		}

		$stylesFile = $iconsPath . '/' . self::RULE_ICON_FILE_NAME;
		$cssContent = File::isFileExists($stylesFile) ? File::getFileContents($stylesFile) : '';
		if (!$cssContent)
		{
			return null;
		}

		return preg_replace('/@font-face\s*{.+?}/is', '', $cssContent);
	}

	/**
	 * Builds an inline data-URI @font-face override for device-preview mode.
	 * The sandboxed preview iframe has an opaque origin, so a networked icon-font
	 * fetch becomes cross-origin and is blocked; inlining the font avoids the fetch.
	 * Returns null on any missing data to fall back to the normal preload path.
	 *
	 * @param string $vendorName Vendor folder code.
	 * @param string $fontWebPath Web path of the preferred font file.
	 *
	 * @return string|null
	 */
	protected static function buildInlineFontFace(string $vendorName, string $fontWebPath): ?string
	{
		$fontFilePath = Manager::getDocRoot() . $fontWebPath;
		if (!File::isFileExists($fontFilePath))
		{
			return null;
		}

		$iconsPath = self::getIconsPath($vendorName);
		if (!$iconsPath)
		{
			return null;
		}

		$stylesFile = $iconsPath . '/' . self::RULE_ICON_FILE_NAME;
		$cssContent = File::isFileExists($stylesFile) ? File::getFileContents($stylesFile) : '';
		if (
			!$cssContent
			|| !preg_match('/@font-face\s*{(.+?)}/is', $cssContent, $face)
			|| !preg_match('/font-family\s*:\s*([\'"])(.+?)\1/i', $face[1], $family)
		)
		{
			return null;
		}
		// Keep the original weight/style: FontAwesome Pro sets share one family
		// and differ only by font-weight (far 400, fas 900, fal 300, fat 100).
		$fontWeight = preg_match('/font-weight\s*:\s*([^;]+);/i', $face[1], $fw) ? trim($fw[1]) : 'normal';
		$fontStyle = preg_match('/font-style\s*:\s*([^;]+);/i', $face[1], $fs) ? trim($fs[1]) : 'normal';

		if (substr($fontWebPath, -6) === '.woff2')
		{
			$mime = 'font/woff2';
			$format = 'woff2';
		}
		elseif (substr($fontWebPath, -5) === '.woff')
		{
			$mime = 'font/woff';
			$format = 'woff';
		}
		else
		{
			return null;
		}

		$fontData = File::getFileContents($fontFilePath);
		if (!$fontData)
		{
			return null;
		}

		return '@font-face{font-family:\'' . $family[2] . '\';'
			. 'src:url(\'data:' . $mime . ';base64,' . base64_encode($fontData) . '\') format(\'' . $format . '\');'
			. 'font-weight:' . $fontWeight . ';font-style:' . $fontStyle . ';}';
	}

	/**
	 * Parses icon file and returns content for each icon class.
	 * @param string $vendorName Vendor folder code.
	 * @return array
	 */
	protected static function getIconsContentByVendor(string $vendorName): array
	{
		static $vendorContent = [];

		if (!array_key_exists($vendorName, $vendorContent))
		{
			$vendorContent[$vendorName] = [];
			$path = self::getIconsPath($vendorName);
			if ($path)
			{
				$cssFileName = $path . '/' . self::CONTENT_ICON_FILE_NAME;
				if (File::isFileExists($cssFileName))
				{
					$cssContent = File::getFileContents($cssFileName);
					if ($cssContent)
					{
						$classPrefix = $vendorName;
						$iconVendorsConfig = Config::get('icon_vendors_config');
						if ($iconVendorsConfig && isset ($iconVendorsConfig[$vendorName]['class_prefix']))
						{
							$classPrefix = $iconVendorsConfig[$vendorName]['class_prefix'];
						}
						$found = preg_match_all(
							'/\.(' . $classPrefix . '-[^:]+):{1,2}before\s*{\s*content:\s*["\']((?:\\\\.|[^\\\\])*?)["\'];\s*}/',
							$cssContent,
							$matches
						);
						if ($found)
						{
							foreach ($matches[1] as $i => $match)
							{
								$vendorContent[$vendorName][$match] = $matches[2][$i];
							}
						}
					}
				}
			}
		}

		return $vendorContent[$vendorName];
	}

	/**
	 * Returns icon css content.
	 * @param string $className Class name.
	 * @param string $vendorName Vendor folder code.
	 * @return string|null
	 */
	public static function getIconContentByClass(string $className, string $vendorName): ?string
	{
		$contentAll = self::getIconsContentByVendor($vendorName);

		return $contentAll[$className] ?? null;
	}

	/**
	 * Tries to find any icons and save them assets to the block.
	 * @param Block $block Bock instance.
	 * @return void
	 */
	protected static function saveAssets(Block $block): void
	{
		$iconSrc = Config::get('icon_src');
		$iconVendors = Config::get('icon_vendors');
		$blockContent = $block->getContent();

		if (!$iconSrc || !$iconVendors || !$blockContent)
		{
			return;
		}

		$assetsIcon = [];
		$iconVendors = (array)$iconVendors;
		$vendorsStr = '(' . implode('|', $iconVendors) . ')';
		$prefixesStr = $vendorsStr;
		// special vendors
		$iconVendorsConfig = Config::get('icon_vendors_config');
		if ($iconVendorsConfig && !empty($iconVendorsConfig))
		{
			$classPrefixes = $iconVendors;
			foreach ($iconVendorsConfig as $vendor => $config)
			{
				$classPrefixes[array_search($vendor, $classPrefixes, true)] = $config['class_prefix'];
			}
			$prefixesStr = '(' . implode('|', array_unique($classPrefixes)) . ')';
		}
		$found = preg_match_all(
			'/(?<=[\s"])' . $vendorsStr . '?\s*(' . $prefixesStr . '-([^\s"\/\\\]+))/s',
			$blockContent,
			$matches
		);
		if ($found)
		{
			foreach ($matches[0] as $i => $full)
			{
				$vendor = $matches[1][$i]
					? trim($matches[1][$i])
					: trim($matches[3][$i]);
				$class = trim($matches[2][$i]);
				[$vendor, $class] = self::updateIconsBeforeSave($vendor, $class);
				if (!isset($assetsIcon[$vendor]))
				{
					$assetsIcon[$vendor] = [];
				}
				$assetsIcon[$vendor][$class] = self::getIconContentByClass(
					$class,
					$vendor
				);
				if ($assetsIcon[$vendor][$class] === null)
				{
					unset($assetsIcon[$vendor][$class]);
				}
				if (!$assetsIcon[$vendor])
				{
					unset($assetsIcon[$vendor]);
				}
			}
		}

		$block->saveAssets([
			'icon' => $assetsIcon
		]);
	}

	/**
	 * Processing icons in the block content.
	 * @param Block $block Block instance.
	 * @return void
	 */
	public static function processing(Block $block): void
	{
		// find assets always, because block can use icon not only as icon-node, but also just in html
		self::saveAssets($block);
	}

	/**
	 * Processing entire landing.
	 * @param int $landingId Landing id.
	 * @return void
	 */
	public static function processingLanding(int $landingId): void
	{
		$res = BlockTable::getList([
			'select' => [
				'ID'
			],
			'filter' => [
				'LID' => $landingId,
				'=DELETED' => 'N'
			]
		]);
		while ($row = $res->fetch())
		{
			$block = new Block($row['ID']);
			self::processing($block);
			$block->save();
		}
	}

	/**
	 * Shows icons styles on the block output.
	 * @param Block $block Block instance.
	 * @return void
	 */
	public static function view(Block $block): void
	{
		$blockAssets = $block->getAssets();
		if (isset($blockAssets['icon']) && !empty($blockAssets['icon']))
		{
			$assetsManager = Assets\Manager::getInstance();
			$iconSrc = Config::get('icon_src');
			if (!$iconSrc)
			{
				return;
			}
			$devicePreview = Landing::getDevicePreviewMode();
			$stylesString = '<style>';
			$blockAssets['icon'] = self::updateIconsBeforeView($blockAssets['icon']);
			foreach ($blockAssets['icon'] as $vendorName => $icons)
			{
				$fontFile = self::getPreferredFontPath($vendorName);
				$stylesFile = $iconSrc . $vendorName . '/' . self::RULE_ICON_FILE_NAME;

				// In device preview both the font and the vendor stylesheet go inline: the
				// stylesheet carries its own @font-face with a networked src, which the opaque
				// origin would still fetch (and the browser would block) next to the inlined
				// font. Either both inline or neither — a half-applied override leaves the
				// blocked request in place.
				$inlineFontFace = ($devicePreview && $fontFile)
					? self::buildInlineFontFace($vendorName, $fontFile)
					: null;
				$inlineStyles = $inlineFontFace !== null
					? self::buildInlineVendorStyles($vendorName)
					: null;

				if ($inlineStyles !== null)
				{
					$stylesString .= $inlineFontFace . $inlineStyles;
				}
				else
				{
					if ($fontFile)
					{
						$assetsManager->addAsset($fontFile);
					}
					$assetsManager->addAsset($stylesFile);
				}

				foreach ($icons as $className => $content)
				{
					$stylesString .= '.' . $className . ':before{content:"' . $content . '";}';
				}
			}
			$stylesString .= '</style>';
			$assetsManager->addString($stylesString);
		}
	}

	protected static function updateIconsBeforeView($iconData): array
	{
		$newIcons = $iconData;

		foreach ($iconData as $vendor => $icons)
		{
			if (!isset(self::VENDOR_UPDATES[$vendor]))
			{
				continue;
			}

			$newVendor = self::VENDOR_UPDATES[$vendor];

			foreach ($icons as $icon => $content)
			{
				if (isset(self::ICON_UPDATES[$vendor]))
				{
					$currVendor = self::ICON_UPDATES[$vendor][$icon]['vendor'] ?? $newVendor;
					$newContent = self::ICON_UPDATES[$vendor][$icon]['content'] ?? $content;
					$newIcons[$currVendor][$icon] = $newContent;

					unset($newIcons[$vendor][$icon]);
					if (empty($newIcons[$vendor]))
					{
						unset($newIcons[$vendor]);
					}
				}
			}
		}

		return $newIcons;
	}

	protected static function updateIconsBeforeSave(string $vendor, string $class): array
	{
		$newVendor = self::VENDOR_UPDATES[$vendor] ?? $vendor;
		$newClass = $class;
		if (isset(self::ICON_UPDATES[$vendor]))
		{
			$newVendor = self::ICON_UPDATES[$vendor][$class]['vendor'] ?? $newVendor;
		}

		return [$newVendor, $newClass];
	}
}