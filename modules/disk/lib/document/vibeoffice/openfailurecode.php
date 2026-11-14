<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Vibeoffice;

enum OpenFailureCode: string
{
	case InProgress = 'ERR-IN-PROGRESS';
	case SaveRefused = 'ERR-SAVE-REFUSED';
	case DocumentFull = 'ERR-DOC-FULL';
	case NoGrant = 'ERR-NO-GRANT';
	case FileTooLarge = 'ERR-FILE-TOO-LARGE';
	case FiletypeNotEditable = 'ERR-FILETYPE-NOT-EDITABLE';
	case UnsupportedFiletype = 'ERR-UNSUPPORTED-FILETYPE';
	case Other413 = 'ERR-OPEN-413-OTHER';
	case Other422 = 'ERR-OPEN-422-OTHER';
	case PullFailed = 'ERR-PULL-FAILED';
	case NoCapacity = 'ERR-NO-CAPACITY';
	case CapacityFull = 'ERR-CAPACITY-FULL';
	case Auth = 'ERR-AUTH';
	case OpenFailed = 'ERR-OPEN-FAILED';

	public static function fromPlatform(int $httpStatus, ?string $platformCode, bool $responseVerified): self
	{
		return match (true)
		{
			$responseVerified && $platformCode === 'save_refused' => self::SaveRefused,
			$responseVerified && $platformCode === 'doc_full' => self::DocumentFull,
			OpenRetryPolicy::isTemporaryPlatformCode($platformCode), $httpStatus === 409 => self::InProgress,
			$httpStatus === 403 => self::NoGrant,
			$responseVerified && $httpStatus === 413 && $platformCode === 'file_too_large' => self::FileTooLarge,
			$responseVerified && $httpStatus === 422 && $platformCode === 'filetype_not_editable' => self::FiletypeNotEditable,
			$responseVerified && $httpStatus === 422 && $platformCode === 'unsupported_filetype' => self::UnsupportedFiletype,
			$httpStatus === 413 => self::Other413,
			$httpStatus === 422 => self::Other422,
			$httpStatus === 502 => self::PullFailed,
			$responseVerified && $httpStatus === 503 && $platformCode === 'no_capacity' => self::CapacityFull,
			$httpStatus === 503 => self::NoCapacity,
			$httpStatus === 401 => self::Auth,
			default => self::OpenFailed,
		};
	}

	public function template(): ?string
	{
		return match ($this)
		{
			self::InProgress => 'saving',
			self::FileTooLarge => 'large-file',
			self::FiletypeNotEditable => 'filetype-not-editable',
			self::UnsupportedFiletype => 'unsupported-filetype',
			self::Other413 => 'open-413-other',
			self::Other422 => 'open-422-other',
			self::CapacityFull => 'no-capacity',
			default => null,
		};
	}

	public function releasesEditSlot(): bool
	{
		// Every completed open attempt without a config gives up its edit reservation.
		// InProgress is the only continuation of the same attempt. New codes must be
		// classified here explicitly rather than inheriting behavior from an ERR- prefix.
		return match ($this)
		{
			self::InProgress => false,
			self::SaveRefused, self::DocumentFull, self::NoGrant, self::FileTooLarge,
			self::FiletypeNotEditable, self::UnsupportedFiletype, self::Other413,
			self::Other422, self::PullFailed, self::NoCapacity, self::CapacityFull, self::Auth,
			self::OpenFailed => true,
		};
	}
}
