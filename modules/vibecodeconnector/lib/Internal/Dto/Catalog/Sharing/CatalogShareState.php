<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Dto\Catalog\Sharing;

final readonly class CatalogShareState
{
	public const AUDIENCE_OWNER_ONLY = 'OWNER_ONLY';
	public const AUDIENCE_SPECIFIC_MEMBERS = 'SPECIFIC_MEMBERS';
	public const AUDIENCE_PORTAL = 'PORTAL';
	public const AUDIENCE_AUTHENTICATED = 'AUTHENTICATED';
	public const AUDIENCE_PUBLIC = 'PUBLIC';

	public const DEPARTMENT_FORMAT_ACCESS_CODE = 'access_code';

	/**
	 * @param list<CatalogShareParticipant> $users
	 * @param list<CatalogShareParticipant> $departments
	 */
	public function __construct(
		public string $audience,
		public array $users,
		public array $departments,
	) {
	}

	/**
	 * @return array{
	 *     audience: string,
	 *     users: list<array{id: string, name: string}>,
	 *     departments: list<array{id: string, name: string}>
	 * }
	 */
	public function toArray(): array
	{
		return [
			'audience' => $this->audience,
			'users' => array_map(
				static fn(CatalogShareParticipant $participant): array => $participant->toArray(),
				$this->users,
			),
			'departments' => array_map(
				static fn(CatalogShareParticipant $participant): array => $participant->toArray(),
				$this->departments,
			),
		];
	}
}
