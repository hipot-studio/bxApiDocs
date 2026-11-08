<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Sharing;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Vibecodeconnector\Internal\Dto\Catalog\Sharing\CatalogLinkState;
use Bitrix\Vibecodeconnector\Internal\Dto\Catalog\Sharing\CatalogShareParticipant;
use Bitrix\Vibecodeconnector\Internal\Dto\Catalog\Sharing\CatalogShareState;

final class CatalogSharingResponseMapper
{
	public const ERROR_PLATFORM_UNAVAILABLE = 'SHARE_PLATFORM_UNAVAILABLE';
	public const ERROR_PLATFORM_INVALID_RESPONSE = 'SHARE_PLATFORM_INVALID_RESPONSE';

	private const LINK_CREATED_VIA = 'BITRIX24_PORTAL';
	private const DEPARTMENT_ACCESS_CODE_PATTERN = '/^DR?[1-9][0-9]*$/D';

	private const AUDIENCES = [
		CatalogShareState::AUDIENCE_OWNER_ONLY,
		CatalogShareState::AUDIENCE_SPECIFIC_MEMBERS,
		CatalogShareState::AUDIENCE_PORTAL,
		CatalogShareState::AUDIENCE_AUTHENTICATED,
		CatalogShareState::AUDIENCE_PUBLIC,
	];

	private const PLATFORM_ERROR_CODES = [
		'INVALID_PAYLOAD',
		'EMPTY_PARTICIPANTS',
		'NOT_REGISTERED',
		'PORTAL_SUSPENDED',
		'FEATURE_DISABLED',
		'LINK_FEATURE_DISABLED',
		'CATALOG_ITEM_NOT_FOUND',
		'SERVER_NOT_SHAREABLE',
		'LINK_UNAVAILABLE_FOR_AUDIENCE',
		'INVALID_TTL',
		'NAME_TOO_LONG',
		'SERVER_NOT_FOUND',
		'TOKEN_OWNER_MISMATCH',
		'AGENT_OWNER_ONLY',
		'ACTIVE_TOKEN_LIMIT',
		'TOKEN_MINT_RATE_LIMIT',
		'SERVER_NO_SUBDOMAIN',
		'TOKEN_EXPIRED',
		'NOT_FOUND',
		'WRONG_TOKEN_MODE',
		'ALREADY_REVOKED',
		'INTERNAL_ERROR',
	];

	private const TRANSPORT_ERROR_CODES = [
		'MULTIPART',
		'NETWORK',
		'REDIRECT',
		'URI_SCHEME',
		'URI_HOST',
		'URI_PUNICODE',
		'PRIVATE_IP',
	];

	private const INVALID_RESPONSE_ERROR_CODES = [
		'WRONG_SERVER_RESPONSE',
		'EMPTY_SERVER_RESPONSE',
		'INVALID_RESPONSE',
	];

	public function mapShare(Result $response): Result
	{
		if (!$response->isSuccess())
		{
			return $this->mapFailure($response);
		}

		$data = $response->getData();
		$audience = $data['audience'] ?? null;
		if (!is_string($audience) || !in_array($audience, self::AUDIENCES, true))
		{
			return $this->invalidResponse();
		}

		if (!array_key_exists('users', $data) || !array_key_exists('departments', $data))
		{
			return $this->invalidResponse();
		}

		if (($data['department_format'] ?? null) !== CatalogShareState::DEPARTMENT_FORMAT_ACCESS_CODE)
		{
			return $this->invalidResponse();
		}

		$users = $this->mapParticipants($data['users']);
		$departments = $this->mapParticipants($data['departments']);
		if (
			$users === null
			|| $departments === null
			|| !$this->areDepartmentAccessCodes($departments)
		)
		{
			return $this->invalidResponse();
		}

		$hasParticipants = $users !== [] || $departments !== [];
		if (
			($audience === CatalogShareState::AUDIENCE_SPECIFIC_MEMBERS && !$hasParticipants)
			|| ($audience !== CatalogShareState::AUDIENCE_SPECIFIC_MEMBERS && $hasParticipants)
		)
		{
			return $this->invalidResponse();
		}

		$result = new Result();
		$result->setData([
			'shareState' => new CatalogShareState($audience, $users, $departments),
		]);

		return $result;
	}

	/**
	 * @param list<CatalogShareParticipant> $departments
	 */
	private function areDepartmentAccessCodes(array $departments): bool
	{
		foreach ($departments as $department)
		{
			if (preg_match(self::DEPARTMENT_ACCESS_CODE_PATTERN, $department->id) !== 1)
			{
				return false;
			}
		}

		return true;
	}

	public function mapLink(Result $response): Result
	{
		if (!$response->isSuccess())
		{
			return $this->mapFailure($response);
		}

		$data = $response->getData();
		$availability = $data['availability'] ?? null;
		if (!is_string($availability) || !in_array($availability, [
			CatalogLinkState::AVAILABILITY_AVAILABLE,
			CatalogLinkState::AVAILABILITY_UNAVAILABLE,
		], true))
		{
			return $this->invalidResponse();
		}

		if (!array_key_exists('unavailable_reason', $data) || !array_key_exists('link', $data))
		{
			return $this->invalidResponse();
		}

		if ($availability === CatalogLinkState::AVAILABILITY_UNAVAILABLE)
		{
			return $this->mapUnavailableLink($data);
		}

		return $this->mapAvailableLink($data);
	}

	/**
	 * @return list<CatalogShareParticipant>|null
	 */
	private function mapParticipants(mixed $value): ?array
	{
		if (!is_array($value) || !array_is_list($value))
		{
			return null;
		}

		$participants = [];
		foreach ($value as $row)
		{
			if (!is_array($row) || !array_key_exists('id', $row) || !array_key_exists('name', $row))
			{
				return null;
			}

			$id = $this->normalizeParticipantId($row['id']);
			if ($id === null || !is_string($row['name']))
			{
				return null;
			}

			$participants[] = new CatalogShareParticipant($id, $row['name']);
		}

		return $participants;
	}

	private function normalizeParticipantId(mixed $id): ?string
	{
		if (is_int($id) && $id > 0)
		{
			return (string)$id;
		}

		if (!is_string($id) || trim($id) === '')
		{
			return null;
		}

		return $id;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function mapUnavailableLink(array $data): Result
	{
		if (
			$data['unavailable_reason'] !== CatalogLinkState::UNAVAILABLE_REASON_DIRECT_GLOBAL_ACCESS
			|| $data['link'] !== null
		)
		{
			return $this->invalidResponse();
		}

		return $this->linkResult(new CatalogLinkState(
			availability: CatalogLinkState::AVAILABILITY_UNAVAILABLE,
			unavailableReason: CatalogLinkState::UNAVAILABLE_REASON_DIRECT_GLOBAL_ACCESS,
			url: null,
			expiresAt: null,
			requireB24Auth: null,
		));
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function mapAvailableLink(array $data): Result
	{
		if ($data['unavailable_reason'] !== null)
		{
			return $this->invalidResponse();
		}

		if ($data['link'] === null)
		{
			return $this->linkResult(new CatalogLinkState(
				availability: CatalogLinkState::AVAILABILITY_AVAILABLE,
				unavailableReason: null,
				url: null,
				expiresAt: null,
				requireB24Auth: null,
			));
		}

		$link = $data['link'];
		if (!is_array($link))
		{
			return $this->invalidResponse();
		}

		$url = $link['url'] ?? null;
		$expiresAt = $link['expires_at'] ?? null;
		$requireB24Auth = $link['require_b24_auth'] ?? null;
		$createdVia = $link['created_via'] ?? null;
		if (
			!array_key_exists('expires_at', $link)
			|| !is_string($url)
			|| $url === ''
			|| filter_var($url, FILTER_VALIDATE_URL) === false
			|| ($expiresAt !== null && (!is_string($expiresAt) || !$this->isUtcDateTime($expiresAt)))
			|| !is_bool($requireB24Auth)
			|| $createdVia !== self::LINK_CREATED_VIA
		)
		{
			return $this->invalidResponse();
		}

		return $this->linkResult(new CatalogLinkState(
			availability: CatalogLinkState::AVAILABILITY_AVAILABLE,
			unavailableReason: null,
			url: $url,
			expiresAt: $expiresAt,
			requireB24Auth: $requireB24Auth,
		));
	}

	private function isUtcDateTime(string $value): bool
	{
		if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $value) !== 1)
		{
			return false;
		}

		$date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $value);

		return $date !== false && $date->format('Y-m-d\TH:i:s.v\Z') === $value;
	}

	private function mapFailure(Result $response): Result
	{
		$errors = $response->getErrors();
		if ($errors === [])
		{
			return $this->invalidResponse();
		}

		$codes = array_map(static fn(Error $error): string => (string)$error->getCode(), $errors);
		if (array_diff($codes, self::PLATFORM_ERROR_CODES) === [])
		{
			$result = new Result();
			foreach ($codes as $code)
			{
				$result->addError(new Error('Sharing operation was rejected by the platform.', $code));
			}

			return $result;
		}

		if (array_intersect($codes, self::TRANSPORT_ERROR_CODES) !== [])
		{
			return $this->platformUnavailable();
		}

		if (array_intersect($codes, self::INVALID_RESPONSE_ERROR_CODES) !== [] || in_array('', $codes, true))
		{
			return $this->invalidResponse();
		}

		return $this->invalidResponse();
	}

	private function linkResult(CatalogLinkState $state): Result
	{
		$result = new Result();
		$result->setData(['linkState' => $state]);

		return $result;
	}

	private function platformUnavailable(): Result
	{
		$result = new Result();
		$result->addError(new Error(
			'Sharing platform is temporarily unavailable.',
			self::ERROR_PLATFORM_UNAVAILABLE,
		));

		return $result;
	}

	private function invalidResponse(): Result
	{
		$result = new Result();
		$result->addError(new Error(
			'Sharing platform returned an invalid response.',
			self::ERROR_PLATFORM_INVALID_RESPONSE,
		));

		return $result;
	}
}
