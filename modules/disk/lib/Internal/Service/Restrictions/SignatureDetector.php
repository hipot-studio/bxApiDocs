<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Restrictions;

/**
 * Recognizes the actual type of the content by the bytes it begins with.
 *
 * The detector knows nothing about the portal: it is given the head of the content and answers
 * with the type it recognizes. Content that no sample matches has no type here, and that is a
 * normal answer rather than a refusal: the name of the file still speaks for it.
 *
 * zip-based formats and text scripts are absent from the table on purpose: their beginning does
 * not tell one format from another, so only the name decides for them.
 */
class SignatureDetector
{
	/**
	 * Number of leading bytes the detector asks for: the deepest sample of the table sits at 257.
	 */
	public const HEAD_LENGTH = 512;

	/**
	 * Where the DOS header of a PE keeps the offset of the mark that names the format, and the mark.
	 */
	protected const PE_POINTER_OFFSET = 0x3C;
	protected const PE_MARK = "PE\x00\x00";

	/**
	 * Where an ISO-BMFF container names the format that lies inside it - the major brand, four
	 * bytes right behind the sample - and the brands of the formats the type of this table stands
	 * for: mp4, QuickTime, m4v and 3gp, a line each, in the order of the extensions of the type.
	 */
	protected const MP4_BRAND_OFFSET = 8;
	protected const MP4_BRAND_LENGTH = 4;
	protected const MP4_BRANDS = [
		'isom', 'iso2', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'mp71', 'avc1', 'dash', 'mmp4', 'msnv',
		'qt  ',
		'M4V ', 'M4VH', 'M4VP',
		'3gp1', '3gp2', '3gp3', '3gp4', '3gp5', '3gp6', '3gp7', '3gp8', '3gp9', '3gg6', '3ge6', '3gr6', '3gs6',
	];

	/**
	 * Types in the order they are tried; the first matching one wins.
	 *
	 * A type matches when any of its signatures matches, and a signature matches when every part
	 * of it matches at its own offset. A part carrying a mask compares the bytes under that mask,
	 * which is how a sample covering a range of values is written.
	 *
	 * Strength belongs to the type and is decided by the owner of the feature, not counted from
	 * the length of the sample: a weak type is one whose accidental match on a legitimate file is
	 * likelier than the harm of leaving the type to the name alone. A strong type may name a
	 * "confirmation" - a further look into the head that has to agree before the verdict is given
	 * as strong; unconfirmed, such a type is recognized as any weak one is.
	 */
	protected const TYPES = [
		'pe' => [
			'extensions' => ['exe', 'dll', 'com', 'scr'],
			'isStrong' => true,
			// Two bytes of ASCII that a legitimate file may well begin with, so the verdict of this
			// type is worth its strength only together with the DOS header behind them.
			'confirmation' => 'confirmsPortableExecutable',
			'signatures' => [
				[['offset' => 0, 'bytes' => "\x4D\x5A"]],
			],
		],
		'elf' => [
			'extensions' => ['elf', 'so', 'bin'],
			'isStrong' => true,
			'signatures' => [
				[['offset' => 0, 'bytes' => "\x7F\x45\x4C\x46"]],
			],
		],
		// Mach-O executables carry no extension on their own platform, so the policy has no name to
		// restrict them by and this type decides nothing on its own. It is kept in the table so that
		// a real executable format is recognized as itself instead of being answered for as unknown.
		'macho' => [
			'extensions' => [],
			'isStrong' => true,
			'signatures' => [
				[['offset' => 0, 'bytes' => "\xFE\xED\xFA\xCE"]],
				[['offset' => 0, 'bytes' => "\xFE\xED\xFA\xCF"]],
				[['offset' => 0, 'bytes' => "\xCA\xFE\xBA\xBE"]],
			],
		],
		'pdf' => [
			'extensions' => ['pdf'],
			'isStrong' => true,
			'signatures' => [
				[['offset' => 0, 'bytes' => '%PDF-']],
			],
		],
		'rar' => [
			'extensions' => ['rar'],
			'isStrong' => true,
			'signatures' => [
				[['offset' => 0, 'bytes' => "\x52\x61\x72\x21\x1A\x07"]],
			],
		],
		'7z' => [
			'extensions' => ['7z'],
			'isStrong' => true,
			'signatures' => [
				[['offset' => 0, 'bytes' => "\x37\x7A\xBC\xAF\x27\x1C"]],
			],
		],
		'gzip' => [
			'extensions' => ['gz', 'tgz'],
			'isStrong' => false,
			'signatures' => [
				[['offset' => 0, 'bytes' => "\x1F\x8B"]],
			],
		],
		'bzip2' => [
			'extensions' => ['bz2'],
			'isStrong' => false,
			'signatures' => [
				[['offset' => 0, 'bytes' => 'BZh']],
			],
		],
		'tar' => [
			'extensions' => ['tar'],
			'isStrong' => true,
			'signatures' => [
				[['offset' => 257, 'bytes' => 'ustar']],
			],
		],
		'riff-avi' => [
			'extensions' => ['avi'],
			'isStrong' => true,
			'signatures' => [
				[
					['offset' => 0, 'bytes' => 'RIFF'],
					['offset' => 8, 'bytes' => 'AVI'],
				],
			],
		],
		'matroska' => [
			'extensions' => ['mkv', 'webm'],
			'isStrong' => true,
			'signatures' => [
				[['offset' => 0, 'bytes' => "\x1A\x45\xDF\xA3"]],
			],
		],
		'mp4' => [
			'extensions' => ['mp4', 'mov', 'm4v', '3gp'],
			'isStrong' => true,
			// The sample opens the whole ISO-BMFF family - a HEIC photo, an AVIF image, m4a sound
			// and the raw of a camera are written in the same container - so the verdict of this
			// type is worth its strength only together with the brand naming the format inside.
			'confirmation' => 'confirmsMp4Brand',
			'signatures' => [
				[['offset' => 4, 'bytes' => 'ftyp']],
			],
		],
		'mpeg-audio' => [
			'extensions' => ['mp3'],
			'isStrong' => false,
			'signatures' => [
				[['offset' => 0, 'bytes' => 'ID3']],
				[['offset' => 0, 'bytes' => "\xFF\xE0", 'mask' => "\xFF\xF0"]],
			],
		],
		'png' => [
			'extensions' => ['png'],
			'isStrong' => true,
			'signatures' => [
				[['offset' => 0, 'bytes' => "\x89\x50\x4E\x47"]],
			],
		],
		'jpeg' => [
			'extensions' => ['jpg', 'jpeg', 'jpe'],
			'isStrong' => true,
			'signatures' => [
				[['offset' => 0, 'bytes' => "\xFF\xD8\xFF"]],
			],
		],
		'gif' => [
			'extensions' => ['gif'],
			'isStrong' => true,
			'signatures' => [
				[['offset' => 0, 'bytes' => 'GIF8']],
			],
		],
	];

	/**
	 * Extensions of the types the detector recognizes firmly, as a map to look up by.
	 *
	 * Only through these can the content ever overrule the name: a recognized type meets the policy
	 * through its own synonymous extensions, and a weak type is left to the name whatever it holds.
	 * So a policy naming none of them cannot be helped by a look at the content.
	 *
	 * A type whose strength waits for a confirmation stands here all the same: the map is read
	 * before any content is, and dropping such a type from it would leave the head of a renamed
	 * executable unread - which is the very thing the signature step exists for.
	 *
	 * @return array<string, true>
	 */
	public function getStronglyRecognizedExtensionMap(): array
	{
		static $map = null;

		if ($map === null)
		{
			$map = [];
			foreach (self::TYPES as $type)
			{
				if (!$type['isStrong'])
				{
					continue;
				}

				foreach ($type['extensions'] as $extension)
				{
					$map[$extension] = true;
				}
			}
		}

		return $map;
	}

	/**
	 * Returns the recognized type as "id", its synonymous "extensions" and the strength of the
	 * verdict in "isStrong". An unrecognized head has no type: the answer is null.
	 *
	 * @param string|null $head Leading bytes of the content, HEAD_LENGTH of them are enough.
	 * @return array|null
	 */
	public function detect(?string $head): ?array
	{
		if ($head === null || $head === '')
		{
			return null;
		}

		foreach (self::TYPES as $id => $type)
		{
			if ($this->matchesAnySignature($head, $type['signatures']))
			{
				return [
					'id' => $id,
					'extensions' => $type['extensions'],
					'isStrong' => $this->isStrengthConfirmed($head, $type),
				];
			}
		}

		return null;
	}

	/**
	 * The strength a type declares may still depend on the head at hand: a type naming a
	 * confirmation is answered for as strong only where that further look agrees.
	 */
	protected function isStrengthConfirmed(string $head, array $type): bool
	{
		if (!$type['isStrong'])
		{
			return false;
		}

		$confirmation = $type['confirmation'] ?? null;

		return $confirmation === null || $this->{$confirmation}($head);
	}

	/**
	 * The DOS header of a PE points at the format itself: four bytes at PE_POINTER_OFFSET, read as
	 * a little-endian number, hold the offset the "PE\0\0" mark lies at.
	 *
	 * An executable whose DOS stub pushes that mark past the head we read stays unconfirmed and is
	 * judged by its name. The trade is deliberate: the rule of the feature puts a rare executable
	 * let through above a legitimate file refused for beginning with two letters.
	 */
	protected function confirmsPortableExecutable(string $head): bool
	{
		$pointer = substr($head, static::PE_POINTER_OFFSET, 4);
		if (strlen($pointer) !== 4)
		{
			return false;
		}

		$markOffset = unpack('V', $pointer)[1];

		return substr($head, $markOffset, strlen(static::PE_MARK)) === static::PE_MARK;
	}

	/**
	 * An ISO-BMFF container names the format it carries by its major brand, the four bytes behind
	 * the sample, and only there do the formats of this type part from the photos, the images and
	 * the sound written in the very same container.
	 *
	 * A brand of another format, an unknown one and a head too short to hold one all leave the type
	 * unconfirmed: the container is recognized as itself, and the name of the file decides the
	 * write. Brands are read as they are written - the case of the four bytes belongs to the brand.
	 */
	protected function confirmsMp4Brand(string $head): bool
	{
		$brand = substr($head, static::MP4_BRAND_OFFSET, static::MP4_BRAND_LENGTH);

		return in_array($brand, static::MP4_BRANDS, true);
	}

	protected function matchesAnySignature(string $head, array $signatures): bool
	{
		foreach ($signatures as $signature)
		{
			if ($this->matchesSignature($head, $signature))
			{
				return true;
			}
		}

		return false;
	}

	protected function matchesSignature(string $head, array $signature): bool
	{
		foreach ($signature as $part)
		{
			if (!$this->matchesPart($head, $part))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * A head too short for the offset of the part simply does not match it.
	 */
	protected function matchesPart(string $head, array $part): bool
	{
		$bytes = $part['bytes'];
		$length = strlen($bytes);

		$chunk = substr($head, $part['offset'], $length);
		if (strlen($chunk) !== $length)
		{
			return false;
		}

		$mask = $part['mask'] ?? null;
		if ($mask !== null)
		{
			$chunk &= $mask;
		}

		return $chunk === $bytes;
	}
}
