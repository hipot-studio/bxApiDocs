<?php

namespace Bitrix\Landing\Subtype;

use Bitrix\Landing\Block;
use Bitrix\Main\Web\Json;

/**
 * Block with untrusted custom code.
 *
 * The source is stored in the block row and is never printed into the portal document as markup:
 * the server prints an escaped JSON payload next to an empty mount point, the client extension
 * hands the source to a sandboxed frame with an opaque origin.
 */
class CustomCode
{
	public const SUBTYPE_CODE = 'customcode';
	public const EXTENSION_NAME = 'landing.customcode';

	/**
	 * Version of the payload format, read by the client extension.
	 */
	public const PAYLOAD_VERSION = 1;

	public const MODE_BLOCK = 'block';
	public const MODE_FULLPAGE = 'fullpage';

	private const WRAPPER_CLASS = 'landing-custom-code';
	private const MOUNT_CLASS = 'landing-custom-code__mount';
	private const BLOCK_ATTRIBUTE = 'data-custom-code-block';
	private const SOURCE_ATTRIBUTE = 'data-custom-code-source';

	/**
	 * Prepare manifest.
	 * @param array $manifest Block's manifest.
	 * @param Block|null $block Block instance.
	 * @param array $params Additional params.
	 * @return array
	 */
	public static function prepareManifest(array $manifest, ?Block $block = null, array $params = []): array
	{
		// there is nothing to edit by nodes: the whole block is the source
		$manifest['nodes'] = [];
		$manifest['cards'] = [];
		$manifest['attrs'] = [];
		$manifest['style'] = [
			'block' => [],
			'nodes' => [],
		];

		// in edit mode a repo block gets no own assets.js, so the mounter comes as an extension
		$manifest['assets'] = [
			'ext' => [self::EXTENSION_NAME],
		];

		$manifest['block']['subtype_params']['mode'] = self::getMode($params);

		return $manifest;
	}

	/**
	 * Checks whether the manifest declares the custom code subtype.
	 * @param array $manifest Block's manifest.
	 * @return bool
	 */
	public static function isCustomCode(array $manifest): bool
	{
		$subtypes = (array)($manifest['block']['subtype'] ?? []);
		$subtypes = array_map('mb_strtolower', array_filter($subtypes, 'is_string'));

		return in_array(self::SUBTYPE_CODE, $subtypes, true);
	}

	/**
	 * Returns the block wrapper: a container, the source as a JSON payload and a mount point.
	 * The frame itself is created by the client, the source never becomes markup of this document.
	 * @param int $blockId Block id.
	 * @param string $source Raw source of the block.
	 * @param array $manifest Block's manifest.
	 * @return string
	 */
	public static function getWrapper(int $blockId, string $source, array $manifest): string
	{
		$payload = Json::encode([
			'source' => $source,
			'mode' => self::getMode($manifest['block']['subtype_params'] ?? []),
			'version' => self::PAYLOAD_VERSION,
		]);

		return '<div class="' . self::WRAPPER_CLASS . '" ' . self::BLOCK_ATTRIBUTE . '="' . $blockId . '">'
			. '<script type="application/json" ' . self::SOURCE_ATTRIBUTE . '>' . $payload . '</script>'
			. '<div class="' . self::MOUNT_CLASS . '"></div>'
			. '</div>';
	}

	private static function getMode(array $params): string
	{
		$mode = $params['mode'] ?? null;

		return $mode === self::MODE_FULLPAGE ? self::MODE_FULLPAGE : self::MODE_BLOCK;
	}
}
