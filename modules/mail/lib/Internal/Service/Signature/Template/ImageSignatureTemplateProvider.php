<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Template;

use Bitrix\Mail\Internal\Service\Signature\Image\SignatureImageValidator;
use Bitrix\Mail\Internals\SignatureImageBindingTable;
use Bitrix\Mail\Internals\SignatureImageTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

final class ImageSignatureTemplateProvider implements SignatureTemplateProvider
{
	private const TYPE = 'image';
	private const VIEW_PATH = '/bitrix/services/main/ajax.php?action=mail.api.signatureimage.view&amp;id=';
	private const IMAGE_ATTRIBUTE = 'data-bx-mail-signature-image';
	private const SIZE_ATTRIBUTE = 'data-bx-mail-signature-image-size';
	private const SIZE_WIDTHS = [
		'small' => 160,
		'medium' => 320,
		'original' => 600,
	];
	private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

	private \Closure $loadImage;

	/** @var array<string, array<string, mixed>|null> Images already looked up in this request. */
	private array $loadedImages = [];

	public function __construct(?\Closure $loadImage = null)
	{
		$this->loadImage = $loadImage ?? static function (
			string $uuid,
			int $userId,
			?int $signatureId,
		): ?array {
			$image = SignatureImageTable::getRow([
				'select' => ['ID', 'OWNER_ID', 'WIDTH'],
				'filter' => ['=UUID' => $uuid],
			]);
			if ($image === null)
			{
				return null;
			}

			if ((int)$image['OWNER_ID'] === $userId)
			{
				return $image;
			}

			if ($signatureId === null || $signatureId <= 0)
			{
				return null;
			}

			$binding = SignatureImageBindingTable::getRow([
				'select' => ['IMAGE_ID'],
				'filter' => [
					'=SIGNATURE_ID' => $signatureId,
					'=IMAGE_ID' => (int)$image['ID'],
				],
			]);

			return $binding === null ? null : $image;
		};
	}

	public function getType(): string
	{
		return self::TYPE;
	}

	/**
	 * One signature is resolved once per sender, and a marker of the same image repeats across those
	 * passes, so without the memo every sender costs its own query for the same row. A miss is
	 * remembered too: an inaccessible marker is exactly the case that repeats on every pass.
	 */
	private function loadImageOnce(string $uuid, int $userId, ?int $signatureId): ?array
	{
		$key = $uuid . '|' . $userId . '|' . ($signatureId ?? 0);
		if (!array_key_exists($key, $this->loadedImages))
		{
			$this->loadedImages[$key] = ($this->loadImage)($uuid, $userId, $signatureId);
		}

		return $this->loadedImages[$key];
	}

	public function canonicalize(
		string $template,
		?SignatureTemplateCanonicalizationContext $context = null,
	): Result
	{
		// Only a marker costs a query, so the limit of the signature is checked on markers and before them:
		// otherwise a template of a thousand markers walks to the database a thousand times just to be
		// refused by the same limit a step later.
		if (count($this->collectMarkerUuids($template)) > SignatureImageValidator::MAX_SIGNATURE_FILE_COUNT)
		{
			return (new Result())->addError(new Error(
				SignatureImageValidator::ERROR_COUNT_LIMIT_EXCEEDED,
				SignatureImageValidator::ERROR_COUNT_LIMIT_EXCEEDED,
			));
		}

		$html = $this->replaceImages(
			$template,
			function (string $tag) use ($context): string {
				$marker = $this->parseMarker($tag);
				if ($marker === null)
				{
					// A picture that claims nothing about signature images is none of our business: it may
					// well be the logo the signature carried long before this feature, and saving the
					// signature must not wipe it. Unsafe sources and handlers are already gone: the
					// sanitizer runs before canonicalization.
					return $this->containsMarkerAttribute($tag) ? '' : $tag;
				}
				if ($context === null || $context->userId <= 0)
				{
					return '';
				}

				$image = $this->loadImageOnce(
					$marker['uuid'],
					$context->userId,
					$context->signatureId,
				);
				if (!$this->isValidImage($image))
				{
					return '';
				}

				return $this->renderMarker($marker['uuid'], $marker['size']);
			},
		);

		return (new Result())->setData(['template' => $html]);
	}

	/**
	 * @return string[] Unique UUIDs of the tags that parse as signature image markers.
	 */
	private function collectMarkerUuids(string $template): array
	{
		$uuids = [];
		$this->replaceImages($template, function (string $tag) use (&$uuids): string {
			$marker = $this->parseMarker($tag);
			if ($marker !== null)
			{
				$uuids[$marker['uuid']] = true;
			}

			return $tag;
		});

		return array_keys($uuids);
	}

	public function resolve(string $template, SignatureTemplateContext $context): Result
	{
		$html = $this->replaceImages(
			$template,
			function (string $tag) use ($context): string {
				$marker = $this->parseMarker($tag);
				if ($marker === null)
				{
					return $this->containsMarkerAttribute($tag) ? '' : $tag;
				}
				if ($context->userId <= 0)
				{
					return '';
				}

				$image = $context->imageResources === null
					? $this->loadImageOnce($marker['uuid'], $context->userId, null)
					: ($context->imageResources[$marker['uuid']] ?? null);
				if (!$this->isValidImage($image))
				{
					return '';
				}

				$width = min((int)$image['WIDTH'], self::SIZE_WIDTHS[$marker['size']]);

				return $this->renderMarker($marker['uuid'], $marker['size'], $width);
			},
		);

		return (new Result())->setData(['html' => $html]);
	}

	private function containsMarkerAttribute(string $tag): bool
	{
		return preg_match(
			'/\s(?:' . self::IMAGE_ATTRIBUTE . '|' . self::SIZE_ATTRIBUTE . ')\s*(?:=|\s|\/?>)/i',
			$tag,
		) === 1;
	}

	private function replaceImages(string $html, callable $replace): string
	{
		$result = '';
		$offset = 0;
		$length = strlen($html);

		while (preg_match('/<\s*img\b/i', $html, $match, PREG_OFFSET_CAPTURE, $offset) === 1)
		{
			$start = (int)$match[0][1];
			$result .= substr($html, $offset, $start - $offset);
			$end = $this->findTagEnd($html, $start);
			if ($end === null)
			{
				return $result;
			}

			$result .= $replace(substr($html, $start, $end - $start + 1));
			$offset = $end + 1;
		}

		return $result . substr($html, $offset, $length - $offset);
	}

	private function findTagEnd(string $html, int $start): ?int
	{
		$quote = null;
		$length = strlen($html);
		for ($position = $start; $position < $length; $position++)
		{
			$character = $html[$position];
			if ($quote !== null)
			{
				if ($character === $quote)
				{
					$quote = null;
				}

				continue;
			}

			if ($character === '"' || $character === "'")
			{
				$quote = $character;

				continue;
			}

			if ($character === '>')
			{
				return $position;
			}
		}

		return null;
	}

	/** @return array{uuid: string, size: string}|null */
	private function parseMarker(string $tag): ?array
	{
		$attributes = [];
		$body = preg_replace('/^<\s*img\b/i', '', substr($tag, 0, -1), 1);
		if (!is_string($body))
		{
			return null;
		}

		preg_match_all(
			'/(?:^|\s)([^\s=\/>]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/u',
			$body,
			$matches,
			PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL,
		);
		foreach ($matches as $match)
		{
			$name = strtolower((string)$match[1]);
			if ($name !== self::IMAGE_ATTRIBUTE && $name !== self::SIZE_ATTRIBUTE)
			{
				continue;
			}
			if (array_key_exists($name, $attributes))
			{
				return null;
			}

			$value = $match[2] ?? $match[3] ?? $match[4] ?? '';
			$attributes[$name] = html_entity_decode(
				(string)$value,
				ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
				'UTF-8',
			);
		}

		$uuid = strtolower(trim($attributes[self::IMAGE_ATTRIBUTE] ?? ''));
		$size = $attributes[self::SIZE_ATTRIBUTE] ?? '';
		if (
			preg_match(self::UUID_PATTERN, $uuid) !== 1
			|| !isset(self::SIZE_WIDTHS[$size])
		)
		{
			return null;
		}

		return [
			'uuid' => $uuid,
			'size' => $size,
		];
	}

	private function isValidImage(mixed $image): bool
	{
		return is_array($image) && (int)($image['WIDTH'] ?? 0) > 0;
	}

	private function renderMarker(string $uuid, string $size, ?int $width = null): string
	{
		$marker = '<img src="' . self::VIEW_PATH . $uuid . '"'
			. ' ' . self::IMAGE_ATTRIBUTE . '="' . $uuid . '"'
			. ' ' . self::SIZE_ATTRIBUTE . '="' . $size . '"';
		if ($width !== null)
		{
			$marker .= ' width="' . $width . '"';
		}

		return $marker . '>';
	}
}
