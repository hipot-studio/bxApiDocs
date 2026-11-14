<?php

namespace Bitrix\Call\Controller;

use Bitrix\Call\Settings;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Web\JWT;

/**
 * Reads and verifies the jwt of a server-to-server request to a JwtController action.
 *
 * Shared by the prefilter that rejects the request and by the autowiring that builds the action DTO,
 * so both of them see the same token and the same verdict on it.
 *
 * @internal
 */
final class JwtReader
{
	/**
	 * Takes the jwt the same way the action binder does: from the first source that has it,
	 * falling back to the raw JSON body.
	 *
	 * @param array $sourceParametersList {@see \Bitrix\Main\Engine\Controller::getSourceParametersList()}
	 */
	public function read(array $sourceParametersList): string
	{
		foreach ($sourceParametersList as $source)
		{
			if (isset($source['jwt']))
			{
				if (is_string($source['jwt']) && $source['jwt'] !== '')
				{
					return $source['jwt'];
				}
				break;
			}
		}

		return $this->readFromJsonBody();
	}

	/**
	 * @return object|array|null The payload, an array when it was signed as a JSON array, or null when the token
	 * is not signed with the portal key or is malformed.
	 */
	public function decode(string $jwt): object|array|null
	{
		// Read outside the try, so that an exception from reading the key is not taken for a bad token.
		// An empty key is not such an exception: the library refuses it inside the try, as a bad token.
		$key = base64_decode(Settings::getPrivateKey());

		try
		{
			return JWT::decode($jwt, $key, ['HS256']);
		}
		// A malformed header, such as a non-scalar alg, makes the library fail with a TypeError.
		catch (\Throwable $e)
		{
			return null;
		}
	}

	private function readFromJsonBody(): string
	{
		$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
		if (!str_contains($contentType, 'application/json'))
		{
			return '';
		}

		try
		{
			$body = (string)file_get_contents('php://input');
			$decoded = $body !== '' ? Json::decode($body) : null;
			if (is_array($decoded) && isset($decoded['jwt']) && is_string($decoded['jwt']))
			{
				return $decoded['jwt'];
			}
		}
		catch (\Throwable $e) {}

		return '';
	}
}
