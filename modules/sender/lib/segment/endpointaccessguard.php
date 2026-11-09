<?php

namespace Bitrix\Sender\Segment;

use Bitrix\Main;
use Bitrix\Sender\Access\Service\SegmentConnectorAccessService;
use Bitrix\Sender\Entity;
use Bitrix\Sender\Security\AccessChecker;

/**
 * Applies the rule of SegmentConnectorAccessService to the sources a request reads or writes.
 */
final class EndpointAccessGuard
{
	public function __construct(private SegmentConnectorAccessService $access)
	{
	}

	public static function forUser(int $userId): self
	{
		return new self(new SegmentConnectorAccessService($userId));
	}

	public function checkRead(array $endpoints): Main\Result
	{
		$result = new Main\Result();
		foreach ($endpoints as $endpoint)
		{
			if (!is_array($endpoint) || !$this->access->isEndpointAllowed($endpoint))
			{
				return $result->addError(self::createAccessError());
			}
		}

		return $result;
	}

	/**
	 * A forbidden source passes only unchanged, and then its saved copy is written, so no
	 * inaccuracy of the comparison lets a change through. Saved sources of a kind the form
	 * does not draw for the user are kept even though the post lacks them.
	 */
	private function prepareForWrite(array $savedEndpoints, array $incomingEndpoints): Main\Result
	{
		$result = new Main\Result();
		$endpoints = [];
		$endpointsBySentKey = [];
		$matchedSavedKeys = [];

		foreach ($incomingEndpoints as $sentKey => $endpoint)
		{
			if (!is_array($endpoint))
			{
				return $result->addError(self::createAccessError());
			}

			if ($this->access->isEndpointAllowed($endpoint))
			{
				$endpoints[] = $endpointsBySentKey[$sentKey] = $endpoint;
				continue;
			}

			$savedKey = $this->findUnchangedSaved($savedEndpoints, $endpoint, $matchedSavedKeys);
			if ($savedKey === null)
			{
				return $result->addError(self::createAccessError());
			}

			$matchedSavedKeys[$savedKey] = true;
			$endpoints[] = $endpointsBySentKey[$sentKey] = $savedEndpoints[$savedKey];
		}

		foreach ($savedEndpoints as $key => $saved)
		{
			if (
				isset($matchedSavedKeys[$key])
				|| !is_array($saved)
				|| $this->access->canUseConnector((string)($saved['CODE'] ?? ''))
			)
			{
				continue;
			}

			$endpoints[] = $saved;
		}

		return $result->setData(['ENDPOINTS' => $endpoints, 'ENDPOINTS_BY_SENT_KEY' => $endpointsBySentKey]);
	}

	/**
	 * @return Main\Result With the sources as written in 'ENDPOINTS' and, for each sent source,
	 * the one written in its place in 'ENDPOINTS_BY_SENT_KEY' under the key it was sent under.
	 */
	public function save(Entity\Segment $segment, array $data): Main\Result
	{
		$savedEndpoints = $segment->getData()['ENDPOINTS'] ?? [];
		$prepared = $this->prepareForWrite($savedEndpoints, $data['ENDPOINTS'] ?? []);
		if (!$prepared->isSuccess())
		{
			return $prepared;
		}

		$result = new Main\Result();
		$segment->mergeData(['ENDPOINTS' => $prepared->getData()['ENDPOINTS']] + $data)->save();
		$result->addErrors($segment->getErrors());

		return $result->setData($prepared->getData());
	}

	/**
	 * Whether save() would take the sent source for one of the saved ones, so a caller can leave
	 * such a source as sent instead of completing it.
	 */
	public function isUnchangedSaved(array $savedEndpoints, array $endpoint): bool
	{
		return $this->findUnchangedSaved($savedEndpoints, $endpoint, []) !== null;
	}

	/**
	 * A saved source of a system segment has no filter identifier, while the form sends it under
	 * one of its own, so such a source is matched by its kind and fields only.
	 */
	private function findUnchangedSaved(array $savedEndpoints, array $endpoint, array $matchedSavedKeys): int|string|null
	{
		$filterId = (string)($endpoint['FILTER_ID'] ?? '');
		$fields = EndpointFields::normalize($endpoint['FIELDS'] ?? null);
		$fallbackKey = null;
		foreach ($savedEndpoints as $key => $saved)
		{
			if (
				isset($matchedSavedKeys[$key])
				|| !is_array($saved)
				|| (string)($saved['CODE'] ?? '') !== (string)($endpoint['CODE'] ?? '')
				|| (string)($saved['MODULE_ID'] ?? '') !== (string)($endpoint['MODULE_ID'] ?? '')
				|| EndpointFields::normalize($saved['FIELDS'] ?? null) !== $fields
			)
			{
				continue;
			}

			$savedFilterId = (string)($saved['FILTER_ID'] ?? '');
			if ($filterId !== '' && $savedFilterId === $filterId)
			{
				return $key;
			}

			if ($savedFilterId === '')
			{
				$fallbackKey ??= $key;
			}
		}

		return $fallbackKey;
	}

	private static function createAccessError(): Main\Error
	{
		return new Main\Error(
			AccessChecker::getMessage(AccessChecker::ERR_CODE_EDIT),
			AccessChecker::ERR_CODE_EDIT
		);
	}
}
