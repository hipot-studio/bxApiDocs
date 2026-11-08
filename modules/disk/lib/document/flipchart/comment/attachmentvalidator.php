<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Comment;

use Bitrix\Main\Error;
use Bitrix\Main\File\Image;
use Bitrix\Main\Result;

/**
 * What may become a public file: the extension against the white list of the contract and the bytes
 * against the format the extension claims.
 *
 * A raster image is confirmed by the reader of the kernel; `heic` and `heif` are confirmed by the
 * ISO-BMFF signature, which that reader does not parse. An SVG is not confirmed but rebuilt: the
 * document is parsed without the network, everything outside the closed lists below is dropped, and
 * what is stored is the result of the rebuild, never the bytes that came in. A document that cannot
 * be rebuilt safely is refused as an unsupported format, and so is every other refusal here: the
 * answer never tells which check the file failed.
 */
final class AttachmentValidator
{
	private const ERROR_UNSUPPORTED = 'unavailable_file_ext';

	private const SVG_EXTENSION = 'svg';
	private const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';
	private const XMLNS_NAMESPACE = 'http://www.w3.org/2000/xmlns/';

	private const ALLOWED_EXTENSIONS = ['png', 'bmp', 'webp', 'jpg', 'jpeg', 'gif', 'heic', 'heif', 'svg'];

	private const RASTER_FORMATS = [
		'png' => Image::FORMAT_PNG,
		'bmp' => Image::FORMAT_BMP,
		'webp' => Image::FORMAT_WEBP,
		'jpg' => Image::FORMAT_JPEG,
		'jpeg' => Image::FORMAT_JPEG,
		'gif' => Image::FORMAT_GIF,
	];

	private const HEIF_EXTENSIONS = ['heic', 'heif'];
	private const HEIF_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1'];

	private const ALLOWED_ELEMENTS = [
		'svg',
		'g',
		'defs',
		'symbol',
		'use',
		'title',
		'desc',
		'path',
		'rect',
		'circle',
		'ellipse',
		'line',
		'polyline',
		'polygon',
		'text',
		'tspan',
		'lineargradient',
		'radialgradient',
		'stop',
		'clippath',
		'mask',
		'pattern',
	];

	private const ALLOWED_ATTRIBUTES = [
		'id',
		'class',
		'style',
		'transform',
		'd',
		'x',
		'y',
		'dx',
		'dy',
		'x1',
		'y1',
		'x2',
		'y2',
		'cx',
		'cy',
		'r',
		'rx',
		'ry',
		'fx',
		'fy',
		'fr',
		'width',
		'height',
		'points',
		'offset',
		'viewbox',
		'version',
		'preserveaspectratio',
		'fill',
		'fill-opacity',
		'fill-rule',
		'stroke',
		'stroke-width',
		'stroke-linecap',
		'stroke-linejoin',
		'stroke-dasharray',
		'stroke-dashoffset',
		'stroke-opacity',
		'stroke-miterlimit',
		'opacity',
		'stop-color',
		'stop-opacity',
		'clip-path',
		'clip-rule',
		'clippathunits',
		'mask',
		'maskunits',
		'maskcontentunits',
		'gradientunits',
		'gradienttransform',
		'patternunits',
		'patterncontentunits',
		'patterntransform',
		'spreadmethod',
		'font-family',
		'font-size',
		'font-style',
		'font-weight',
		'text-anchor',
		'dominant-baseline',
		'letter-spacing',
		'display',
		'visibility',
		'overflow',
		'vector-effect',
		'href',
		'xlink:href',
	];

	private const DANGEROUS_STYLE_FRAGMENTS = ['url(', '@import', 'expression'];

	/**
	 * @return Result On success `getData()['extension']` is the confirmed extension and
	 *     `getData()['content']` holds the bytes to store instead of the uploaded ones, or `null`
	 *     when the uploaded file is stored as it is.
	 */
	public function validate(string $tmpName, string $originalName): Result
	{
		$extension = mb_strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
		if (!in_array($extension, self::ALLOWED_EXTENSIONS, true))
		{
			return self::unsupported();
		}

		if ($extension === self::SVG_EXTENSION)
		{
			$sanitized = $this->sanitizeSvg($tmpName);

			return $sanitized === null ? self::unsupported() : self::accept($extension, $sanitized);
		}

		if (in_array($extension, self::HEIF_EXTENSIONS, true))
		{
			return self::isHeif($tmpName) ? self::accept($extension) : self::unsupported();
		}

		return self::isRaster($tmpName, $extension) ? self::accept($extension) : self::unsupported();
	}

	private static function isRaster(string $tmpName, string $extension): bool
	{
		try
		{
			$info = (new Image($tmpName))->getInfo();
		}
		catch (\Throwable)
		{
			return false;
		}

		return $info !== null && $info->getFormat() === self::RASTER_FORMATS[$extension];
	}

	/**
	 * ISO-BMFF puts the box length first and the type right after it; the brand that follows tells a
	 * still image of the HEIF family from the other containers built on the same box.
	 */
	private static function isHeif(string $tmpName): bool
	{
		$header = @file_get_contents($tmpName, false, null, 0, 12);
		if (!is_string($header) || strlen($header) < 12)
		{
			return false;
		}

		return substr($header, 4, 4) === 'ftyp'
			&& in_array(strtolower(substr($header, 8, 4)), self::HEIF_BRANDS, true)
		;
	}

	/**
	 * @return string|null The rebuilt document, or `null` when it cannot be rebuilt.
	 */
	private function sanitizeSvg(string $tmpName): ?string
	{
		$bytes = @file_get_contents($tmpName);
		if (!is_string($bytes) || $bytes === '' || self::declaresDoctypeOrEntity($bytes))
		{
			return null;
		}

		$document = new \DOMDocument();
		$previousErrors = libxml_use_internal_errors(true);
		try
		{
			$loaded = $document->loadXML($bytes, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		}
		catch (\Throwable)
		{
			$loaded = false;
		}
		finally
		{
			libxml_clear_errors();
			libxml_use_internal_errors($previousErrors);
		}

		$root = $document->documentElement;
		if (
			!$loaded
			|| $document->doctype !== null
			|| !$root instanceof \DOMElement
			|| strtolower($root->localName) !== 'svg'
		)
		{
			return null;
		}

		self::keepRootAlone($document, $root);
		self::sanitizeElement($root);

		$rebuilt = $document->saveXML();

		return is_string($rebuilt) && $rebuilt !== '' ? $rebuilt : null;
	}

	/**
	 * A fast refusal for the usual single-byte encodings; the parsed document is what decides for the
	 * rest, so an encoding this scan cannot read changes nothing.
	 */
	private static function declaresDoctypeOrEntity(string $bytes): bool
	{
		return stripos($bytes, '<!DOCTYPE') !== false || stripos($bytes, '<!ENTITY') !== false;
	}

	/**
	 * The rebuild owns the whole document, not just the drawing: a processing instruction or a comment
	 * sitting beside the root element is not carried into what gets stored.
	 */
	private static function keepRootAlone(\DOMDocument $document, \DOMElement $root): void
	{
		foreach (iterator_to_array($document->childNodes) as $node)
		{
			if ($node !== $root)
			{
				$document->removeChild($node);
			}
		}
	}

	/**
	 * Everything outside the lists goes away with its children: a dropped element is not unwrapped,
	 * so nothing it carried can survive it. Text stays, comments and processing instructions do not.
	 */
	private static function sanitizeElement(\DOMElement $element): void
	{
		foreach (iterator_to_array($element->childNodes) as $child)
		{
			if ($child instanceof \DOMElement)
			{
				if (self::isAllowedElement($child))
				{
					self::sanitizeElement($child);
				}
				else
				{
					$element->removeChild($child);
				}

				continue;
			}

			if (!$child instanceof \DOMText && !$child instanceof \DOMCdataSection)
			{
				$element->removeChild($child);
			}
		}

		self::sanitizeAttributes($element);
	}

	private static function isAllowedElement(\DOMElement $element): bool
	{
		$namespace = $element->namespaceURI;
		if ($namespace !== null && $namespace !== self::SVG_NAMESPACE)
		{
			return false;
		}

		return in_array(strtolower($element->localName), self::ALLOWED_ELEMENTS, true);
	}

	private static function sanitizeAttributes(\DOMElement $element): void
	{
		foreach (iterator_to_array($element->attributes ?? []) as $attribute)
		{
			if (!$attribute instanceof \DOMAttr || self::isNamespaceDeclaration($attribute))
			{
				continue;
			}

			$name = strtolower($attribute->nodeName);
			$value = $attribute->value;
			$drop = str_starts_with($name, 'on')
				|| !in_array($name, self::ALLOWED_ATTRIBUTES, true)
				|| (self::isReference($name) && !str_starts_with(trim($value), '#'))
				|| ($name === 'style' && self::isDangerousStyle($value))
			;

			if ($drop)
			{
				$element->removeAttributeNode($attribute);
			}
		}
	}

	private static function isNamespaceDeclaration(\DOMAttr $attribute): bool
	{
		return $attribute->namespaceURI === self::XMLNS_NAMESPACE
			|| $attribute->nodeName === 'xmlns'
			|| str_starts_with($attribute->nodeName, 'xmlns:')
		;
	}

	private static function isReference(string $name): bool
	{
		return $name === 'href' || $name === 'xlink:href';
	}

	private static function isDangerousStyle(string $value): bool
	{
		foreach (self::DANGEROUS_STYLE_FRAGMENTS as $fragment)
		{
			if (stripos($value, $fragment) !== false)
			{
				return true;
			}
		}

		return false;
	}

	private static function accept(string $extension, ?string $content = null): Result
	{
		$result = new Result();
		$result->setData([
			'extension' => $extension,
			'content' => $content,
		]);

		return $result;
	}

	private static function unsupported(): Result
	{
		$result = new Result();
		$result->addError(
			new Error('File format is not supported.', self::ERROR_UNSUPPORTED, ['status' => 415]),
		);

		return $result;
	}
}
