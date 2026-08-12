<?php
declare(strict_types=1);

namespace Bitrix\Landing\AI\SiteBuilder\Html;

use Bitrix\Landing\Assets\PreProcessing;
use Bitrix\Main\Web\DOM;

final class AiFontAwesomeIconNormalizer
{
	private const ROOT_NODE_ID = 'ai-font-awesome-icon-normalizer-root';
	private const DEFAULT_ICON_CLASS = 'fa-stars';
	private const VENDOR_SEARCH_ORDER = ['far', 'fas', 'fal', 'fat', 'fab'];
	private const FA_VENDOR_ALIASES = [
		'fas' => 'fas',
		'far' => 'far',
		'fal' => 'fal',
		'fat' => 'fat',
		'fab' => 'fab',
		'fa-solid' => 'fas',
		'fa-regular' => 'far',
		'fa-light' => 'fal',
		'fa-thin' => 'fat',
		'fa-brands' => 'fab',
	];
	private const SERVICE_FA_CLASSES = [
		'fa-fw' => true,
		'fa-ul' => true,
		'fa-li' => true,
		'fa-border' => true,
		'fa-pull-left' => true,
		'fa-pull-right' => true,
		'fa-spin' => true,
		'fa-pulse' => true,
		'fa-beat' => true,
		'fa-fade' => true,
		'fa-beat-fade' => true,
		'fa-bounce' => true,
		'fa-flip' => true,
		'fa-shake' => true,
		'fa-spin-pulse' => true,
		'fa-swap-opacity' => true,
		'fa-inverse' => true,
		'fa-stack' => true,
		'fa-stack-1x' => true,
		'fa-stack-2x' => true,
		'fa-layers' => true,
		'fa-layers-text' => true,
		'fa-layers-counter' => true,
		'fa-primary' => true,
		'fa-secondary' => true,
	];
	private const FOREIGN_ICON_CLASS_MAP = [
		'lucide-phone' => 'fa-phone',
		'lucide-mail' => 'fa-envelope',
		'lucide-envelope' => 'fa-envelope',
		'lucide-map-pin' => 'fa-map-marker',
		'lucide-calendar' => 'fa-calendar',
		'lucide-clock' => 'fa-clock',
		'lucide-user' => 'fa-user',
		'lucide-users' => 'fa-users',
		'lucide-shield' => 'fa-shield',
		'lucide-rocket' => 'fa-rocket',
		'lucide-message-circle' => 'fa-comments',
		'lucide-message-square' => 'fa-comments',
		'lucide-check' => 'fa-check',
		'lucide-star' => 'fa-star',
		'lucide-heart' => 'fa-heart',
		'lucide-zap' => 'fa-bolt',
		'lucide-arrow-right' => 'fa-arrow-right',
		'lucide-play' => 'fa-play',
		'lucide-globe' => 'fa-globe',
		'lucide-briefcase' => 'fa-briefcase',
		'bi-telephone' => 'fa-phone',
		'bi-phone' => 'fa-phone',
		'bi-envelope' => 'fa-envelope',
		'bi-geo-alt' => 'fa-map-marker',
		'bi-calendar' => 'fa-calendar',
		'bi-clock' => 'fa-clock',
		'bi-person' => 'fa-user',
		'bi-people' => 'fa-users',
		'bi-shield' => 'fa-shield',
		'bi-rocket' => 'fa-rocket',
		'bi-chat-dots' => 'fa-comments',
		'bi-check' => 'fa-check',
		'bi-star' => 'fa-star',
		'bi-heart' => 'fa-heart',
		'bi-lightning' => 'fa-bolt',
		'bi-arrow-right' => 'fa-arrow-right',
		'bi-play' => 'fa-play',
		'bi-globe' => 'fa-globe',
		'bi-briefcase' => 'fa-briefcase',
	];

	public static function normalizeHtml(string $html): string
	{
		if (trim($html) === '')
		{
			return trim($html);
		}

		try
		{
			return self::normalizeWithDom($html);
		}
		catch (\Throwable)
		{
			return self::normalizeWithRegexFallback($html);
		}
	}

	private static function normalizeWithDom(string $html): string
	{
		$doc = new DOM\Document();
		$doc->loadHTML('<div id="' . self::ROOT_NODE_ID . '">' . $html . '</div>');

		$root = $doc->querySelector('#' . self::ROOT_NODE_ID);
		if (!$root)
		{
			return self::normalizeWithRegexFallback($html);
		}

		$hasChanges = false;
		foreach ($root->querySelectorAll('[class]') as $node)
		{
			$classList = (string)$node->getAttribute('class');
			$normalizedClassList = self::normalizeClassList($classList, (string)$node->getNodeName());
			if ($normalizedClassList !== $classList)
			{
				$node->setAttribute('class', $normalizedClassList);
				$hasChanges = true;
			}
		}

		return $hasChanges ? trim($root->getInnerHTML()) : trim($html);
	}

	private static function normalizeWithRegexFallback(string $html): string
	{
		$result = preg_replace_callback(
			'/<([a-z][a-z0-9:-]*)(\s[^<>]*?)>/isu',
			static function (array $tagMatches): string
			{
				$tagName = (string)($tagMatches[1] ?? '');
				$attributes = (string)($tagMatches[2] ?? '');

				$normalizedAttributes = preg_replace_callback(
					'/\bclass\s*=\s*(["\'])(.*?)\1/isu',
					static function (array $classMatches) use ($tagName): string
					{
						$quote = (string)($classMatches[1] ?? '"');
						$classList = (string)($classMatches[2] ?? '');

						return 'class=' . $quote . self::normalizeClassList($classList, $tagName) . $quote;
					},
					$attributes,
					1,
				);

				return '<' . $tagName . ($normalizedAttributes ?? $attributes) . '>';
			},
			$html,
		);

		return trim(is_string($result) ? $result : $html);
	}

	private static function normalizeClassList(string $classList, string $tagName = ''): string
	{
		$tokens = preg_split('/\s+/u', trim($classList), -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($tokens) || $tokens === [])
		{
			return trim($classList);
		}

		$lowerTokens = array_map(static fn(string $token): string => mb_strtolower($token), $tokens);
		$hasFaSystem = in_array('fa', $lowerTokens, true);
		$hasFaIconCandidate = false;
		$hasForeignIcon = false;

		foreach ($lowerTokens as $token)
		{
			if (isset(self::FA_VENDOR_ALIASES[$token]))
			{
				$hasFaSystem = true;
			}

			if (self::isIconClassCandidate($token))
			{
				$hasFaIconCandidate = true;
			}

			if (self::isForeignIconClass($token))
			{
				$hasForeignIcon = true;
			}
		}

		$isIconTag = in_array(mb_strtolower($tagName), ['i', 'span'], true);
		if (!$hasFaSystem && !$hasFaIconCandidate && !($isIconTag && $hasForeignIcon))
		{
			return trim($classList);
		}

		$preferredVendors = self::extractPreferredVendors($lowerTokens);
		$iconClass = self::resolveValidIconClass($lowerTokens, $preferredVendors);
		if ($iconClass === null && $isIconTag)
		{
			$iconClass = self::resolveForeignIconClass($lowerTokens);
		}
		if ($iconClass === null)
		{
			$iconClass = self::DEFAULT_ICON_CLASS;
		}

		$normalizedTokens = ['fa', $iconClass];
		foreach ($tokens as $token)
		{
			$lowerToken = mb_strtolower($token);
			if (
				$lowerToken === 'fa'
				|| isset(self::FA_VENDOR_ALIASES[$lowerToken])
				|| (self::isIconClassCandidate($lowerToken) && !self::isServiceFaClass($lowerToken))
				|| self::isForeignIconClass($lowerToken)
			)
			{
				continue;
			}

			if (!self::containsToken($normalizedTokens, $token))
			{
				$normalizedTokens[] = $token;
			}
		}

		return implode(' ', $normalizedTokens);
	}

	private static function extractPreferredVendors(array $lowerTokens): array
	{
		$vendors = [];
		foreach ($lowerTokens as $token)
		{
			$vendor = self::FA_VENDOR_ALIASES[$token] ?? null;
			if ($vendor !== null && !in_array($vendor, $vendors, true))
			{
				$vendors[] = $vendor;
			}
		}

		return array_values(array_unique([...$vendors, ...self::VENDOR_SEARCH_ORDER]));
	}

	private static function resolveValidIconClass(array $lowerTokens, array $preferredVendors): ?string
	{
		foreach ($lowerTokens as $token)
		{
			if (!self::isIconClassCandidate($token) || self::isServiceFaClass($token))
			{
				continue;
			}

			if (self::isValidIconClass($token, $preferredVendors))
			{
				return $token;
			}
		}

		return null;
	}

	private static function resolveForeignIconClass(array $lowerTokens): ?string
	{
		foreach ($lowerTokens as $token)
		{
			$mappedClass = self::FOREIGN_ICON_CLASS_MAP[$token] ?? null;
			if ($mappedClass !== null && self::isValidIconClass($mappedClass, self::VENDOR_SEARCH_ORDER))
			{
				return $mappedClass;
			}
		}

		return null;
	}

	private static function isIconClassCandidate(string $token): bool
	{
		return preg_match('/^fa-[a-z0-9][a-z0-9-]*$/u', $token) === 1;
	}

	private static function isServiceFaClass(string $token): bool
	{
		if (isset(self::SERVICE_FA_CLASSES[$token]))
		{
			return true;
		}

		return preg_match('/^fa-(?:xs|sm|lg|xl|2xl|[1-9]x|10x|rotate-(?:90|180|270)|flip-(?:horizontal|vertical|both))$/u', $token) === 1;
	}

	private static function isForeignIconClass(string $token): bool
	{
		return isset(self::FOREIGN_ICON_CLASS_MAP[$token])
			|| in_array($token, ['lucide', 'bi', 'material-icons', 'material-symbols-outlined', 'material-symbols-rounded', 'material-symbols-sharp'], true)
			|| preg_match('/^(?:lucide|bi|mdi|ri|heroicon)-[a-z0-9-]+$/u', $token) === 1
		;
	}

	private static function containsToken(array $tokens, string $token): bool
	{
		$lowerToken = mb_strtolower($token);
		foreach ($tokens as $existingToken)
		{
			if (mb_strtolower($existingToken) === $lowerToken)
			{
				return true;
			}
		}

		return false;
	}

	private static function isValidIconClass(string $className, array $vendors): bool
	{
		foreach ($vendors as $vendor)
		{
			if (!in_array($vendor, self::VENDOR_SEARCH_ORDER, true))
			{
				continue;
			}

			try
			{
				if (PreProcessing\Icon::getIconContentByClass($className, $vendor) !== null)
				{
					return true;
				}
			}
			catch (\Throwable)
			{
			}

			if (self::getLocalIconContentByClass($className, $vendor) !== null)
			{
				return true;
			}
		}

		return false;
	}

	private static function getLocalIconContentByClass(string $className, string $vendor): ?string
	{
		$content = self::getLocalIconContentByVendor($vendor);

		return $content[$className] ?? null;
	}

	private static function getLocalIconContentByVendor(string $vendor): array
	{
		static $vendorContent = [];

		if (array_key_exists($vendor, $vendorContent))
		{
			return $vendorContent[$vendor];
		}

		$vendorContent[$vendor] = [];
		$contentFile = dirname(__DIR__, 4) . '/install/templates/landing24/assets/vendor/icon/' . $vendor . '/content.css';
		if (!is_file($contentFile))
		{
			return $vendorContent[$vendor];
		}

		$content = file_get_contents($contentFile);
		if (!is_string($content) || $content === '')
		{
			return $vendorContent[$vendor];
		}

		if (
			preg_match_all(
				'/\.(fa-[a-z0-9-]+):{1,2}before\s*{\s*content:\s*["\']((?:\\\\.|[^\\\\])*?)["\'];\s*}/u',
				$content,
				$matches,
			)
		)
		{
			foreach ($matches[1] as $index => $iconClass)
			{
				$vendorContent[$vendor][mb_strtolower((string)$iconClass)] = (string)($matches[2][$index] ?? '');
			}
		}

		return $vendorContent[$vendor];
	}
}
