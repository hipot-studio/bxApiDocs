<?php

namespace Bitrix\MailMobile\Public\Service;

use Bitrix\MailMobile\Internal\Integration\Main\SenderProvider;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Mail\Converter;

class MailSenderService
{
	private SenderProvider $senderProvider;

	public function __construct()
	{
		$this->senderProvider = new SenderProvider();
	}

	/**
	 * @throws ObjectPropertyException|ArgumentException|SystemException
	 */
	public function getSenderList(?int $userId = null): array
	{
		$context = $this->senderProvider->getUserAvailableSendersWithSignatures($userId);
		$signaturesById = $this->indexSignaturesById($context);
		$signaturesBySender = (array)($context['signaturesBySender'] ?? []);
		$senders = [];

		foreach ((array)($context['senders'] ?? []) as $sender)
		{
			$builtSender = $this->buildSender([
				'email' => $sender['email'] ?? '',
				'name' => $sender['name'] ?? '',
				'id' => $sender['userId'] ?? 0,
				'isUser' => true,
			]);

			$htmlSignature = $this->resolveSelectedSignatureText(
				(string)($sender['formated'] ?? ''),
				$signaturesById,
				$signaturesBySender,
			);
			$builtSender['signature'] = $htmlSignature === '' ? '' : Converter::htmlToText($htmlSignature);

			$senders[] = $builtSender;
		}

		return $senders;
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array<int, array<string, mixed>>
	 */
	private function indexSignaturesById(array $context): array
	{
		$signaturesById = [];
		foreach ((array)($context['signatures'] ?? []) as $signature)
		{
			$id = is_array($signature) ? (int)($signature['id'] ?? 0) : 0;
			if ($id > 0)
			{
				$signaturesById[$id] = $signature;
			}
		}

		return $signaturesById;
	}

	/**
	 * Signature the core preselected for the sender, with macros already resolved.
	 *
	 * @param array<int, array<string, mixed>> $signaturesById
	 * @param array<string, mixed> $signaturesBySender
	 */
	private function resolveSelectedSignatureText(
		string $senderKey,
		array $signaturesById,
		array $signaturesBySender,
	): string
	{
		if ($senderKey === '')
		{
			return '';
		}

		$selectedId = (int)($signaturesBySender[$senderKey]['selectedSignatureId'] ?? 0);
		$signature = $signaturesById[$selectedId] ?? null;
		if (!is_array($signature))
		{
			return '';
		}

		$senderTexts = (array)($signature['senderTexts'] ?? []);

		return (string)($senderTexts[$senderKey] ?? $signature['signature'] ?? '');
	}

	private function buildSender(array $props): array
	{
		$whiteListKeys = [
			'email' => '',
			'name' => '',
			'id' => 0,
			'isUser' => false,
		];

		$sender = [];
		foreach ($whiteListKeys as $key => $value)
		{
			if (isset($props[$key]) && $props[$key])
			{
				if ($key === 'id')
				{
					$props[$key] = (int)$props[$key];
				}

				$sender[$key] = $props[$key];
			}
			else
			{
				$sender[$key] = $value;
			}
		}

		return $sender;
	}
}
