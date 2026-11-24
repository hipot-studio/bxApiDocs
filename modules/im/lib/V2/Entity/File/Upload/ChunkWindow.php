<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\File\Upload;

use Bitrix\Disk\Driver;
use Bitrix\Im\V2\Service\FileService;
use Bitrix\Main\Config\Ini;
use Bitrix\Main\Loader;

/**
 * The range a chunk of the door is allowed to have, decided before a single byte travels.
 *
 * The upper bound is what the portal can really take in one chunk - the body it accepts at all, narrowed
 * to what one request can hold in memory; the lower one exists only behind a cloud
 * bucket, where every chunk but the last has to be at least getMinUploadPartSize()
 * ({@see \Bitrix\UI\FileUploader\TempFile::uploadPartToCloud()}). The two can cross - a portal with a
 * small upload_max_filesize and an S3 bucket leaves no legal chunk size at all - and the door answers
 * that before it opens a session, instead of refusing the first chunk that arrives.
 *
 * 🔴 THE TWO BOUNDS COST DIFFERENT MONEY. The upper one is three ini_get. The lower one includes the
 * modules clouds and disk, walks every bucket of the portal with the compiled rules of each and
 * initializes the one it finds - the heaviest thing in an append after the bytes themselves, and a file of
 * 100 MB is fifty appends. So the lower bound is resolved lazily, at the first question that is really
 * decided by it, and never on the way to one that is not: a portal with no upper bound at all is empty
 * before the buckets are ever walked, and the factory of a chunk judges the size of a part against the
 * upper bound alone ({@see MultipartChunkFactory::fromRequest()}).
 *
 * Asked it is, though, once per chunk but the last, where the whole upload hangs on the answer
 * ({@see ChunkedUploadService::findShortChunkRefusal()}). A bucket that refuses a short part takes the
 * accumulated bytes down with it, and a bound nobody asks for bounds nothing. On a portal without a cloud
 * that question costs the missing module and stops there.
 */
final class ChunkWindow
{
	/**
	 * Room kept for everything a multipart body carries beyond the chunk itself: boundaries, the part
	 * headers and the fields of the call. The chunk has to fit under the php ceiling together with them.
	 */
	private const ENVELOPE_RESERVE = 8192;

	/**
	 * The biggest chunk the door ever recommends, however much the portal would accept.
	 *
	 * 🔴 THE ENGINE HOLDS A WHOLE CHUNK IN A STRING: file_get_contents of the part, then one more copy for
	 * a cloud bucket ({@see \Bitrix\UI\FileUploader\TempFile::appendToFile()} and ::appendToCloud()). A
	 * portal with post_max_size of 512M would otherwise be told to send 512M parts, and the first append
	 * of a client that obeyed would exhaust memory_limit - in the cloud at twice the size of the chunk.
	 */
	private const RECOMMENDED_CEILING = 16 * 1024 * 1024;

	/**
	 * The share of memory_limit a recommended chunk is allowed to take. One part of eight leaves room for
	 * the second copy the cloud makes and for everything else the request holds.
	 */
	private const MEMORY_LIMIT_SHARE = 8;

	/**
	 * 🔴 The share of memory_limit the biggest chunk the door ACCEPTS is allowed to take, and not only the
	 * one it recommends. A ceiling that bounds the advice alone bounds nothing at all: the number a caller
	 * is really judged by was the body php lets in, so on a portal that accepts a request of 100 MB a
	 * caller could send almost the whole file as its second chunk - and the engine, which holds a chunk
	 * whole in a string and makes a second copy of it for a cloud bucket, would take the process of php
	 * down with it. A quarter leaves half of the limit for the two copies and half for everything else of
	 * the request; the recommendation stays at half of that, so the number named to a caller is well
	 * inside the number it is judged by.
	 */
	private const MEMORY_LIMIT_ACCEPTED_SHARE = 4;

	private ?int $min;

	/** @var \Closure(): int|null The lower bound while it has not been asked for. */
	private ?\Closure $findMin = null;

	public function __construct(
		int $min,
		private readonly int $max,
	)
	{
		$this->min = $min;
	}

	public static function forFile(string $fileName, int $fileSize): self
	{
		$window = new self(0, self::findAcceptedSize());
		$window->min = null;
		$window->findMin = static fn (): int => self::findCloudPartSize($fileName, $fileSize);

		return $window;
	}

	public function getMin(): int
	{
		if ($this->min === null)
		{
			$this->min = ($this->findMin)();
			$this->findMin = null;
		}

		return $this->min;
	}

	public function getMax(): int
	{
		return $this->max;
	}

	/**
	 * A window with no upper bound at all is empty before the lower one is even asked: php accepts no body
	 * of this door, and every chunk that could arrive would be too big. Reading the upper bound first also
	 * keeps a degenerate portal from paying for a walk over the buckets.
	 */
	public function isEmpty(): bool
	{
		return $this->max <= 0 || $this->getMin() > $this->max;
	}

	/**
	 * The chunk size the door names to the caller: inside the window, and never above what one request can
	 * hold in memory. A lower bound above that ceiling wins over it - a cloud bucket refuses anything
	 * smaller - but never over the upper bound, which is the one a chunk is really judged by.
	 */
	public function getRecommended(): int
	{
		$underTheCeiling = min($this->max, self::findRecommendedCeiling());

		return min($this->max, max($this->getMin(), $underTheCeiling));
	}

	/**
	 * @return array{chunkSize: int, minChunkSize: int, maxChunkSize: int}
	 */
	public function toRest(): array
	{
		return [
			'chunkSize' => $this->getRecommended(),
			'minChunkSize' => $this->getMin(),
			'maxChunkSize' => $this->max,
		];
	}

	/**
	 * The lower bound of the bucket the file would land in, or 0 when there is no cloud behind it:
	 * a local portal has no lower bound at all.
	 */
	private static function findCloudPartSize(string $fileName, int $fileSize): int
	{
		if (!Loader::includeModule('clouds') || !Loader::includeModule('disk'))
		{
			return 0;
		}

		// ORIGINAL_NAME is read by the extension rules of a bucket next to the name itself.
		$bucket = \CCloudStorage::FindBucketForFile(
			[
				'FILE_SIZE' => $fileSize,
				'MODULE_ID' => Driver::INTERNAL_MODULE_ID,
				'ORIGINAL_NAME' => $fileName,
			],
			$fileName,
		);

		if ($bucket === null || !$bucket->init())
		{
			return 0;
		}

		return max(0, (int)$bucket->getService()->getMinUploadPartSize());
	}

	/**
	 * The biggest chunk the door takes: everything the two ceilings of the machine allow, and nothing
	 * above either of them.
	 */
	private static function findAcceptedSize(): int
	{
		return self::narrowToAcceptedSize(self::findPhpUploadSize(), Ini::getInt('memory_limit'));
	}

	/**
	 * 🔴 A CHUNK IS JUDGED BY MEMORY AS WELL AS BY THE REQUEST, and this is the only place that holds it.
	 * The engine reads a chunk whole into a string and copies it once more for a cloud bucket
	 * ({@see \Bitrix\UI\FileUploader\TempFile::appendToFile()} and ::appendToCloud()), so a portal that
	 * lets a body of 100 MB through would otherwise ACCEPT a chunk of 100 MB - and die on it. The
	 * recommendation alone cannot hold this: it is advice, and what a caller is judged by is this number.
	 *
	 * @param int $requestCeiling The body of one request, as the directives of php leave it.
	 * @param int $memoryLimit memory_limit, as php reports it; -1 and 0 are its legal "no limit".
	 */
	private static function narrowToAcceptedSize(int $requestCeiling, int $memoryLimit): int
	{
		return min($requestCeiling, self::narrowToMemoryShare($memoryLimit, self::MEMORY_LIMIT_ACCEPTED_SHARE));
	}

	/** memory_limit of -1 or 0 is "no limit", and then memory bounds nothing at all. */
	private static function narrowToMemoryShare(int $memoryLimit, int $share): int
	{
		return $memoryLimit <= 0 ? PHP_INT_MAX : intdiv($memoryLimit, $share);
	}

	/**
	 * 🔴 ZERO IS THE LEGAL "NO LIMIT" OF BOTH DIRECTIVES, and php itself reads it that way. Taking it for a
	 * ceiling of zero bytes left the door silently broken on such a portal: start answered chunkSize 0 and
	 * every chunk that followed was too big. What bounds a chunk then is the only ceiling left - the file
	 * itself, the same 100 MB the one-shot door of im stands by.
	 */
	private static function findPhpUploadSize(): int
	{
		return self::narrowToPhpCeiling([Ini::getInt('post_max_size'), Ini::getInt('upload_max_filesize')]);
	}

	/**
	 * @param int[] $ceilings The two directives, as php reports them.
	 */
	private static function narrowToPhpCeiling(array $ceilings): int
	{
		$real = array_filter($ceilings, static fn (int $ceiling): bool => $ceiling > 0);

		if ($real === [])
		{
			return FileService::MAX_FILE_SIZE;
		}

		return max(0, min($real) - self::ENVELOPE_RESERVE);
	}

	/** memory_limit of -1 or 0 is "no limit", and then the fixed ceiling is the whole answer. */
	private static function findRecommendedCeiling(): int
	{
		return min(
			self::RECOMMENDED_CEILING,
			self::narrowToMemoryShare(Ini::getInt('memory_limit'), self::MEMORY_LIMIT_SHARE),
		);
	}
}
