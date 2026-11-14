<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync;

use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Public access of an object by an external link: the one effective source and its parameters.
 *
 * Three shapes, built by the constructors below:
 *  - disabled: only {@see $enabled};
 *  - a source the reader sees: every field; {@see $expiresAt} is published as null for a link
 *    without a lifetime, and {@see $inheritedFromFolderId} is present only for an inherited source;
 *  - a source in a folder hidden from the reader: the fact alone, without the folder and the
 *    parameters of its link.
 *
 * Property order is the order of the published answer.
 */
final class PublicAccessDto extends Dto
{
	public const SOURCE_DIRECT = 'direct';
	public const SOURCE_INHERITED = 'inherited';

	public const PERMISSION_VIEW = 'view';
	public const PERMISSION_EDIT = 'edit';

	public bool $enabled;

	public string $effectiveSource;

	public int $inheritedFromFolderId;

	public string $permission;

	/** Null for a link without a lifetime; absent when the parameters of the source are not shown. */
	public ?DateTime $expiresAt;

	public bool $passwordProtected;

	/** More than one public source stands on the object, hidden ones included. */
	public bool $hasOtherSources;

	public static function disabled(): self
	{
		$access = new self();
		$access->enabled = false;

		return $access;
	}

	public static function visible(
		string $effectiveSource,
		?int $inheritedFromFolderId,
		string $permission,
		?DateTime $expiresAt,
		bool $passwordProtected,
		bool $hasOtherSources,
	): self
	{
		$access = new self();
		$access->enabled = true;
		$access->effectiveSource = $effectiveSource;
		if ($inheritedFromFolderId !== null)
		{
			$access->inheritedFromFolderId = $inheritedFromFolderId;
		}
		$access->permission = $permission;
		$access->expiresAt = $expiresAt;
		$access->passwordProtected = $passwordProtected;
		$access->hasOtherSources = $hasOtherSources;

		return $access;
	}

	public static function hidden(bool $hasOtherSources): self
	{
		$access = new self();
		$access->enabled = true;
		$access->effectiveSource = self::SOURCE_INHERITED;
		$access->hasOtherSources = $hasOtherSources;

		return $access;
	}
}
