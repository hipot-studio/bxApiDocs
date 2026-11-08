<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Sharing;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Vibecodeconnector\Internal\Dto\Catalog\Sharing\CatalogLinkState;
use Bitrix\Vibecodeconnector\Internal\Dto\Catalog\Sharing\CatalogShareParticipant;
use Bitrix\Vibecodeconnector\Internal\Dto\Catalog\Sharing\CatalogShareState;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItemType;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Vibecode\CatalogItemSender;

final class CatalogSharingService
{
	public const ERROR_ACCESS_DENIED = 'SHARE_ACCESS_DENIED';
	public const ERROR_ITEM_NOT_FOUND = 'SHARE_ITEM_NOT_FOUND';
	public const ERROR_ITEM_INACTIVE = 'SHARE_ITEM_INACTIVE';
	public const ERROR_ITEM_NOT_APPLICATION = 'SHARE_ITEM_NOT_APPLICATION';
	public const ERROR_INVALID_PAYLOAD = 'SHARE_INVALID_PAYLOAD';

	private const MAX_PARTICIPANTS_PER_TYPE = 200;
	private const MAX_PARTICIPANT_ID_LENGTH = 128;
	private const MAX_PARTICIPANT_NAME_LENGTH = 255;
	private const MIN_LINK_EXPIRY_OFFSET_SECONDS = 300;
	private const MAX_LINK_EXPIRY_OFFSET_SECONDS = 315_360_000;

	private const AUDIENCES = [
		CatalogShareState::AUDIENCE_OWNER_ONLY,
		CatalogShareState::AUDIENCE_SPECIFIC_MEMBERS,
		CatalogShareState::AUDIENCE_PORTAL,
		CatalogShareState::AUDIENCE_AUTHENTICATED,
		CatalogShareState::AUDIENCE_PUBLIC,
	];

	private readonly \Closure $nowProvider;

	public function __construct(
		private readonly CatalogItemRepository $itemRepository = new CatalogItemRepository(),
		private readonly CatalogItemSender $sender = new CatalogItemSender(),
		?\Closure $nowProvider = null,
		private readonly ShareDepartmentCodes $departmentCodes = new ShareDepartmentCodes(),
	) {
		$this->nowProvider = $nowProvider ?? static fn(): int => time();
	}

	public function getShare(int $catalogItemId, int $userId, bool $isAdmin): Result
	{
		$itemResult = $this->resolveItem($catalogItemId, $userId, $isAdmin);
		if (!$itemResult->isSuccess())
		{
			return $itemResult;
		}

		/** @var CatalogItem $item */
		$item = $itemResult->getData()['item'];

		try
		{
			$response = $this->sender->getShare($item);
		}
		catch (\Throwable)
		{
			return $this->platformUnavailable();
		}

		return $this->mapShareResult($response);
	}

	public function getLink(int $catalogItemId, int $userId, bool $isAdmin): Result
	{
		$itemResult = $this->resolveItem($catalogItemId, $userId, $isAdmin);
		if (!$itemResult->isSuccess())
		{
			return $itemResult;
		}

		/** @var CatalogItem $item */
		$item = $itemResult->getData()['item'];

		try
		{
			$response = $this->sender->getLink($item);
		}
		catch (\Throwable)
		{
			return $this->platformUnavailable();
		}

		if (!$response->isSuccess())
		{
			return (new Result())->addErrors($response->getErrors());
		}

		$state = $response->getData()['linkState'] ?? null;
		if (!$state instanceof CatalogLinkState)
		{
			return $this->invalidPlatformResponse();
		}

		$result = new Result();
		$result->setData(['linkState' => $state->toArray()]);

		return $result;
	}

	public function setShare(
		int $catalogItemId,
		int $userId,
		bool $isAdmin,
		mixed $audience,
		mixed $users,
		mixed $departments,
	): Result {
		$itemResult = $this->resolveItem($catalogItemId, $userId, $isAdmin);
		if (!$itemResult->isSuccess())
		{
			return $itemResult;
		}

		$normalizedUsers = $this->normalizeParticipants($users);
		$normalizedDepartments = $this->normalizeParticipants($departments);
		$departmentAccessCodes = $normalizedDepartments === null
			? null
			: $this->dialogIdsToAccessCodes($normalizedDepartments);
		if (
			!is_string($audience)
			|| !in_array($audience, self::AUDIENCES, true)
			|| $normalizedUsers === null
			|| $departmentAccessCodes === null
			|| !$this->isValidAudienceState($audience, $normalizedUsers, $departmentAccessCodes)
		)
		{
			return $this->invalidPayload();
		}

		/** @var CatalogItem $item */
		$item = $itemResult->getData()['item'];

		// Verify the platform contract before writing department access codes after a platform rollback.
		if ($departmentAccessCodes !== [])
		{
			$formatResult = $this->resolvePlatformDepartmentFormat($item);
			if (!$formatResult->isSuccess())
			{
				return $formatResult;
			}
		}

		try
		{
			$response = $this->sender->setShare(
				$item,
				$userId,
				$audience,
				$normalizedUsers,
				$departmentAccessCodes,
			);
		}
		catch (\Throwable)
		{
			return $this->platformUnavailable();
		}

		return $this->mapShareResult($response);
	}

	public function setLink(
		int $catalogItemId,
		int $userId,
		bool $isAdmin,
		mixed $enabled,
		mixed $expiresAt = null,
		mixed $requireB24Auth = null,
	): Result {
		$itemResult = $this->resolveItem($catalogItemId, $userId, $isAdmin);
		if (!$itemResult->isSuccess())
		{
			return $itemResult;
		}

		if (!is_bool($enabled) || !$this->isValidLinkState($enabled, $expiresAt, $requireB24Auth))
		{
			return $this->invalidPayload();
		}

		/** @var CatalogItem $item */
		$item = $itemResult->getData()['item'];

		try
		{
			$response = $this->sender->setLink(
				$item,
				$userId,
				$enabled,
				$expiresAt,
				$requireB24Auth,
			);
		}
		catch (\Throwable)
		{
			return $this->platformUnavailable();
		}

		return $this->mapLinkResult($response);
	}

	/**
	 * @return list<array{id: string, name: string}>|null
	 */
	private function normalizeParticipants(mixed $participants): ?array
	{
		if (
			!is_array($participants)
			|| !array_is_list($participants)
			|| count($participants) > self::MAX_PARTICIPANTS_PER_TYPE
		)
		{
			return null;
		}

		$normalized = [];
		$seen = [];
		foreach ($participants as $participant)
		{
			if (
				!is_array($participant)
				|| count($participant) !== 2
				|| !array_key_exists('id', $participant)
				|| !array_key_exists('name', $participant)
				|| !is_string($participant['name'])
				|| mb_strlen($participant['name']) > self::MAX_PARTICIPANT_NAME_LENGTH
			)
			{
				return null;
			}

			$id = $this->normalizeParticipantId($participant['id']);
			if ($id === null)
			{
				return null;
			}

			if (isset($seen[$id]))
			{
				continue;
			}

			$seen[$id] = true;
			$normalized[] = [
				'id' => $id,
				'name' => $participant['name'],
			];
		}

		return $normalized;
	}

	private function normalizeParticipantId(mixed $id): ?string
	{
		if (is_int($id))
		{
			return $id > 0 ? (string)$id : null;
		}

		if (!is_string($id) || trim($id) === '' || mb_strlen($id) > self::MAX_PARTICIPANT_ID_LENGTH)
		{
			return null;
		}

		return $id;
	}

	private function resolvePlatformDepartmentFormat(CatalogItem $item): Result
	{
		try
		{
			$response = $this->sender->getShare($item);
		}
		catch (\Throwable)
		{
			return $this->platformUnavailable();
		}

		if (!$response->isSuccess())
		{
			return (new Result())->addErrors($response->getErrors());
		}

		return new Result();
	}

	/**
	 * @param list<array{id: string, name: string}> $departments
	 * @return list<array{id: string, name: string}>|null
	 */
	private function dialogIdsToAccessCodes(array $departments): ?array
	{
		$converted = [];
		foreach ($departments as $department)
		{
			$code = $this->departmentCodes->dialogIdToAccessCode($department['id']);
			if ($code === null)
			{
				return null;
			}

			$converted[] = [
				'id' => $code,
				'name' => $department['name'],
			];
		}

		return $converted;
	}

	/**
	 * @return array{
	 *     audience: string,
	 *     users: list<array{id: string, name: string}>,
	 *     departments: list<array{id: string, name: string}>
	 * }|null
	 */
	private function toDialogShare(CatalogShareState $state): ?array
	{
		$departments = [];
		foreach ($state->departments as $department)
		{
			$dialogId = $this->departmentCodes->accessCodeToDialogId($department->id);
			if ($dialogId === null)
			{
				return null;
			}

			$departments[] = (new CatalogShareParticipant($dialogId, $department->name))->toArray();
		}

		$share = $state->toArray();
		$share['departments'] = $departments;

		return $share;
	}

	/**
	 * @param list<array{id: string, name: string}> $users
	 * @param list<array{id: string, name: string}> $departments
	 */
	private function isValidAudienceState(string $audience, array $users, array $departments): bool
	{
		$hasParticipants = $users !== [] || $departments !== [];

		return $audience === CatalogShareState::AUDIENCE_SPECIFIC_MEMBERS
			? $hasParticipants
			: !$hasParticipants;
	}

	private function isValidLinkState(bool $enabled, mixed $expiresAt, mixed $requireB24Auth): bool
	{
		if (!$enabled)
		{
			return $expiresAt === null && $requireB24Auth === null;
		}

		return is_bool($requireB24Auth)
			&& (
				$expiresAt === null
				|| (is_string($expiresAt) && $this->isValidLinkExpiry($expiresAt))
			);
	}

	private function isValidLinkExpiry(string $expiresAt): bool
	{
		$matched = preg_match(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.(?<fraction>\d{1,9}))?(?:Z|[+-]\d{2}:\d{2})$/D',
			$expiresAt,
			$matches,
		);
		if ($matched !== 1)
		{
			return false;
		}

		try
		{
			$expiry = new \DateTimeImmutable($expiresAt);
		}
		catch (\Throwable)
		{
			return false;
		}

		$parseErrors = \DateTimeImmutable::getLastErrors();
		if (
			$parseErrors !== false
			&& ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0)
		)
		{
			return false;
		}

		$now = ($this->nowProvider)();
		if (!is_int($now))
		{
			return false;
		}

		$fraction = str_pad((string)($matches['fraction'] ?? ''), 9, '0');
		$offsetNanoseconds
			= ($expiry->getTimestamp() - $now) * 1_000_000_000
			+ (int)$fraction;

		return $offsetNanoseconds >= self::MIN_LINK_EXPIRY_OFFSET_SECONDS * 1_000_000_000
			&& $offsetNanoseconds <= self::MAX_LINK_EXPIRY_OFFSET_SECONDS * 1_000_000_000;
	}

	private function mapShareResult(Result $response): Result
	{
		if (!$response->isSuccess())
		{
			return (new Result())->addErrors($response->getErrors());
		}

		$state = $response->getData()['shareState'] ?? null;
		$share = $state instanceof CatalogShareState ? $this->toDialogShare($state) : null;
		if ($share === null)
		{
			return $this->invalidPlatformResponse();
		}

		$result = new Result();
		$result->setData(['share' => $share]);

		return $result;
	}

	private function mapLinkResult(Result $response): Result
	{
		if (!$response->isSuccess())
		{
			return (new Result())->addErrors($response->getErrors());
		}

		$state = $response->getData()['linkState'] ?? null;
		if (!$state instanceof CatalogLinkState)
		{
			return $this->invalidPlatformResponse();
		}

		$result = new Result();
		$result->setData(['linkState' => $state->toArray()]);

		return $result;
	}

	private function resolveItem(int $catalogItemId, int $userId, bool $isAdmin): Result
	{
		if ($catalogItemId <= 0)
		{
			return $this->error('Catalog item id is invalid.', self::ERROR_INVALID_PAYLOAD);
		}

		$item = $this->itemRepository->getById($catalogItemId);
		if ($item === null)
		{
			return $this->error('Catalog item was not found.', self::ERROR_ITEM_NOT_FOUND);
		}

		if ($userId <= 0 || (!$isAdmin && $item->getOwnerId() !== $userId))
		{
			return $this->error('User cannot share this catalog item.', self::ERROR_ACCESS_DENIED);
		}

		if ($item->isDeactivated())
		{
			return $this->error('Catalog item is inactive.', self::ERROR_ITEM_INACTIVE);
		}

		if ($item->getType() !== CatalogItemType::Application)
		{
			return $this->error('Catalog item is not an application.', self::ERROR_ITEM_NOT_APPLICATION);
		}

		$result = new Result();
		$result->setData(['item' => $item]);

		return $result;
	}

	private function error(string $message, string $code): Result
	{
		$result = new Result();
		$result->addError(new Error($message, $code));

		return $result;
	}

	private function platformUnavailable(): Result
	{
		return $this->error(
			'Sharing platform is temporarily unavailable.',
			CatalogSharingResponseMapper::ERROR_PLATFORM_UNAVAILABLE,
		);
	}

	private function invalidPayload(): Result
	{
		return $this->error('Sharing payload is invalid.', self::ERROR_INVALID_PAYLOAD);
	}

	private function invalidPlatformResponse(): Result
	{
		return $this->error(
			'Sharing platform returned an invalid response.',
			CatalogSharingResponseMapper::ERROR_PLATFORM_INVALID_RESPONSE,
		);
	}
}
