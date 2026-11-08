<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MessageList;

use Bitrix\Mail\Helper\MailContact;

/**
 * Sender avatar as parameters instead of markup: the server picks the kind and resizes the picture,
 * the client draws it.
 */
class AvatarPresenter
{
	public const KIND_IMAGE = 'image';
	public const KIND_INITIALS = 'initials';
	public const KIND_DEFAULT = 'default';

	public const DEFAULT_SIZE = 22;

	/** One sender repeats across the page; the picture is resized once. */
	private array $presented = [];

	/**
	 * @param array $avatarParams One entry of AvatarManager::getAvatarParamsFromMessagesHeaders()
	 * @return array{kind: string, src?: string, color?: string, initials?: string, size: int,
	 *     protected?: bool, cacheIdentity?: string, fallback?: array}
	 */
	public function present(array $avatarParams, int $size = self::DEFAULT_SIZE): array
	{
		$key = md5(serialize([
			$avatarParams['email'] ?? null,
			$avatarParams['name'] ?? null,
			$avatarParams['mailContact'] ?? null,
			$avatarParams['senderAvatar']['SOURCE'] ?? null,
			$avatarParams['senderAvatar']['IMAGE_URL'] ?? null,
			$avatarParams['senderAvatar']['CACHE_IDENTITY'] ?? null,
			$size,
		]));

		return $this->presented[$key] ??= $this->build($avatarParams, $size);
	}

	private function build(array $avatarParams, int $size): array
	{
		$senderAvatar = $avatarParams['senderAvatar'] ?? null;
		if (
			is_array($senderAvatar)
			&& ($senderAvatar['SOURCE'] ?? null) === 'sender'
			&& !empty($senderAvatar['IMAGE_URL'])
		)
		{
			$avatar = [
				'kind' => self::KIND_IMAGE,
				'size' => $size,
				'src' => (string)$senderAvatar['IMAGE_URL'],
				'protected' => true,
				'fallback' => $this->buildLegacy($avatarParams, $size),
			];
			if (is_string($senderAvatar['CACHE_IDENTITY'] ?? null) && $senderAvatar['CACHE_IDENTITY'] !== '')
			{
				$avatar['cacheIdentity'] = $senderAvatar['CACHE_IDENTITY'];
			}

			return $avatar;
		}

		return $this->buildLegacy($avatarParams, $size);
	}

	private function buildLegacy(array $avatarParams, int $size): array
	{
		$contact = is_array($avatarParams['mailContact'] ?? null) ? $avatarParams['mailContact'] : [];
		$fileId = $contact['FILE_ID'] ?? 0;

		if (!empty($fileId) && is_numeric($fileId))
		{
			return $this->presentImage($fileId, $size);
		}

		return $this->presentIcon($contact, $avatarParams, $size);
	}

	private function presentImage(mixed $fileId, int $size): array
	{
		$image = \CFile::resizeImageGet(
			$fileId,
			['width' => $size, 'height' => $size],
			BX_RESIZE_IMAGE_EXACT,
			false,
		);

		// an unresizable picture falls back to the default avatar, not to initials
		if (empty($image['src']))
		{
			return ['kind' => self::KIND_DEFAULT, 'size' => $size];
		}

		return ['kind' => self::KIND_IMAGE, 'src' => (string)$image['src'], 'size' => $size];
	}

	private function presentIcon(array $contact, array $avatarParams, int $size): array
	{
		$icon = is_array($contact['ICON'] ?? null) ? $contact['ICON'] : [];
		$color = $icon['COLOR'] ?? null;
		$storedInitials = $icon['INITIALS'] ?? null;

		if (empty($color) || empty($storedInitials))
		{
			return ['kind' => self::KIND_DEFAULT, 'size' => $size];
		}

		$email = $avatarParams['email'] ?? null;
		$name = $avatarParams['name'] ?? null;

		if (!empty($email) && !empty($name))
		{
			$initials = MailContact::getInitials($email, $name);
		}
		elseif (!empty($email))
		{
			$initials = MailContact::getInitials($email);
		}
		else
		{
			$initials = $storedInitials;
		}

		return [
			'kind' => self::KIND_INITIALS,
			'color' => (string)$color,
			'initials' => (string)$initials,
			'size' => $size,
		];
	}
}
