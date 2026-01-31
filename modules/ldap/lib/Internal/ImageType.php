<?php

namespace Bitrix\Ldap\Internal;

/**
 * @package Bitrix\Ldap\Internal
 * You must not use classes from Internal namespace outside current module.
 */
enum ImageType: string
{
	case Gif = 'gif';
	case Jpg = 'jpg';
	case Png = 'png';
	case Swf = 'swf';
	case Swc = 'swc';
	case Psd = 'psd';
	case Bmp = 'bmp';
	case Jpc = 'jpc';
	case Tif = 'tif';
	case Iff = 'iff';
	case Ico = 'ico';
	case Jp2 = 'jp2';
	case Unknown = 'unknown';

	public static function fromFileContent(string $fileContent): ImageType
	{
		if($fileContent === '')
			return ImageType::Unknown;

		$fileContent = mb_strcut($fileContent, 0, 12);

		/** @var array<string, ImageType> $allowedSignatures */
		$allowedSignatures = [
			"GIF" => ImageType::Gif,
			"\xff\xd8\xff" => ImageType::Jpg,
			"\x89\x50\x4e" => ImageType::Png,
			"FWS" => ImageType::Swf,
			"CWS" => ImageType::Swc,
			"8BPS" => ImageType::Psd,
			"BM" => ImageType::Bmp,
			"\xff\x4f\xff" => ImageType::Jpc,
			"II\x2a\x00" => ImageType::Tif,
			"MM\x00\x2a" => ImageType::Tif,
			"FORM" => ImageType::Iff,
			"\x00\x00\x01\x00" => ImageType::Ico,
			"\x0d\x0a\x87\x0a" => ImageType::Jp2,
		];

		foreach ($allowedSignatures as $signature => $type)
		{
			if (preg_match("/^" . $signature . "/x", $fileContent))
			{
				return $type;
			}
		}

		return ImageType::Unknown;
	}
}