<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\ExternalLink;

final class PasswordProtectionResult
{
	public const STATUS_SUCCESS = 'success';
	public const STATUS_WRONG_PASSWORD = 'wrong_password';
	public const STATUS_CAPTCHA_REQUIRED = 'captcha_required';
	public const STATUS_CAPTCHA_INVALID = 'captcha_invalid';

	private const ALLOWED_STATUSES = [
		self::STATUS_SUCCESS => true,
		self::STATUS_WRONG_PASSWORD => true,
		self::STATUS_CAPTCHA_REQUIRED => true,
		self::STATUS_CAPTCHA_INVALID => true,
	];

	public function __construct(
		public readonly string $status,
		public readonly bool $captchaRequired,
		public readonly ?string $captchaSid,
		public readonly bool $passwordChecked,
	)
	{
		if (!isset(self::ALLOWED_STATUSES[$this->status]))
		{
			throw new \InvalidArgumentException('Unsupported password protection status.');
		}
	}
}
