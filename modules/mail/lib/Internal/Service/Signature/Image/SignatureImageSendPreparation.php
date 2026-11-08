<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Image;

use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Internal\Service\Signature\StyleTagSanitizer;
use Bitrix\Main\Result;

final class SignatureImageSendPreparation
{
	private const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';
	private const CANONICAL_MARKER_PATTERN
		= '~^<img src="/bitrix/services/main/ajax\.php\?action=mail\.api\.signatureimage\.view&amp;id='
		. '(?<uuid>' . self::UUID_PATTERN . ')"'
		. ' data-bx-mail-signature-image="\k<uuid>"'
		. ' data-bx-mail-signature-image-size="(?:small|medium|original)"'
		. '(?: width="[1-9][0-9]{0,3}")?>$~';
	private const TRANSPORT_MARKER_PATTERN
		= '~^<img data-bx-mail-signature-image="(?<uuid>' . self::UUID_PATTERN . ')"'
		. ' data-bx-mail-signature-image-size="(?<size>small|medium|original)"'
		. '(?<width> width="[1-9][0-9]{0,3}")?>$~';
	private const IMAGE_TAG_PATTERN = '~<img\b[^>]*>~i';
	private const MARKER_ATTRIBUTE_PATTERN
		= '~\s+data-bx-mail-signature-image(?:-size)?'
		. '(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?~i';
	private const VIEW_PATH = '/bitrix/services/main/ajax.php?action=mail.api.signatureimage.view&amp;id=';

	public function __construct(
		private readonly SignatureImageMimeResolver $resolver = new SignatureImageMimeResolver(),
		private readonly StyleTagSanitizer $styleTagSanitizer = new StyleTagSanitizer(),
	)
	{
	}

	public function sanitizeHtml(string $html): string
	{
		$html = $this->normalizeTrustedMarkers($html);
		$html = $this->removeUntrustedMarkerAttributes($html);

		return $this->styleTagSanitizer->sanitize(Message::sanitizeHtml($html));
	}

	/**
	 * Resolves signature markers and appends their MIME descriptors to prepared attachments.
	 *
	 * @param array<int, array<string, mixed>> $attachments
	 * @return Result Data keys: html, attachments and totalSize.
	 */
	public function prepare(
		string $html,
		int $userId,
		string $hostname,
		array $attachments = [],
		int $totalSize = 0,
	): Result
	{
		$html = $this->normalizeTrustedMarkers($html);
		$html = $this->removeUntrustedMarkerAttributes($html);
		$html = $this->restoreCanonicalMarkerUrls($html);
		$result = $this->resolver->resolve($html, $userId, $hostname, $totalSize);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$data = $result->getData();

		return $result->setData([
			'html' => (string)$data['html'],
			'attachments' => array_merge($attachments, (array)$data['attachments']),
			'totalSize' => (int)$data['totalSize'],
		]);
	}

	private function restoreCanonicalMarkerUrls(string $html): string
	{
		return preg_replace_callback(
			'~<img data-bx-mail-signature-image="(?<uuid>' . self::UUID_PATTERN . ')"'
				. ' data-bx-mail-signature-image-size="(?<size>small|medium|original)"'
				. '(?<width> width="[1-9][0-9]{0,3}")?>~',
			static fn(array $match): string => '<img src="' . self::VIEW_PATH . $match['uuid'] . '"'
				. ' data-bx-mail-signature-image="' . $match['uuid'] . '"'
				. ' data-bx-mail-signature-image-size="' . $match['size'] . '"'
				. ($match['width'] ?? '')
				. '>',
			$html,
		) ?? $html;
	}

	private function normalizeTrustedMarkers(string $html): string
	{
		return preg_replace_callback(
			self::IMAGE_TAG_PATTERN,
			function (array $match): string {
				$marker = $this->parseTrustedMarker($match[0]);
				if ($marker === null)
				{
					return $match[0];
				}

				return '<img src="' . self::VIEW_PATH . $marker['uuid'] . '"'
					. ' data-bx-mail-signature-image="' . $marker['uuid'] . '"'
					. ' data-bx-mail-signature-image-size="' . $marker['size'] . '"'
					. ($marker['width'] === null ? '' : ' width="' . $marker['width'] . '"')
					. '>';
			},
			$html,
		) ?? $html;
	}

	/** @return array{uuid: string, size: string, width: string|null}|null */
	private function parseTrustedMarker(string $tag): ?array
	{
		preg_match_all(
			'/(?:^|\s)([^\s=\/>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/u',
			preg_replace('/^<\s*img\b/i', '', substr($tag, 0, -1), 1) ?? '',
			$matches,
			PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
		);

		$attributes = [];
		foreach ($matches as $match)
		{
			$name = strtolower((string)$match[1]);
			if (!in_array($name, ['src', 'width', 'data-bx-mail-signature-image', 'data-bx-mail-signature-image-size'], true))
			{
				continue;
			}
			if (array_key_exists($name, $attributes))
			{
				return null;
			}

			$attributes[$name] = html_entity_decode(
				(string)($match[2] ?? $match[3] ?? $match[4] ?? ''),
				ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
				'UTF-8',
			);
		}

		$source = $attributes['src'] ?? '';
		$width = $attributes['width'] ?? null;
		if ($width !== null && preg_match('/^[1-9][0-9]{0,3}$/D', $width) !== 1)
		{
			return null;
		}

		$sourcePattern = '~^(?:https?://[^/?#]+)?/bitrix/services/main/ajax\.php'
			. '\?action=mail\.api\.signatureimage\.view&id=(' . self::UUID_PATTERN . ')$~iD';
		if (preg_match($sourcePattern, $source, $sourceMatch) !== 1)
		{
			return null;
		}
		$sourceUuid = strtolower($sourceMatch[1]);
		$uuid = strtolower(trim($attributes['data-bx-mail-signature-image'] ?? ''));
		$size = $attributes['data-bx-mail-signature-image-size'] ?? '';
		$hasUuidAttribute = $uuid !== '';
		$hasSizeAttribute = $size !== '';
		if ($hasUuidAttribute !== $hasSizeAttribute)
		{
			return null;
		}
		if ($hasUuidAttribute)
		{
			if (
				preg_match('/^' . self::UUID_PATTERN . '$/D', $uuid) !== 1
				|| $uuid !== $sourceUuid
				|| !in_array($size, ['small', 'medium', 'original'], true)
			)
			{
				return null;
			}
		}
		else
		{
			$uuid = $sourceUuid;
			$size = match (true)
			{
				$width !== null && (int)$width <= 160 => 'small',
				$width !== null && (int)$width <= 320 => 'medium',
				default => 'original',
			};
		}

		return [
			'uuid' => $uuid,
			'size' => $size,
			'width' => $width,
		];
	}

	private function removeUntrustedMarkerAttributes(string $html): string
	{
		return preg_replace_callback(
			self::IMAGE_TAG_PATTERN,
			static function (array $match): string {
				$tag = $match[0];
				if (
					preg_match(self::CANONICAL_MARKER_PATTERN, $tag) === 1
					|| preg_match(self::TRANSPORT_MARKER_PATTERN, $tag) === 1
				)
				{
					return $tag;
				}

				return preg_replace(self::MARKER_ATTRIBUTE_PATTERN, '', $tag) ?? $tag;
			},
			$html,
		) ?? $html;
	}
}
