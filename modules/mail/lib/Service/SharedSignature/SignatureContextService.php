<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\SharedSignature;

use Bitrix\Mail\Dto\MobileSignatureContextDto;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Internals\SharedSignatureAssignmentTable;
use Bitrix\Mail\Internals\SharedSignatureTable;
use Bitrix\Mail\Internal\Service\Signature\Template\SignatureTemplateContext;
use Bitrix\Mail\Service\Signature\SignatureTemplateResolver;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Mail\Sender\UserSenderDataProvider;
use Bitrix\Main\Result;

final class SignatureContextService
{
	public const ERROR_INVALID_SIGNATURE_ID = 'SIGNATURE_INVALID_ID';
	public const ERROR_INVALID_SENDER_KEY = 'SIGNATURE_INVALID_SENDER_KEY';
	public const ERROR_SENDER_NOT_AVAILABLE = 'SIGNATURE_SENDER_NOT_AVAILABLE';
	public const ERROR_SIGNATURE_NOT_AVAILABLE = 'SIGNATURE_NOT_AVAILABLE';
	public const ERROR_EMPTY_TEXT = 'SIGNATURE_EMPTY_TEXT';
	public const ERROR_LIMIT_REACHED = 'SIGNATURE_LIMIT_REACHED';
	public const ERROR_OPERATION_FAILED = 'SIGNATURE_OPERATION_FAILED';
	public const ERROR_FEATURE_DISABLED = 'SIGNATURE_FEATURE_DISABLED';

	private const DEFAULT_OWNER_SIGNATURE_LIMIT = 100;

	private SignatureResolver $resolver;

	private SignatureMigrator $migrator;

	private SignatureChoiceStorage $choiceStorage;

	private SharedSignatureService $signatureService;

	private SignatureTemplateResolver $templateResolver;

	private \Closure $senderProvider;

	public function __construct(
		?SignatureResolver $resolver = null,
		?SignatureMigrator $migrator = null,
		?SignatureChoiceStorage $choiceStorage = null,
		?callable $senderProvider = null,
		?SharedSignatureService $signatureService = null,
		?SignatureTemplateResolver $templateResolver = null,
	)
	{
		$this->choiceStorage = $choiceStorage ?? new SignatureChoiceStorage();
		$this->resolver = $resolver ?? new SignatureResolver(choiceStorage: $this->choiceStorage);
		$this->migrator = $migrator ?? new SignatureMigrator(choiceStorage: $this->choiceStorage);
		$this->signatureService = $signatureService ?? new SharedSignatureService();
		$this->templateResolver = $templateResolver ?? new SignatureTemplateResolver();
		$this->senderProvider = $senderProvider === null
			? static fn(int $userId): array => UserSenderDataProvider::getUserAvailableSenders($userId)
			: \Closure::fromCallable($senderProvider);
	}

	/**
	 * Returns signatures and senders available to the given user.
	 */
	public function getContext(int $userId): MobileSignatureContextDto
	{
		return MobileSignatureContextDto::fromResolvedContext($this->resolveContext($userId, true));
	}

	/**
	 * The same context as getContext(), with the signature bodies left as HTML with the macros resolved.
	 * Serves a consumer that renders the signature itself; getContext() serves the mobile REST, which
	 * takes the bodies already converted to text.
	 *
	 * @return array{signatures: array, senders: array}
	 */
	public function getResolvedContext(int $userId): array
	{
		return $this->resolveContext($userId, true);
	}

	/**
	 * Saves or clears a signature choice after checking it against the current context.
	 */
	public function setChoice(int $userId, string $senderKey, ?int $signatureId): Result
	{
		$result = new Result();
		if ($signatureId !== null && $signatureId <= 0)
		{
			return $result->addError(new Error('', self::ERROR_INVALID_SIGNATURE_ID));
		}

		$context = $this->resolveContext($userId);
		$sender = null;
		foreach ($context['senders'] as $availableSender)
		{
			if ($availableSender['key'] === $senderKey)
			{
				$sender = $availableSender;

				break;
			}
		}

		if ($sender === null)
		{
			return $result->addError(new Error('', self::ERROR_SENDER_NOT_AVAILABLE));
		}

		if ($signatureId === null)
		{
			$this->choiceStorage->remove($userId, $senderKey);
		}
		elseif (!in_array($signatureId, $sender['availableSignatureIds'], true))
		{
			return $result->addError(new Error('', self::ERROR_SIGNATURE_NOT_AVAILABLE));
		}
		else
		{
			$this->choiceStorage->set($userId, $senderKey, $signatureId);
		}

		$result->setData([
			'senderKey' => $senderKey,
			'selectedSignatureId' => $signatureId,
		]);

		return $result;
	}

	/**
	 * Creates an owner signature from plain text and optional available sender keys.
	 *
	 * @param string[] $senderKeys
	 */
	public function addOwner(
		int $userId,
		string $text,
		?string $senderKey = null,
		array $senderKeys = [],
	): Result
	{
		$result = new Result();
		if (trim($text) === '')
		{
			return $result->addError(new Error('', self::ERROR_EMPTY_TEXT));
		}

		if ($senderKey !== null)
		{
			array_unshift($senderKeys, $senderKey);
		}

		foreach ($senderKeys as $candidateSenderKey)
		{
			if (!is_string($candidateSenderKey))
			{
				return $result->addError(new Error('', self::ERROR_INVALID_SENDER_KEY));
			}
		}

		$senderKeys = array_values(array_unique($senderKeys));
		$normalizedSenders = $senderKeys !== [] || Feature::isSignatureMacrosAvailable()
			? $this->normalizeSenders(($this->senderProvider)($userId))
			: []
		;
		$sendersByKey = [];
		foreach ($normalizedSenders as $sender)
		{
			$sendersByKey[$sender['key']] = $sender;
		}
		$availableSenders = [];
		foreach ($senderKeys as $senderKey)
		{
			if (
				$senderKey === ''
				|| AssignmentResolver::normalizeSenderKey($senderKey) !== $senderKey
			)
			{
				return $result->addError(new Error('', self::ERROR_INVALID_SENDER_KEY));
			}

			$sender = $sendersByKey[$senderKey] ?? null;
			if ($sender === null)
			{
				return $result->addError(new Error('', self::ERROR_SENDER_NOT_AVAILABLE));
			}

			$availableSenders[] = $sender;
		}

		$assignments = array_map(
			static fn(array $sender): array => [
				'targetType' => SharedSignatureAssignmentTable::TARGET_SENDER,
				'targetId' => 0,
				'targetValue' => $sender['key'],
				'isFlat' => false,
			],
			$availableSenders,
		);

		$signature = nl2br(htmlspecialcharsbx($text), false);
		$signatureId = $this->addOwnerUnderLock($userId, $signature, $assignments, $result);
		if ($signatureId === null)
		{
			return $result;
		}
		$result->setData([
			'item' => $this->buildOwnerItem(
				$userId,
				$signatureId,
				$signature,
				array_column($availableSenders, 'key'),
				$senderKeys === [] ? $normalizedSenders : $availableSenders,
			),
		]);

		return $result;
	}

	/**
	 * Deletes only a signature of the owner scope that belongs to the given user.
	 */
	public function deleteOwner(int $userId, int $signatureId): Result
	{
		$result = new Result();
		if ($signatureId <= 0)
		{
			return $result->addError(new Error('', self::ERROR_INVALID_SIGNATURE_ID));
		}

		$entry = $this->signatureService->getById($signatureId);
		if (
			$entry === null
			|| (string)$entry['signature']->get('SCOPE') !== SharedSignatureTable::SCOPE_OWNER
			|| (int)$entry['signature']->get('OWNER_ID') !== $userId
		)
		{
			return $result->addError(new Error('', self::ERROR_SIGNATURE_NOT_AVAILABLE));
		}

		$deleteResult = $this->signatureService->delete($signatureId);
		if (!$deleteResult->isSuccess())
		{
			return $result->addError(new Error('', self::ERROR_OPERATION_FAILED));
		}

		$result->setData(['ok' => true]);

		return $result;
	}

	/**
	 * @return array{signatures: array, senders: array}
	 */
	private function resolveContext(int $userId, bool $resolveTemplates = false): array
	{
		if ($userId <= 0)
		{
			return ['signatures' => [], 'senders' => []];
		}

		$this->migrator->migrateUser($userId);

		$senders = $this->normalizeSenders(($this->senderProvider)($userId));
		$context = $this->resolver->resolveForSenders(
			$userId,
			$senders,
		);

		return $resolveTemplates
			? $this->resolveTemplates($context, $userId, $senders)
			: $context
		;
	}

	/**
	 * @param array{signatures: array, senders: array} $context
	 * @param array<int, array{key: string, email: string, name: string, mailboxId: int}> $senders
	 * @return array{signatures: array, senders: array}
	 */
	private function resolveTemplates(array $context, int $userId, array $senders): array
	{
		if (!Feature::isSignatureMacrosAvailable())
		{
			return $context;
		}

		$sendersByKey = array_column($senders, null, 'key');
		foreach ($context['signatures'] as &$signature)
		{
			$template = (string)$signature['signature'];
			$signature['senderTexts'] = [];
			$firstSenderText = null;

			foreach ($context['senders'] as $resolvedSender)
			{
				$signatureId = (int)$signature['id'];
				if (!in_array($signatureId, $resolvedSender['availableSignatureIds'], true))
				{
					continue;
				}

				$senderKey = (string)$resolvedSender['key'];
				$sender = $sendersByKey[$senderKey] ?? null;
				if ($sender === null)
				{
					continue;
				}

				$senderText = $this->templateResolver->resolveInContext(
					$template,
					new SignatureTemplateContext(
						$userId,
						$sender['mailboxId'],
						$sender['email'],
						$sender['name'],
					),
				);
				if ($senderText === null)
				{
					continue;
				}

				$signature['senderTexts'][$senderKey] = $senderText;
				$firstSenderText ??= $senderText;
			}

			$signature['signature'] = $firstSenderText
				?? $this->templateResolver->resolve($template, $userId)
			;
		}
		unset($signature);

		return $context;
	}

	/**
	 * @return array{key: string, email: string, name: string, mailboxId: int}|null
	 */
	private function addOwnerUnderLock(int $userId, string $signature, array $assignments, Result $result): ?int
	{
		$application = Application::getInstance();
		$pool = $application->getConnectionPool();
		$pool->useMasterOnly(true);
		$connection = $application->getConnection();
		$lockName = 'mail.owner_signature_limit.' . $userId;
		$isLocked = false;

		try
		{
			$isLocked = $connection->lock($lockName, 5);
			if (!$isLocked)
			{
				$result->addError(new Error('', self::ERROR_OPERATION_FAILED));

				return null;
			}

			$this->migrator->migrateUser($userId);
			$limit = (int)Option::get('mail', 'user_signatures_limit', self::DEFAULT_OWNER_SIGNATURE_LIMIT);
			if (
				$limit > 0
				&& $this->signatureService->getTotalCountByOwner(
					$userId,
					SharedSignatureTable::SCOPE_OWNER,
				) >= $limit
			)
			{
				$result->addError(new Error('', self::ERROR_LIMIT_REACHED));

				return null;
			}

			$addResult = $this->signatureService->add([
				'signature' => $signature,
				'scope' => SharedSignatureTable::SCOPE_OWNER,
				'ownerId' => $userId,
			], $assignments);
			if (!$addResult->isSuccess())
			{
				$result->addError(new Error('', self::ERROR_OPERATION_FAILED));

				return null;
			}

			return (int)($addResult->getData()['id'] ?? 0);
		}
		finally
		{
			try
			{
				if ($isLocked)
				{
					$connection->unlock($lockName);
				}
			}
			finally
			{
				$pool->useMasterOnly(false);
			}
		}
	}

	/**
	 * @param string[] $senderKeys
	 * @param array<int, array{key: string, email: string, name: string, mailboxId: int}> $contextSenders
	 * @return array{id: int, text: string, scope: string, senderKey: string|null, senderKeys: string[]}
	 */
	private function buildOwnerItem(
		int $userId,
		int $signatureId,
		string $signature,
		array $senderKeys,
		array $contextSenders,
	): array
	{
		$context = $this->resolveTemplates([
			'signatures' => [[
				'id' => $signatureId,
				'signature' => $signature,
				'scope' => SharedSignatureTable::SCOPE_OWNER,
				'senderKey' => $senderKeys[0] ?? null,
				'senderKeys' => $senderKeys,
			]],
			'senders' => array_map(
				static fn(array $sender): array => [
					...$sender,
					'availableSignatureIds' => [$signatureId],
					'selectedSignatureId' => $signatureId,
				],
				$contextSenders,
			),
		], $userId, $contextSenders);
		$dto = MobileSignatureContextDto::fromResolvedContext($context)->toArray();

		return $dto['signatures'][0];
	}

	/**
	 * @param array<int, array<string, mixed>> $senders
	 * @return array<int, array{key: string, email: string, name: string, mailboxId: int}>
	 */
	private function normalizeSenders(array $senders): array
	{
		$result = [];
		foreach ($senders as $sender)
		{
			$email = trim((string)($sender['email'] ?? ''));
			$name = trim((string)($sender['name'] ?? ''));
			$key = SignatureChoiceStorage::buildSenderKey($email, $name);
			if ($key === '' || isset($result[$key]))
			{
				continue;
			}

			$result[$key] = [
				'key' => $key,
				'email' => $email,
				'name' => $name,
				'mailboxId' => max(0, (int)($sender['mailboxId'] ?? 0)),
			];
		}

		return array_values($result);
	}
}
