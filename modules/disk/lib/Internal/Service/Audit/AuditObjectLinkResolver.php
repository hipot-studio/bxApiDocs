<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Entity\Audit\AuditTarget;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Web\Uri;
use Closure;

final class AuditObjectLinkResolver
{
	public const REASON_DELETED = 'deleted';
	public const REASON_NOT_FOUND = 'not_found';
	public const REASON_ACCESS_DENIED = 'access_denied';
	public const REASON_UNSUPPORTED_TYPE = 'unsupported_type';

	private readonly Closure $objectLoader;
	private readonly Closure $urlBuilder;

	public function __construct(
		?callable $objectLoader = null,
		?callable $urlBuilder = null,
	)
	{
		$this->objectLoader = Closure::fromCallable(
			$objectLoader ?? static fn(int $objectId): ?BaseObject => BaseObject::loadById($objectId),
		);
		$this->urlBuilder = Closure::fromCallable(
			$urlBuilder ?? static function (BaseObject $object): string {
				$urlManager = Driver::getInstance()->getUrlManager();

				return $object instanceof File
					? $urlManager->getPathFileDetail($object)
					: $urlManager->getPathFolderList($object);
			},
		);
	}

	/**
	 * @return array{available: bool, url: ?string, reason: ?string}
	 */
	public function resolve(
		string $targetType,
		string $targetId,
		?CurrentUser $currentUser = null,
	): array
	{
		if (!in_array($targetType, [AuditTarget::TYPE_FILE, AuditTarget::TYPE_FOLDER], true))
		{
			return $this->unavailable(self::REASON_UNSUPPORTED_TYPE);
		}

		$objectId = filter_var($targetId, FILTER_VALIDATE_INT, [
			'options' => ['min_range' => 1],
		]);
		if ($objectId === false)
		{
			return $this->unavailable(self::REASON_NOT_FOUND);
		}

		$object = ($this->objectLoader)($objectId);
		if (!$object instanceof BaseObject || !$this->matchesTargetType($object, $targetType))
		{
			return $this->unavailable(self::REASON_NOT_FOUND);
		}

		if ($object->isDeleted())
		{
			return $this->unavailable(self::REASON_DELETED);
		}

		$storage = $object->getStorage();
		if ($storage === null)
		{
			return $this->unavailable(self::REASON_NOT_FOUND);
		}

		$securityContext = $storage->getSecurityContext($currentUser ?? CurrentUser::get());
		if (!$object->canRead($securityContext))
		{
			return $this->unavailable(self::REASON_ACCESS_DENIED);
		}

		$url = (string)($this->urlBuilder)($object);
		if (!$this->isSafeRelativeUrl($url))
		{
			return $this->unavailable(self::REASON_NOT_FOUND);
		}

		return [
			'available' => true,
			'url' => $url,
			'reason' => null,
		];
	}

	private function matchesTargetType(BaseObject $object, string $targetType): bool
	{
		return match ($targetType)
		{
			AuditTarget::TYPE_FILE => $object instanceof File,
			AuditTarget::TYPE_FOLDER => $object instanceof Folder,
			default => false,
		};
	}

	private function isSafeRelativeUrl(string $url): bool
	{
		if ($url === '' || !str_starts_with($url, '/') || str_starts_with($url, '//'))
		{
			return false;
		}

		$uri = new Uri($url);

		return $uri->getScheme() === ''
			&& $uri->getHost() === ''
			&& $uri->getPath() !== ''
			&& $uri->getQuery() === ''
			&& $uri->getFragment() === '';
	}

	/**
	 * @return array{available: false, url: null, reason: string}
	 */
	private function unavailable(string $reason): array
	{
		return [
			'available' => false,
			'url' => null,
			'reason' => $reason,
		];
	}
}
