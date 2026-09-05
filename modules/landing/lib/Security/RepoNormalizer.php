<?php

declare(strict_types=1);

namespace Bitrix\Landing\Security;

use Bitrix\Landing\Sanitizer;

/**
 * Turns block data coming from outside - a REST application registering its block, a site
 * archive being imported - into the fields of a b_landing_repo row.
 *
 * Everything the module later does with such a block is driven by the stored manifest, and the
 * stored content is echoed on a public page as is. So both are cut down to an allow-list here:
 * a key that is not part of the published contract is dropped without a word, and the content
 * and the card presets go through Sanitizer. Where a key of the contract carries a value that
 * reaches the page on its own - the asset paths - the value is checked too. The identity of the
 * row - XML_ID and APP_CODE - comes from the call context and never from the incoming fields.
 */
final class RepoNormalizer
{
	/**
	 * Fields the caller describes itself. The identity of the item, its owner application,
	 * its author and its dates are set by the module, so they are not listed here.
	 */
	private const ALLOWED_FIELDS = [
		'NAME',
		'CONTENT',
		'SECTIONS',
		'PREVIEW',
		'DESCRIPTION',
		'ACTIVE',
		'SITE_TEMPLATE_ID',
	];

	private const ALLOWED_MANIFEST_KEYS = [
		'block',
		'nodes',
		'cards',
		'style',
		'attrs',
		'menu',
		'assets',
		'lang',
		'lang_original',
	];

	/**
	 * `namespace`, `version`, `system`, `html` and `app_code` are absent on purpose:
	 * Repo::getBlock() rebuilds the whole section from the row itself, so a stored value is
	 * dead data - except `namespace`, which feeds the class.php lookup of Block::getAsset().
	 */
	private const ALLOWED_BLOCK_KEYS = [
		'name',
		'section',
		'type',
		'dynamic',
		'subtype',
		'subtype_params',
		'description',
	];

	/**
	 * `class` and `callbacks` are absent on purpose: Block::getAsset() turns `assets.class`
	 * into a path to include and Block::createFromRepository() calls `callbacks.afteradd`,
	 * so either of them stored would mean arbitrary code execution on the portal. The actual
	 * selection of extensions happens on read, against Block::$allowedRepoExtensions.
	 */
	private const ALLOWED_ASSET_KEYS = [
		'css',
		'js',
		'ext',
	];

	/**
	 * Extension an asset path has to carry to be stored under its key. `ext` is absent on
	 * purpose: it holds the name of an extension and not a path.
	 */
	private const ASSET_PATH_EXTENSIONS = [
		'css' => '.css',
		'js' => '.js',
	];

	/**
	 * Characters an asset path may not carry. A quote, a backtick or an angle bracket ends the
	 * attribute the tag of Assets\Manager is built around; a space or a control character hides
	 * where the address really points, and browsers drop them while resolving a url anyway, so
	 * nothing legitimate needs them unencoded.
	 */
	private const UNSAFE_ASSET_PATH_PATTERN = '~[\x00-\x20\x7F"\'<>\\\\`]~';

	private const SANITIZED_PRESET_KEYS = [
		'html',
		'name',
		'values',
	];

	/**
	 * Builds the fields of the repository row out of the incoming data.
	 *
	 * @param array $fields Incoming fields.
	 * @param mixed $manifest Incoming manifest; anything but an array is stored as an empty one.
	 * @param string $xmlId Unique code of the block within the application.
	 * @param string|null $appCode Code of the owner application, null for a portal-wide item.
	 * @return NormalizedRepoBlock
	 */
	public static function normalize(
		array $fields,
		mixed $manifest,
		string $xmlId,
		?string $appCode = null,
	): NormalizedRepoBlock
	{
		$sanitizer = new Sanitizer();
		$contentRejected = false;

		$normalized = array_intersect_key($fields, array_flip(self::ALLOWED_FIELDS));
		if (isset($normalized['CONTENT']))
		{
			$normalized['CONTENT'] = self::sanitizeContent($sanitizer, $normalized['CONTENT'], $contentRejected);
		}

		$normalized['MANIFEST'] = serialize(self::normalizeManifest($sanitizer, $manifest, $contentRejected));
		$normalized['XML_ID'] = $xmlId;
		if ($appCode !== null)
		{
			$normalized['APP_CODE'] = $appCode;
		}

		return new NormalizedRepoBlock($normalized, $contentRejected);
	}

	private static function normalizeManifest(Sanitizer $sanitizer, mixed $manifest, bool &$contentRejected): array
	{
		if (!is_array($manifest))
		{
			return [];
		}

		$normalized = array_intersect_key($manifest, array_flip(self::ALLOWED_MANIFEST_KEYS));
		$normalized = self::dropNonDataValues($normalized);

		if (isset($normalized['block']) && is_array($normalized['block']))
		{
			$normalized['block'] = array_intersect_key(
				$normalized['block'],
				array_flip(self::ALLOWED_BLOCK_KEYS),
			);
		}
		if (isset($normalized['assets']) && is_array($normalized['assets']))
		{
			$normalized['assets'] = self::normalizeAssets($normalized['assets']);
		}
		if (isset($normalized['cards']) && is_array($normalized['cards']))
		{
			$normalized['cards'] = self::sanitizeCards($sanitizer, $normalized['cards'], $contentRejected);
		}

		return $normalized;
	}

	/**
	 * A manifest is data - scalars, nulls and arrays of them. An archive may carry a serialized
	 * object, and unserialize() with `allowed_classes` turned off hands it over as
	 * __PHP_Incomplete_Class: there is nothing left to execute, but every reader of the manifest
	 * fatals on the first property access, so such a value is dropped along with the rest.
	 */
	private static function dropNonDataValues(array $values): array
	{
		foreach ($values as $key => $value)
		{
			if (is_array($value))
			{
				$values[$key] = self::dropNonDataValues($value);
			}
			elseif ($value !== null && !is_scalar($value))
			{
				unset($values[$key]);
			}
		}

		return $values;
	}

	/**
	 * The paths of `css` and `js` are printed into the src/href attribute of the tag
	 * Assets\Manager::createStringFromPath() puts on a public page, so a value that cannot be
	 * the path of a resource is dropped: no character able to end that attribute, no scheme
	 * besides http and https, and the extension of the key the value is stored under.
	 *
	 * The extension is what the module already demands of a local path - Manager::processAsset()
	 * ignores a path detectType() cannot classify - so the rule only brings the branch of an
	 * external link in line with it, and it keeps `js` from naming a .php the web server runs.
	 */
	private static function normalizeAssets(array $assets): array
	{
		$assets = array_intersect_key($assets, array_flip(self::ALLOWED_ASSET_KEYS));

		foreach (self::ASSET_PATH_EXTENSIONS as $key => $extension)
		{
			if (!array_key_exists($key, $assets))
			{
				continue;
			}

			$assets[$key] = is_array($assets[$key])
				? array_values(array_filter(
					$assets[$key],
					static fn (mixed $path): bool => is_string($path) && self::isAssetPath($path, $extension),
				))
				: [];
		}

		return $assets;
	}

	private static function isAssetPath(string $path, string $extension): bool
	{
		if (preg_match(self::UNSAFE_ASSET_PATH_PATTERN, $path) !== 0)
		{
			return false;
		}

		if (
			preg_match('~^([a-z][a-z0-9+.\-]*):~i', $path, $scheme) === 1
			&& !in_array(mb_strtolower($scheme[1]), ['http', 'https'], true)
		)
		{
			return false;
		}

		// a query string and a fragment belong to the address, the extension is in the path
		$withoutQuery = substr($path, 0, strcspn($path, '?#'));

		return str_ends_with(mb_strtolower($withoutQuery), $extension);
	}

	private static function sanitizeCards(Sanitizer $sanitizer, array $cards, bool &$contentRejected): array
	{
		foreach ($cards as $cardCode => $card)
		{
			if (!is_array($card) || !isset($card['presets']) || !is_array($card['presets']))
			{
				continue;
			}

			$cards[$cardCode]['presets'] = self::sanitizePresets($sanitizer, $card['presets'], $contentRejected);
		}

		return $cards;
	}

	private static function sanitizePresets(Sanitizer $sanitizer, array $presets, bool &$contentRejected): array
	{
		foreach ($presets as $presetCode => $preset)
		{
			if (!is_array($preset))
			{
				continue;
			}

			$presets[$presetCode] = self::sanitizePreset($sanitizer, $preset, $contentRejected);
		}

		return $presets;
	}

	private static function sanitizePreset(Sanitizer $sanitizer, array $preset, bool &$contentRejected): array
	{
		foreach (self::SANITIZED_PRESET_KEYS as $key)
		{
			if (isset($preset[$key]))
			{
				$preset[$key] = self::sanitizeValue($sanitizer, $preset[$key], $contentRejected);
			}
		}

		return $preset;
	}

	private static function sanitizeContent(Sanitizer $sanitizer, mixed $content, bool &$contentRejected): string
	{
		if (!is_string($content))
		{
			$content = is_scalar($content) ? (string)$content : '';
		}

		return (string)$sanitizer->sanitizeText($content, $contentRejected);
	}

	/**
	 * A value of a card preset is markup, a list of markup or a structure of them, and its
	 * shape belongs to the application. Only strings are sanitized: the structure is kept as
	 * it came, and a value of another type carries no markup to begin with.
	 */
	private static function sanitizeValue(Sanitizer $sanitizer, mixed $value, bool &$contentRejected): mixed
	{
		if (is_array($value))
		{
			return array_map(
				static function ($item) use ($sanitizer, &$contentRejected) {
					return self::sanitizeValue($sanitizer, $item, $contentRejected);
				},
				$value,
			);
		}

		if (is_string($value))
		{
			return $sanitizer->sanitizeText($value, $contentRejected);
		}

		return $value;
	}
}
