<?php
declare(strict_types=1);

namespace Bitrix\Sign\Item\Api\Rest\SignDocument;

/**
 * Class SignMemberData
 *
 * Represents a signer/member in request payloads.
 */
class SignMemberData
{
	public ?string $employeeCode = null;
	public ?int $employeeId = null;
	public ?int $userId = null;

	public function __construct(?string $employeeCode = null, ?int $employeeId = null, ?int $userId = null)
	{
		$this->employeeCode = $employeeCode;
		$this->employeeId = $employeeId;
		$this->userId = $userId;
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

		if (array_key_exists('employeeCode', $data))
		{
			$self->employeeCode = $data['employeeCode'] === null ? null : (string)$data['employeeCode'];
		}

		if (array_key_exists('employeeId', $data))
		{
			$self->employeeId = $data['employeeId'] === null ? null : (int)$data['employeeId'];
		}

		if (array_key_exists('userId', $data))
		{
			$self->userId = $data['userId'] === null ? null : (int)$data['userId'];
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
			'employeeCode' => $this->employeeCode,
			'employeeId' => $this->employeeId,
			'userId' => $this->userId,
		];
	}
}
