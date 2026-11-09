<?php

namespace Bitrix\Landing\Markup\Delivery;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Security\Sign\Signer;

/**
 * Issuer of signed links to the files of a markup package (API-12).
 *
 * The frame of a custom block has an opaque origin and sends no portal cookies, so a file is
 * served by the signature, not by the session. The signature is made with the portal key and
 * binds the block, the file of its manifest and the expiry. A published link has no expiry;
 * a draft link lives until the end of a window: the expiry is aligned, so the links issued
 * within one window are equal and cacheable.
 */
class FileLinkSigner
{
	public const DEFAULT_DRAFT_TTL = 43200;
	public const WINDOW = 21600;

	private const SALT = 'landing.markup.file';
	private const OPTION_DRAFT_TTL = 'landing_markup_draft_link_ttl';
	private const URL = '/bitrix/services/main/ajax.php?action=landing.markupfile.get';

	/** @var \Closure(): int */
	private \Closure $clock;

	/**
	 * @param callable(): int|null $clock Current unix time; the system time by default.
	 */
	public function __construct(?callable $clock = null)
	{
		$this->clock = $clock ? \Closure::fromCallable($clock) : static fn(): int => time();
	}

	/**
	 * @param int|null $expiresAt Null for a published link.
	 */
	public function sign(int $blockId, int $fileId, ?int $expiresAt): string
	{
		return (new Signer())->getSignature($this->buildValue($blockId, $fileId, $expiresAt), self::SALT);
	}

	/**
	 * Checks the signature and, for a draft link, that the window is not over yet.
	 */
	public function verify(int $blockId, int $fileId, ?int $expiresAt, string $signature): bool
	{
		if ($signature === '' || $blockId <= 0 || $fileId <= 0)
		{
			return false;
		}
		if ($expiresAt !== null && $expiresAt < ($this->clock)())
		{
			return false;
		}

		try
		{
			return (new Signer())->validate($this->buildValue($blockId, $fileId, $expiresAt), $signature, self::SALT);
		}
		catch (BadSignatureException)
		{
			return false;
		}
	}

	/**
	 * Expiry of the draft links issued now: the lifetime of a draft link, rounded up to the window end.
	 */
	public function getDraftExpiresAt(): int
	{
		$ttl = (int)Option::get('landing', self::OPTION_DRAFT_TTL, self::DEFAULT_DRAFT_TTL);
		if ($ttl <= 0)
		{
			$ttl = self::DEFAULT_DRAFT_TTL;
		}

		return (int)(ceil((($this->clock)() + $ttl) / self::WINDOW) * self::WINDOW);
	}

	/**
	 * Root relative url of the controller serving the file.
	 * @param int|null $expiresAt Null for a published link.
	 */
	public function getUrl(int $blockId, int $fileId, ?int $expiresAt): string
	{
		return self::URL
			. '&b=' . $blockId
			. '&f=' . $fileId
			. ($expiresAt !== null ? '&e=' . $expiresAt : '')
			. '&s=' . rawurlencode($this->sign($blockId, $fileId, $expiresAt));
	}

	private function buildValue(int $blockId, int $fileId, ?int $expiresAt): string
	{
		return $blockId . '.' . $fileId . '.' . ($expiresAt ?? 'public');
	}
}
