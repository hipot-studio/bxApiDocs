<?php
declare(strict_types=1);

namespace Bitrix\Sign\Item\Api\Rest\SignDocument;

/**
 * Class SignDocumentMember
 *
 * Represents a member entry in a signed document response.
 */
class SignDocumentMember
{
	/** @var string|null UID of the member */
	public ?string $uid = null;

	/** @var string|null role of the member */
	public ?string $role = null;

	/** @var int|null party of the member */
	public ?int $party = null;

	/** @var SignMemberData|null User data for the member */
	public ?SignMemberData $user = null;

	/** @var SignMemberState|null State of the member */
	public ?SignMemberState $state = null;

	public function __construct(
		?string $uid = null,
		?string $role = null,
		?int $party = null,
		?SignMemberData $user = null,
		?SignMemberState $state = null
	)
	{
		$this->uid = $uid;
		$this->role = $role;
		$this->party = $party;
		$this->user = $user;
		$this->state = $state;
	}

	/**
	 * Create instance from associative array.
	 *
	 * @param array $data
	 * @return self
	 */
	public static function fromArray(array $data): self
	{
		$self = new self();

		if (array_key_exists('uid', $data))
		{
			$self->uid = $data['uid'] === null ? null : (string)$data['uid'];
		}

		if (array_key_exists('role', $data))
		{
			$self->role = $data['role'] === null ? null : (string)$data['role'];
		}

		if (array_key_exists('party', $data))
		{
			$self->party = $data['party'] === null ? null : (int)$data['party'];
		}

		if (array_key_exists('user', $data) && is_array($data['user']))
		{
			$self->user = SignMemberData::fromArray($data['user']);
		}

		if (array_key_exists('state', $data) && is_array($data['state']))
		{
			$self->state = SignMemberState::fromArray($data['state']);
		}

		return $self;
	}

	/**
	 * Convert object to array
	 *
	 * @return array
	 */
	public function toArray(): array
	{
		return [
			'uid' => $this->uid,
			'role' => $this->role,
			'party' => $this->party,
			'user' => $this->user?->toArray(),
			'state' => $this->state?->toArray(),
		];
	}

}