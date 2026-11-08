<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Service\Snapshot;

use Bitrix\Location\Entity\Source;
use Bitrix\Location\Entity\Source\Factory as SourceFactory;
use Bitrix\Location\Entity\Source\OrmConverter;
use Bitrix\Location\Geometry\Type\Point;
use Bitrix\Location\Repository\SourceRepository;
use Bitrix\Location\StaticMap\ISourceStaticMapService;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/**
 * The single chain behind every static map the module stores: the OSM source, the image of the
 * requested point and the file it is written to. The callers differ in where the file goes, how its
 * name and cache key are built and how a refusal is answered; nothing above that is theirs.
 */
class StaticMapSnapshotService
{
	public const IMAGE_WIDTH = 420;
	public const IMAGE_HEIGHT = 169;
	public const IMAGE_ZOOM = 15;
	public const COORD_PRECISION = 4;

	private const MODULE_ID = 'stafftrack';

	/**
	 * Raster types only, and named here rather than taken from the answer of the map service: the file
	 * lands in a public directory under a name the answer chooses, so an image/svg+xml or a text/html
	 * would be served back as a document of that type.
	 */
	private const ALLOWED_EXTENSION_BY_MIME_TYPE = [
		'image/png' => 'png',
		'image/jpeg' => 'jpg',
		'image/jpg' => 'jpg',
		'image/webp' => 'webp',
		'image/gif' => 'gif',
	];

	public static function roundCoordinate(float $coordinate): float
	{
		return round($coordinate, self::COORD_PRECISION);
	}

	/**
	 * @return Result Data: source - the available map source.
	 */
	public function resolveSource(): Result
	{
		$result = new Result();

		if (!$this->isLocationModuleLoaded())
		{
			return $result->addError(new Error('Location module is not available'));
		}

		$source = $this->findSource();
		if ($source === null)
		{
			return $result->addError(new Error('OSM source is not available'));
		}

		return $result->setData(['source' => $source]);
	}

	/**
	 * @return Result Data: snapshot - the accepted image of the point.
	 */
	public function capture(Source $source, float $latitude, float $longitude): Result
	{
		$result = new Result();

		$staticMapService = $this->makeStaticMapService($source);
		if ($staticMapService === null)
		{
			return $result->addError(new Error('Static map service is not supported by the source'));
		}

		$staticMap = $staticMapService->getStaticMap(
			new Point($latitude, $longitude),
			self::IMAGE_ZOOM,
			self::IMAGE_WIDTH,
			self::IMAGE_HEIGHT,
		);

		if (!$staticMap->isSuccess())
		{
			return $result->addErrors($staticMap->getErrors());
		}

		$mimeType = (string)$staticMap->getMimeType();
		$extension = self::resolveExtension($mimeType);
		if ($extension === null)
		{
			return $result->addError(new Error('Static map image type is not allowed'));
		}

		return $result->setData([
			'snapshot' => new StaticMapSnapshot((string)$staticMap->getContent(), $mimeType, $extension),
		]);
	}

	public function save(StaticMapSnapshot $snapshot, string $baseName, string $subDir): ?int
	{
		return $this->saveFile(
			[
				'name' => $baseName . '.' . $snapshot->extension,
				'type' => $snapshot->mimeType,
				'size' => strlen($snapshot->content),
				'content' => $snapshot->content,
				'MODULE_ID' => self::MODULE_ID,
			],
			$subDir,
		);
	}

	public function getFilePath(int $fileId): ?string
	{
		return \CFile::GetPath($fileId) ?: null;
	}

	public function getFileName(int $fileId): ?string
	{
		$file = \CFile::GetFileArray($fileId);

		return is_array($file) ? (string)($file['ORIGINAL_NAME'] ?? '') : null;
	}

	public function deleteFile(int $fileId): void
	{
		\CFile::Delete($fileId);
	}

	protected function isLocationModuleLoaded(): bool
	{
		return Loader::includeModule('location');
	}

	protected function findSource(): ?Source
	{
		// TODO: support multiple source providers, not only OSM
		static $source;

		if ($source !== null)
		{
			return $source ?: null;
		}

		$source = (new SourceRepository(new OrmConverter()))
			->findByCode(SourceFactory::OSM_SOURCE_CODE)
		;

		if ($source === null || !$source->isAvailable())
		{
			$source = false;

			return null;
		}

		return $source;
	}

	protected function makeStaticMapService(Source $source): ?ISourceStaticMapService
	{
		return $source->makeStaticMapService();
	}

	protected function saveFile(array $fileData, string $subDir): ?int
	{
		$fileId = (int)\CFile::SaveFile($fileData, $subDir);

		return $fileId > 0 ? $fileId : null;
	}

	private static function resolveExtension(string $mimeType): ?string
	{
		$normalized = strtolower(trim(explode(';', $mimeType, 2)[0]));

		return self::ALLOWED_EXTENSION_BY_MIME_TYPE[$normalized] ?? null;
	}
}
