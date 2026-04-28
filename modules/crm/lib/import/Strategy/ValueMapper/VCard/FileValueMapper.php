<?php

namespace Bitrix\Crm\Import\Strategy\ValueMapper\VCard;

use Bitrix\Crm\VCard\VCardLine;
use Bitrix\Main\Security\Random;
use CCrmUrlUtil;
use CFile;
use CTempFile;

final class FileValueMapper
{
	/**
	 * @return ?array File
	 */
	public function process(VCardLine $vcardLine): ?array
	{
		return
			$this->processPhotoLink($vcardLine)
			?? $this->processDataUri($vcardLine)
			?? $this->processVCard3Standard($vcardLine);
	}

	private function processPhotoLink(VCardLine $vcardLine): ?array
	{
		if (!$this->isValidPhotoUrl($vcardLine->getValue()))
		{
			return null;
		}

		return $this->savePhotoFromUrl($vcardLine->getValue());
	}

	private function isValidPhotoUrl(string $url): bool
	{
		return parse_url($url) !== false && CCrmUrlUtil::IsSecureUrl($url);
	}

	private function processDataUri(VCardLine $vcardLine): ?array
	{
		if (!$this->isDataUri($vcardLine->getValue()))
		{
			return null;
		}

		$pattern = '/^data:image\/(?P<format>[\w]+)(?P<base64>;base64)?,(?P<content>.*)$/i';
		preg_match($pattern, $vcardLine->getValue(), $matches);

		$imageContent = $matches['content'] ?? null;
		if (empty($imageContent))
		{
			return null;
		}

		$isBase64Encoded = $matches['base64'] === ';base64';
		$imageFormat = $matches['format'];

		if (!$this->isValidImageFormat($imageFormat))
		{
			return null;
		}

		if ($isBase64Encoded)
		{
			$imageContent = base64_decode($imageContent);
			if ($imageContent === false)
			{
				return null;
			}
		}

		return $this->savePhotoFromBinaryData($imageContent, $imageFormat);
	}

	private function isDataUri(string $value): bool
	{
		return str_starts_with($value, 'data:');
	}

	private function processVCard3Standard(VCardLine $vcardLine): ?array
	{
		$encodingType = mb_strtoupper($vcardLine->getParameter('ENCODING'));
		$imageType = mb_strtoupper($vcardLine->getParameter('TYPE'));

		if ($encodingType === 'BASE64' || $encodingType === 'B')
		{
			$imageFormat = is_string($imageType) ? mb_strtolower($imageType) : 'jpg';
			if (!$this->isValidImageFormat($imageFormat))
			{
				$imageFormat = 'jpg';
			}

			$binaryImageData = base64_decode($vcardLine->getValue());
			if ($binaryImageData === false)
			{
				return null;
			}

			return $this->savePhotoFromBinaryData($binaryImageData, $imageFormat);
		}

		if (
			$imageType === 'URI'
			&& CCrmUrlUtil::HasScheme($vcardLine->getValue())
			&& CCrmUrlUtil::IsSecureUrl($vcardLine->getValue())
		)
		{
			return $this->savePhotoFromUrl($vcardLine->getValue());
		}

		return null;
	}

	private function isValidImageFormat(string $format): bool
	{
		$allowedExtensions = explode(',', CFile::GetImageExtensions());

		return in_array($format, $allowedExtensions, true);
	}

	private function savePhotoFromUrl(string $photoUrl): ?array
	{
		$fileData = CFile::MakeFileArray($photoUrl);
		if (!is_array($fileData) || !empty(CFile::CheckImageFile($fileData)))
		{
			return null;
		}

		return [
			...$fileData,
			'MODULE_ID' => 'crm',
		];
	}

	private function savePhotoFromBinaryData(string $binaryData, string $format): ?array
	{
		$fileId = Random::getString(23);
		$filename = "vcard_photo_{$fileId}.{$format}";
		$mimeType = "image/{$format}";

		$tempFilePath = CTempFile::GetFileName($filename);
		CheckDirPath($tempFilePath);

		$isFileWritten = file_put_contents($tempFilePath, $binaryData) !== false;
		if (!$isFileWritten)
		{
			return null;
		}

		$fileData = CFile::MakeFileArray($tempFilePath, $mimeType);
		if (!is_array($fileData) || !empty(CFile::CheckImageFile($fileData)))
		{
			return null;
		}

		return [
			...$fileData,
			'MODULE_ID' => 'crm',
		];
	}
}
