<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Audit;

use Bitrix\Intranet\Service\ServiceContainer;
use Bitrix\Main\Loader;
use CAccess;
use Closure;
use Throwable;

/**
 * Names the member a rights event is about. The event keeps only the access code (U57, D5, AU),
 * so the name is read when the card is opened: the card shows the member as the portal names
 * them now, and falls back to the code for a member the portal no longer knows. The name is an
 * extra to the card, so a failing access provider leaves the code instead of breaking the card.
 * A department is named by its own name, as the company structure shows it, and the part of it
 * the access reaches - the department or the department with its subdepartments - goes apart,
 * instead of the one long line the access provider builds.
 */
final class AuditAccessSubjectResolver
{
	private const ACCESS_CODE_KEY = 'accessCode';
	private const USER_CODE_PATTERN = '/^U([1-9][0-9]*)$/D';
	private const DEPARTMENT_CODE_PATTERN = '/^(DR|D)([1-9][0-9]*)$/D';
	public const SCOPE_DEPARTMENT = 'department';
	public const SCOPE_DEPARTMENT_TREE = 'department_tree';

	private readonly Closure $namesLoader;
	private readonly Closure $avatarResolver;
	private readonly Closure $departmentLoader;

	public function __construct(
		?callable $namesLoader = null,
		?callable $avatarResolver = null,
		?callable $departmentLoader = null,
	)
	{
		$this->namesLoader = Closure::fromCallable(
			$namesLoader ?? static fn(array $codes): array => (new CAccess())->GetNames($codes),
		);
		$this->avatarResolver = Closure::fromCallable($avatarResolver ?? static fn(int $userId): ?string => null);
		$this->departmentLoader = Closure::fromCallable($departmentLoader ?? static function (int $departmentId): ?string {
			if (!Loader::includeModule('intranet'))
			{
				return null;
			}

			return ServiceContainer::getInstance()->departmentRepository()->getById($departmentId)?->getName();
		});
	}

	/**
	 * @param array<string, bool|float|int|string|null> $details
	 * @return array{code: string, name: ?string, userId: ?int, avatar: ?string, scope: ?string}|null
	 */
	public function resolve(array $details): ?array
	{
		$code = $details[self::ACCESS_CODE_KEY] ?? null;
		if (!is_string($code) || $code === '')
		{
			return null;
		}

		$unnamed = ['code' => $code, 'name' => null, 'userId' => null, 'avatar' => null, 'scope' => null];
		if (preg_match(self::DEPARTMENT_CODE_PATTERN, $code, $department) === 1)
		{
			$departmentName = $this->resolveDepartmentName((int)$department[2]);
			if ($departmentName !== null)
			{
				return [
					...$unnamed,
					'name' => $departmentName,
					'scope' => $department[1] === 'DR' ? self::SCOPE_DEPARTMENT_TREE : self::SCOPE_DEPARTMENT,
				];
			}
		}

		try
		{
			$names = ($this->namesLoader)([$code]);
		}
		catch (Throwable)
		{
			return $unnamed;
		}

		$name = is_array($names) ? trim((string)($names[$code]['name'] ?? '')) : '';
		if ($name === '')
		{
			return $unnamed;
		}

		$userId = preg_match(self::USER_CODE_PATTERN, $code, $matches) === 1 ? (int)$matches[1] : null;

		return [
			'code' => $code,
			'name' => $name,
			'userId' => $userId,
			'avatar' => $userId === null ? null : $this->resolveAvatar($userId),
			'scope' => null,
		];
	}

	private function resolveDepartmentName(int $departmentId): ?string
	{
		try
		{
			$name = trim((string)($this->departmentLoader)($departmentId));
		}
		catch (Throwable)
		{
			return null;
		}

		return $name === '' ? null : $name;
	}

	private function resolveAvatar(int $userId): ?string
	{
		try
		{
			return ($this->avatarResolver)($userId);
		}
		catch (Throwable)
		{
			return null;
		}
	}
}
