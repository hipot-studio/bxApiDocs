<?php

namespace Bitrix\Mobile\Provider;

use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\IO\File;
use Bitrix\Main\IO\Path;
use Bitrix\UI\Barcode\Barcode;
use Bitrix\UI\Barcode\BarcodeDictionary;

final class QrGenerator
{
	public const ERROR_CORRECTION_LEVEL_LOW = BarcodeDictionary::TYPE_QR;
	public const ERROR_CORRECTION_LEVEL_MEDIUM = BarcodeDictionary::TYPE_QR_M;
	public const ERROR_CORRECTION_LEVEL_QUARTILE = BarcodeDictionary::TYPE_QR_Q;
	public const ERROR_CORRECTION_LEVEL_HIGH = BarcodeDictionary::TYPE_QR_H;

	private const DEFAULT_QUIET_ZONE_MULTIPLIER = 0;
	private const DEFAULT_SIZE = 300;
	private const DEFAULT_PADDING = 18;

	private string $url;
	private bool $darkMode;
	private int $quietZoneMultiplier;
	private string $errorCorrectionLevel;
	private ?int $size;
	private int $padding;
	private ?string $qrContent = null;
	private ?string $filePath = null;

	public function __construct(
		string $url,
		bool $darkMode = false,
		int $quietZoneMultiplier = self::DEFAULT_QUIET_ZONE_MULTIPLIER,
		string $errorCorrectionLevel = self::ERROR_CORRECTION_LEVEL_LOW,
		?int $size = self::DEFAULT_SIZE,
		int $padding = self::DEFAULT_PADDING,
	)
	{
		if (!in_array($errorCorrectionLevel, self::getErrorCorrectionLevels(), true))
		{
			throw new ArgumentOutOfRangeException('errorCorrectionLevel', self::getErrorCorrectionLevels());
		}

		$this->url = $url;
		$this->darkMode = $darkMode;
		$this->quietZoneMultiplier = $quietZoneMultiplier;
		$this->errorCorrectionLevel = $errorCorrectionLevel;
		$this->size = $size;
		$this->padding = $padding;
	}

	/**
	 * @return string[]
	 */
	private static function getErrorCorrectionLevels(): array
	{
		return [
			self::ERROR_CORRECTION_LEVEL_LOW,
			self::ERROR_CORRECTION_LEVEL_MEDIUM,
			self::ERROR_CORRECTION_LEVEL_QUARTILE,
			self::ERROR_CORRECTION_LEVEL_HIGH,
		];
	}

	/**
	 * @return string
	 */
	public function getContent(): string
	{
		if ($this->qrContent === null)
		{
			$this->generateQr();
		}

		return $this->qrContent;
	}

	/**
	 * @return string|null
	 */
	public function getUrl(): ?string
	{
		if ($this->filePath === null)
		{
			$this->saveQrToTemporaryFile();
		}

		return $this->filePath ? $this->getRelativePath($this->filePath) : null;
	}

	/**
	 * @return void
	 */
	private function generateQr(): void
	{
		$bgColor = $this->darkMode ? '#171717' : '#FFFFFF';
		$qrColor = $this->darkMode ? '#FFFFFF' : '#171717';

		$barcode = (new Barcode())
			->format('svg')
			->type($this->errorCorrectionLevel);

		if ($this->size !== null)
		{
			$barcode
				->option('w', $this->size)
				->option('h', $this->size)
			;
		}

		$this->qrContent = $barcode
			->option('p', $this->padding)
			->option('wq', $this->quietZoneMultiplier)
			->option('bc', $bgColor)
			->option('cm', $qrColor)
			->render($this->url);
	}

	/**
	 * @return void
	 */
	private function saveQrToTemporaryFile(): void
	{
		$qrContent = $this->getContent();

		$hash = md5($qrContent);
		$tmpDirName = \CTempFile::GetDirectoryName(1, 'qr_tmp');
		$tmpFileName = Path::combine($tmpDirName, $hash . '.svg');

		if (!Path::validate($tmpFileName))
		{
			return;
		}

		$tmpFile = new File($tmpFileName);

		if (!$tmpFile->isFileExists($tmpFile->getPath()))
		{
			$writeResult = $tmpFile->putContents($qrContent);
			if ($writeResult === false)
			{
				return;
			}
		}

		$this->filePath = $tmpFileName;
	}

	/**
	 * @param string $absoluteFilePath
	 * @return string
	 */
	private function getRelativePath(string $absoluteFilePath): string
	{
		$absoluteRoot = \CTempFile::GetAbsoluteRoot();
		$relativePath = str_replace($absoluteRoot . '/', '', $absoluteFilePath);

		return '/upload/tmp/' . $relativePath;
	}
}