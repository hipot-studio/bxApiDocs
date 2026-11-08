<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Contact;

final class ContactCard
{
	private const MAX_NAME_LENGTH = 255;
	private const MAX_PHONE_LENGTH = 50;

	/**
	 * @param string[] $phones
	 */
	private function __construct(
		private readonly ?string $firstName,
		private readonly ?string $lastName,
		private readonly array $phones,
		private readonly ?int $photoId,
		private readonly ?string $displayName,
		private readonly ?string $organization,
	) {}

	public static function fromArray(array $data): ?self
	{
		if (!array_key_exists('phones', $data) || !is_array($data['phones']) || !array_is_list($data['phones']))
		{
			return null;
		}

		foreach ($data['phones'] as $phone)
		{
			if (!is_string($phone) || mb_strlen($phone) > self::MAX_PHONE_LENGTH)
			{
				return null;
			}
		}

		$textFields = [];
		foreach (['firstName', 'lastName', 'displayName', 'organization'] as $field)
		{
			if (!array_key_exists($field, $data))
			{
				$textFields[$field] = null;

				continue;
			}

			if (!is_string($data[$field]))
			{
				return null;
			}

			$value = trim($data[$field]);
			if (mb_strlen($value) > self::MAX_NAME_LENGTH)
			{
				return null;
			}

			$textFields[$field] = $value !== '' ? $value : null;
		}

		$photoId = $data['photoId'] ?? null;
		if (is_string($photoId))
		{
			if (preg_match('/^[1-9][0-9]*$/D', $photoId) !== 1)
			{
				return null;
			}

			$normalizedPhotoId = (int)$photoId;
			if ((string)$normalizedPhotoId !== $photoId)
			{
				return null;
			}

			$photoId = $normalizedPhotoId;
		}

		if ($photoId !== null && (!is_int($photoId) || $photoId <= 0))
		{
			return null;
		}

		return new self(
			firstName: $textFields['firstName'],
			lastName: $textFields['lastName'],
			phones: $data['phones'],
			photoId: $photoId,
			displayName: $textFields['displayName'],
			organization: $textFields['organization'],
		);
	}

	public function getFirstName(): ?string
	{
		return $this->firstName;
	}

	public function getLastName(): ?string
	{
		return $this->lastName;
	}

	/**
	 * @return string[]
	 */
	public function getPhones(): array
	{
		return $this->phones;
	}

	public function getPhotoId(): ?int
	{
		return $this->photoId;
	}

	public function getDisplayName(): ?string
	{
		return $this->displayName;
	}

	public function getOrganization(): ?string
	{
		return $this->organization;
	}
}
