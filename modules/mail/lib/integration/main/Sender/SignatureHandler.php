<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Main\Sender;

use Bitrix\Mail\Service\SharedSignature\SignatureChoiceStorage;
use Bitrix\Mail\Service\SharedSignature\SignatureContextService;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * Answers the core event OnMailSenderListBuild with the signatures of the current user.
 *
 * The sender key of the event belongs to the caller and stays opaque: the context service rebuilds
 * its own key from the address and the name, so the answer is mapped back onto the keys that came in.
 */
class SignatureHandler
{
	private const EMPTY_CONTEXT = [
		'signatures' => [],
		'senders' => [],
	];

	public static function onMailSenderListBuild(Event $event): EventResult
	{
		try
		{
			$parameters = self::buildContext(
				(int)($event->getParameter('userId') ?? 0),
				(array)($event->getParameter('senders') ?? []),
			);
		}
		catch (\Throwable)
		{
			$parameters = self::EMPTY_CONTEXT;
		}

		return new EventResult(EventResult::SUCCESS, $parameters);
	}

	protected static function createContextService(\Closure $senderProvider): SignatureContextService
	{
		return new SignatureContextService(senderProvider: $senderProvider);
	}

	/**
	 * @param array<int, mixed> $eventSenders
	 * @return array{signatures: array<int, array<string, mixed>>, senders: array<string, array<string, mixed>>}
	 */
	private static function buildContext(int $userId, array $eventSenders): array
	{
		$callerSenders = self::normalizeCallerSenders($eventSenders);
		if ($userId <= 0 || $callerSenders === [])
		{
			return self::EMPTY_CONTEXT;
		}

		$service = static::createContextService(static fn(int $contextUserId): array => $callerSenders);
		$context = $service->getResolvedContext($userId);
		$mailKeyToCallerKeys = self::mapMailKeysToCallerKeys($callerSenders);

		return [
			'signatures' => self::remapSignatures((array)($context['signatures'] ?? []), $mailKeyToCallerKeys),
			'senders' => self::remapSenders((array)($context['senders'] ?? []), $mailKeyToCallerKeys),
		];
	}

	/**
	 * @param array<int, mixed> $eventSenders
	 * @return array<int, array{key: string, email: string, name: string, mailboxId: int}>
	 */
	private static function normalizeCallerSenders(array $eventSenders): array
	{
		$callerSenders = [];
		foreach ($eventSenders as $sender)
		{
			if (!is_array($sender))
			{
				continue;
			}

			$callerKey = (string)($sender['key'] ?? '');
			if ($callerKey === '' || isset($callerSenders[$callerKey]))
			{
				continue;
			}

			$callerSenders[$callerKey] = [
				'key' => $callerKey,
				'email' => trim((string)($sender['email'] ?? '')),
				'name' => trim((string)($sender['name'] ?? '')),
				'mailboxId' => max(0, (int)($sender['mailboxId'] ?? 0)),
			];
		}

		return array_values($callerSenders);
	}

	/**
	 * @param array<int, array{key: string, email: string, name: string, mailboxId: int}> $callerSenders
	 * @return array<string, string[]> mail sender key => caller sender keys
	 */
	private static function mapMailKeysToCallerKeys(array $callerSenders): array
	{
		$mailKeyToCallerKeys = [];
		foreach ($callerSenders as $callerSender)
		{
			$mailKey = SignatureChoiceStorage::buildSenderKey($callerSender['email'], $callerSender['name']);
			if ($mailKey === '')
			{
				continue;
			}

			$mailKeyToCallerKeys[$mailKey][] = $callerSender['key'];
		}

		return $mailKeyToCallerKeys;
	}

	/**
	 * @param array<int, mixed> $contextSenders
	 * @param array<string, string[]> $mailKeyToCallerKeys
	 * @return array<string, array{availableSignatureIds: int[], selectedSignatureId: int|null}>
	 */
	private static function remapSenders(array $contextSenders, array $mailKeyToCallerKeys): array
	{
		$sendersOut = [];
		foreach ($contextSenders as $sender)
		{
			if (!is_array($sender))
			{
				continue;
			}

			foreach ($mailKeyToCallerKeys[(string)($sender['key'] ?? '')] ?? [] as $callerKey)
			{
				$selectedSignatureId = $sender['selectedSignatureId'] ?? null;
				$sendersOut[$callerKey] = [
					'availableSignatureIds' => array_values(array_map(
						'intval',
						(array)($sender['availableSignatureIds'] ?? []),
					)),
					'selectedSignatureId' => $selectedSignatureId === null ? null : (int)$selectedSignatureId,
				];
			}
		}

		return $sendersOut;
	}

	/**
	 * @param array<int, mixed> $contextSignatures
	 * @param array<string, string[]> $mailKeyToCallerKeys
	 * @return array<int, array{id: int, signature: string, scope: string, senderTexts: array<string, string>}>
	 */
	private static function remapSignatures(array $contextSignatures, array $mailKeyToCallerKeys): array
	{
		$signaturesOut = [];
		foreach ($contextSignatures as $signature)
		{
			if (!is_array($signature) || !isset($signature['id']))
			{
				continue;
			}

			$senderTexts = [];
			foreach ((array)($signature['senderTexts'] ?? []) as $mailKey => $text)
			{
				foreach ($mailKeyToCallerKeys[(string)$mailKey] ?? [] as $callerKey)
				{
					$senderTexts[$callerKey] = (string)$text;
				}
			}

			$signaturesOut[] = [
				'id' => (int)$signature['id'],
				'signature' => (string)($signature['signature'] ?? ''),
				'scope' => (string)($signature['scope'] ?? ''),
				'senderTexts' => $senderTexts,
			];
		}

		return $signaturesOut;
	}
}
