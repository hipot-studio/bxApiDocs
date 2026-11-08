<?php

namespace Bitrix\Sign\Service\Integration\Bizproc;

use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Sign\Type\Bizproc\DraftActivityMessageCode;
use Bitrix\Sign\Type\Bizproc\DraftFileSource;
use Bitrix\Sign\Type\BlankFileExtension;

/**
 * Brings the value of the file source of the draft activity to a b_file id, the only form the blank
 * service accepts. The blank service reads the content and makes a copy of its own, so a file of the
 * Disk is not copied here.
 *
 * The service reports a code and never a text: the phrases live in the lang file of the activity.
 */
final class DraftFileSourceResolver
{
	/** @var \Closure(): bool */
	private \Closure $diskAvailabilityChecker;

	/** @var \Closure(int): (\Bitrix\Disk\File|null) */
	private \Closure $diskFileLocator;

	/**
	 * Both collaborators of the disk branch are static, so the dependencies are injectable: without
	 * them neither a portal without the Disk nor a missing object of the Disk is reproducible.
	 *
	 * @param \Closure(): bool|null $diskAvailabilityChecker
	 * @param \Closure(int): (\Bitrix\Disk\File|null)|null $diskFileLocator
	 */
	public function __construct(
		?\Closure $diskAvailabilityChecker = null,
		?\Closure $diskFileLocator = null,
	)
	{
		$this->diskAvailabilityChecker = $diskAvailabilityChecker
			?? static fn(): bool => Loader::includeModule('disk')
		;
		$this->diskFileLocator = $diskFileLocator
			?? static fn(int $diskFileId): ?\Bitrix\Disk\File => \Bitrix\Disk\File::loadById($diskFileId)
		;
	}

	/**
	 * @param string|null $fileSource source code written by the canonical control of the platform
	 * @param mixed $pairValue value of the pair, already resolved by the platform
	 * @param mixed $expressionValue value of the standalone expression, already resolved by the platform
	 *
	 * @return Result data of a successful result carries fileId and extension, a failed one carries
	 * a single error whose code is a DraftActivityMessageCode
	 */
	public function resolve(?string $fileSource, mixed $pairValue, mixed $expressionValue): Result
	{
		$pair = self::collectValues($pairValue);
		$expression = self::collectValues($expressionValue);

		if ($pair !== [] && $expression !== [])
		{
			return self::fail(DraftActivityMessageCode::SOURCE_CONFLICT);
		}

		$values = $expression === [] ? $pair : $expression;
		// the source type of the pair says nothing about the standalone expression: that one always
		// carries a file id of the bizproc
		$isDiskSource = $expression === []
			&& DraftFileSource::tryFrom((string)$fileSource) === DraftFileSource::DISK
		;

		if ($values === [])
		{
			return self::fail(DraftActivityMessageCode::EMPTY_FILE);
		}

		if (count($values) > 1)
		{
			return self::fail(DraftActivityMessageCode::VALUE_IS_MULTIPLE);
		}

		$fileIdResult = $isDiskSource
			? $this->resolveDiskFileId($values[0])
			: self::resolveBizprocFileId($values[0])
		;

		return $fileIdResult->isSuccess()
			? self::describeFile((int)$fileIdResult->getData()['fileId'])
			: $fileIdResult
		;
	}

	private function resolveDiskFileId(string $value): Result
	{
		if (!($this->diskAvailabilityChecker)())
		{
			return self::fail(DraftActivityMessageCode::DISK_MODULE_MISSING);
		}

		$diskFileId = self::toFileId($value);
		if ($diskFileId < 1)
		{
			return self::fail(DraftActivityMessageCode::SOURCE_VALUE_MISMATCH);
		}

		// the object is loaded once: the bizproc wrapper of the Disk repeats the very same load but
		// keeps the object to itself, so the id of the file cannot be taken from there
		$diskFile = ($this->diskFileLocator)($diskFileId);
		$fileId = $diskFile === null ? 0 : (int)$diskFile->getFileId();

		// the object is taken with no permission check on the file: a file property of a bizproc
		// carries no proof of where its value came from - the server takes the value of a form field,
		// and the selector of the Disk is a control of the interface only. This is the trust model of
		// the platform: the standard mail activity gives the same primitive to a wider audience
		// (CBPMailActivity::getFiles()). The residual risk is accepted knowingly
		return $fileId < 1
			? self::fail(DraftActivityMessageCode::DISK_FILE_NOT_FOUND)
			: self::withFileId($fileId)
		;
	}

	private static function resolveBizprocFileId(string $value): Result
	{
		$fileId = self::toFileId($value);
		if ($fileId < 1)
		{
			return self::fail(DraftActivityMessageCode::SOURCE_VALUE_MISMATCH);
		}

		// the helper of the bizproc reads the same b_file row that describeFile() reads below, so a
		// portal without the module gets the same verdict from that check alone
		if (Loader::includeModule('bizproc') && !\Bitrix\Bizproc\File::openById($fileId)->isSuccess())
		{
			return self::fail(DraftActivityMessageCode::FILE_NOT_FOUND);
		}

		return self::withFileId($fileId);
	}

	/**
	 * The extension is read by the class the blank service itself uses, so the check here and the
	 * blank created later cannot disagree.
	 */
	private static function describeFile(int $fileId): Result
	{
		$file = new \Bitrix\Sign\File($fileId);

		// existence is checked first: without the b_file row the name stays null, and the extension
		// is parsed out of that name
		if (!$file->isExist())
		{
			return self::fail(DraftActivityMessageCode::FILE_NOT_FOUND);
		}

		$extension = (string)$file->getExtension();
		if (!BlankFileExtension::isAllowed($extension))
		{
			return self::fail(DraftActivityMessageCode::UNSUPPORTED_EXTENSION);
		}

		return (new Result())->setData(['fileId' => $fileId, 'extension' => $extension]);
	}

	/**
	 * Values already resolved by the platform, flattened and cleaned of the empty ones. A multiple
	 * value is left as it is: the property is single, so several values are an error and not a
	 * reason to take the first one.
	 *
	 * @return list<string>
	 */
	private static function collectValues(mixed $value): array
	{
		$values = [];
		foreach (is_array($value) ? $value : [$value] as $item)
		{
			if (is_array($item))
			{
				$values = array_merge($values, self::collectValues($item));

				continue;
			}

			if (!is_scalar($item))
			{
				continue;
			}

			$text = trim((string)$item);
			if ($text !== '' && $text !== '0')
			{
				$values[] = $text;
			}
		}

		return $values;
	}

	/**
	 * Only a plain positive integer is a file id. A value of another shape is reported as a
	 * mismatch instead of being cast: a cast would resolve a file the author never named.
	 */
	private static function toFileId(string $value): int
	{
		return preg_match('/^\d+$/', $value) === 1 ? (int)$value : 0;
	}

	private static function withFileId(int $fileId): Result
	{
		return (new Result())->setData(['fileId' => $fileId]);
	}

	private static function fail(DraftActivityMessageCode $code): Result
	{
		return (new Result())->addError(new Error('', $code->value));
	}
}
