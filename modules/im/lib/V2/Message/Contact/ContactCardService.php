<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Message\Contact;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Message\Params;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\FileService;

final class ContactCardService
{
	private const MAX_PHONE_COUNT = 10;
	private const MIN_PHONE_DIGIT_COUNT = 5;

	private FileService $fileService;

	public function __construct(?FileService $fileService = null)
	{
		$this->fileService = $fileService ?? new FileService();
	}

	public function prepare(array $contact, Chat $chat, ?string $messageText): Result
	{
		$result = new Result();
		$card = ContactCard::fromArray($contact);
		if ($card === null)
		{
			return $result->addError(new ContactCardError(ContactCardError::INVALID));
		}

		$phones = $this->getUsablePhones($card->getPhones());
		if (empty($phones))
		{
			return $result->addError(new ContactCardError(ContactCardError::NO_PHONE));
		}
		[$firstName, $lastName] = $this->getStructuredNameParts($card);

		$params = [
			Params::COMPONENT_ID => ContactMessage::COMPONENT_ID,
			Params::COMPONENT_PARAMS => [
				'FIRST_NAME' => $firstName,
				'LAST_NAME' => $lastName,
				'PHONES' => $phones,
			],
		];

		$photoId = $card->getPhotoId();
		if ($photoId !== null)
		{
			$fileResult = $this->fileService->attachImageToChat($chat, $photoId);
			if (!$fileResult->isSuccess())
			{
				return $result->addError(new ContactCardError(ContactCardError::PHOTO_NOT_IN_CHAT));
			}

			$params[Params::FILE_ID] = [$photoId];
		}

		$cardLine = $this->buildCardLine($firstName, $lastName, $phones[0]);
		$userText = trim($messageText ?? '');

		return $result->setResult([
			'MESSAGE' => $this->buildMessage($userText, $cardLine),
			'PARAMS' => $params,
		]);
	}

	private function buildMessage(string $userText, string $cardLine): string
	{
		if ($userText === '')
		{
			return $cardLine;
		}

		$captionLimit = \CIMMessenger::MESSAGE_LIMIT - mb_strlen($cardLine) - 1;
		$caption = mb_substr($userText, 0, max(0, $captionLimit));

		return $caption === '' ? $cardLine : $caption . "\n" . $cardLine;
	}

	/**
	 * @param string[] $phones
	 * @return string[]
	 */
	private function getUsablePhones(array $phones): array
	{
		$usablePhones = [];
		foreach ($phones as $phone)
		{
			if (preg_match_all('/[0-9]/', $phone) >= self::MIN_PHONE_DIGIT_COUNT)
			{
				$usablePhones[] = $phone;
			}
		}

		return array_slice($usablePhones, 0, self::MAX_PHONE_COUNT);
	}

	/**
	 * @return array{0: ?string, 1: ?string}
	 */
	private function getStructuredNameParts(ContactCard $card): array
	{
		$firstName = $card->getFirstName();
		$lastName = $card->getLastName();
		if ($firstName === null && $lastName === null)
		{
			$firstName = $card->getDisplayName() ?? $card->getOrganization();
		}

		return [$firstName, $lastName];
	}

	private function buildCardLine(?string $firstName, ?string $lastName, string $phone): string
	{
		$name = implode(' ', array_filter(
			[$lastName, $firstName],
			static fn (?string $part): bool => $part !== null && $part !== '',
		));

		return $name === '' ? $phone : $name . ', ' . $phone;
	}
}
