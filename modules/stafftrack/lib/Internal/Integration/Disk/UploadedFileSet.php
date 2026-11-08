<?php

namespace Bitrix\StaffTrack\Internal\Integration\Disk;

final class UploadedFileSet
{
	private const ROLE_POSTER = 'poster';

	/**
	 * @param string[] $attachmentTokens
	 * @param array<string, string> $posterTokenByAttachmentToken
	 */
	private function __construct(
		private readonly array $attachmentTokens,
		private readonly array $posterTokenByAttachmentToken,
	)
	{
	}

	public static function fromRaw(array $uploadedFiles): self
	{
		$attachmentTokens = [];
		$posterCandidates = [];

		foreach ($uploadedFiles as $item)
		{
			$token = self::extractToken($item);
			if ($token === null)
			{
				continue;
			}

			if (!is_array($item) || ($item['role'] ?? null) !== self::ROLE_POSTER)
			{
				$attachmentTokens[] = $token;

				continue;
			}

			$posterFor = self::extractString($item['posterFor'] ?? null);
			if ($posterFor !== null && !isset($posterCandidates[$posterFor]))
			{
				$posterCandidates[$posterFor] = $token;
			}
		}

		$knownAttachments = array_flip($attachmentTokens);

		return new self(
			$attachmentTokens,
			array_intersect_key($posterCandidates, $knownAttachments),
		);
	}

	/**
	 * @return string[]
	 */
	public function getAttachmentTokens(): array
	{
		return $this->attachmentTokens;
	}

	/**
	 * @return string[]
	 */
	public function getAllTokens(): array
	{
		return array_merge($this->attachmentTokens, array_values($this->posterTokenByAttachmentToken));
	}

	public function getPosterTokenFor(string $attachmentToken): ?string
	{
		return $this->posterTokenByAttachmentToken[$attachmentToken] ?? null;
	}

	public function isEmpty(): bool
	{
		return $this->attachmentTokens === [];
	}

	private static function extractToken(mixed $item): ?string
	{
		if (is_array($item))
		{
			return self::extractString($item['token'] ?? null);
		}

		return self::extractString($item);
	}

	private static function extractString(mixed $value): ?string
	{
		if (!is_string($value))
		{
			return null;
		}

		$value = trim($value);

		return $value === '' ? null : $value;
	}
}
