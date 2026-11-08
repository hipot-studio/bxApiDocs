<?php

namespace Bitrix\Vote\Service;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\Main\Web\Json;

class VotedContextSigner
{
	public const SIGN_SALT = 'vote.voted.context';

	public function sign(int $voteId, int $answerId, string $nameTemplate = '', string $urlTemplate = ''): string
	{
		$payload = base64_encode(Json::encode([
			'voteId' => $voteId,
			'answerId' => $answerId,
			'nameTemplate' => $nameTemplate,
			'urlTemplate' => $urlTemplate,
		]));

		return (new Signer())->sign($payload, self::SIGN_SALT);
	}

	/**
	 * @return array{voteId: int, answerId: int, nameTemplate: string, urlTemplate: string}
	 * @throws \Bitrix\Main\ArgumentTypeException
	 * @throws BadSignatureException
	 */
	public function unsign(string $signedContext): array
	{
		$payload = base64_decode((new Signer())->unsign($signedContext, self::SIGN_SALT), true);

		try
		{
			$context = ($payload === false ? null : Json::decode($payload));
		}
		catch (ArgumentException)
		{
			$context = null;
		}

		if (!is_array($context))
		{
			throw new BadSignatureException('Malformed voted context');
		}

		return [
			'voteId' => (int)($context['voteId'] ?? 0),
			'answerId' => (int)($context['answerId'] ?? 0),
			'nameTemplate' => (string)($context['nameTemplate'] ?? ''),
			'urlTemplate' => (string)($context['urlTemplate'] ?? ''),
		];
	}
}
