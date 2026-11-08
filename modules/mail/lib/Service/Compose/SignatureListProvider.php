<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\Compose;

use Bitrix\Mail\Internal\Service\Signature\SignaturePlainText;
use Bitrix\Mail\Internal\Service\Signature\Image\SignatureImageResourceProvider;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Internal\Service\Signature\Template\Macro\SignatureMacroCatalog;
use Bitrix\Mail\Internal\Service\Signature\Template\SignatureTemplateContext;
use Bitrix\Mail\Internal\Service\Signature\Template\SignatureTemplateProcessor;
use Bitrix\Mail\Internal\Service\Signature\Template\SignatureTemplateProcessorFactory;
use Bitrix\Mail\Service\Signature\SignatureTemplateResolver;
use Bitrix\Mail\Service\SharedSignature\AssignmentResolver;
use Bitrix\Mail\Internals\UserSignatureTable;
use Bitrix\Mail\Service\SharedSignature\SignatureChoiceStorage;
use Bitrix\Mail\Service\SharedSignature\SignatureResolver;
use Bitrix\Main\Application;
use Bitrix\Main\Mail\Sender;

/**
 * Signatures the compose form starts with: every signature available to the user, grouped by the
 * sender it belongs to, plus every choice the user has already made.
 *
 * The map is keyed by sender exactly the way the form looks a sender up:
 *   - a personal signature goes under its own SENDER ("Name <address>" or a bare address);
 *   - a personal signature without SENDER is resolved separately under every actual sender;
 *   - a shared one goes under the 'formated' of the mailbox it is assigned to, so it shows up only
 *     for that sender.
 *
 * Personal signatures come in ID desc order and shared ones in assignment order (latest first),
 * the same order the old form shows them in. A shared signature assigned to one sender twice is
 * listed once.
 *
 * The choices are handed over in the very shape the storage keeps them ("<id>:<unixtime>" keyed by
 * the normalised sender): the store is shared with the old form, so a choice made in one form has
 * to survive in the other. Resolving the default out of these two is the client's job: it knows
 * the sender the user is on right now.
 *
 * Serves both the initial data of the form (DTO-01, key 'signatures') and the action that refreshes
 * the list after the signature settings slider is closed (API-02): one structure, one provider.
 */
class SignatureListProvider
{
	private const PREVIEW_MAX_LENGTH = 500;
	private const MENU_PREVIEW_MAX_GRAPHEMES = 500;

	private SignatureResolver $signatureResolver;

	private SignatureChoiceStorage $choiceStorage;
	private SignatureImageResourceProvider $imageResourceProvider;
	private SignatureTemplateResolver $templateResolver;
	private bool $macrosEnabled;

	public function __construct(
		?SignatureResolver $signatureResolver = null,
		?SignatureChoiceStorage $choiceStorage = null,
		?SignatureTemplateProcessor $templateProcessor = null,
		?bool $macrosEnabled = null,
		?SignatureImageResourceProvider $imageResourceProvider = null,
	)
	{
		$this->signatureResolver = $signatureResolver ?? new SignatureResolver();
		$this->choiceStorage = $choiceStorage ?? new SignatureChoiceStorage();
		$this->macrosEnabled = $macrosEnabled ?? Feature::isSignatureMacrosAvailable();
		$this->templateResolver = new SignatureTemplateResolver(
			$templateProcessor ?? SignatureTemplateProcessorFactory::create(macrosEnabled: $this->macrosEnabled),
		);
		$this->imageResourceProvider = $imageResourceProvider ?? new SignatureImageResourceProvider();
	}

	/**
	 * @param array|null $senders Sender list of the user (Sender::prepareUserMailboxes()); loaded
	 *                            when not given.
	 * @return array{
	 *     bySender: array<string, array<array{
	 *         full: string,
	 *         preview: string,
	 *         menuPreview: string,
	 *         signatureId: int,
	 *         isShared: bool,
	 *         assignedAt: int|null,
	 *     }>>,
	 *     choices: array<string, string>,
	 * }
	 */
	public function getSignatures(int $userId, ?array $senders = null): array
	{
		if ($userId <= 0)
		{
			return [
				'bySender' => [],
				'choices' => [],
			];
		}

		$senders ??= $this->loadSenders($userId);
		$personalRows = $this->loadPersonalSignatureRows($userId);
		$mailboxIds = $this->collectMailboxIds($senders);
		$sharedByMailbox = $mailboxIds === [] ? [] : $this->resolveSharedSignatures($mailboxIds);
		$resources = $this->resolveImageResources($this->collectSignatureIds($personalRows, $sharedByMailbox));

		return [
			'bySender' => $this->mergeSharedSignatures(
				$this->collectPersonalSignatures($userId, $senders, $personalRows, $resources),
				$senders,
				$userId,
				$sharedByMailbox,
				$resources,
			),
			'choices' => $this->resolveChoices($userId),
		];
	}

	/**
	 * @return array<string, array<array{full: string, preview: string, menuPreview: string, signatureId: int, isShared: bool, assignedAt: null}>>
	 */
	private function collectPersonalSignatures(
		int $userId,
		array $senders,
		array $rows,
		array $resources,
	): array
	{
		$contextBySender = $this->buildContextBySender($userId, $senders);
		$universalContextBySender = $this->buildUniversalContextBySender($userId, $senders);
		$bySender = [];
		$universalRows = [];
		foreach ($rows as $row)
		{
			$senderKey = (string)($row['SENDER'] ?? '');
			if ($senderKey === '')
			{
				$universalRows[] = $row;
				continue;
			}

			$this->appendPersonalSignature(
				$bySender,
				$senderKey,
				$row,
				$contextBySender[AssignmentResolver::normalizeSenderKey($senderKey)]
					?? new SignatureTemplateContext($userId, 0, '', ''),
				$resources,
			);
		}

		$emailToken = $this->macrosEnabled
			? (new SignatureMacroCatalog())->getTokensById()['employee.email']
			: null;
		foreach ($universalRows as $row)
		{
			$template = (string)($row['SIGNATURE'] ?? '');
			if ($emailToken === null || !str_contains($template, $emailToken))
			{
				$this->appendPersonalSignature(
					$bySender,
					'',
					$row,
					new SignatureTemplateContext($userId, 0, '', ''),
					$resources,
				);

				continue;
			}

			foreach ($universalContextBySender as $targetSenderKey => $context)
			{
				$this->appendPersonalSignature($bySender, $targetSenderKey, $row, $context, $resources);
			}
		}

		return $bySender;
	}

	private function appendPersonalSignature(
		array &$bySender,
		string $senderKey,
		array $row,
		SignatureTemplateContext $context,
		array $resources,
	): void
	{
		$signatureId = (int)($row['ID'] ?? 0);
		$signature = $this->resolveTemplate(
			(string)($row['SIGNATURE'] ?? ''),
			$context->withImageResources($resources[$signatureId] ?? []),
		);
		if ($signature === null)
		{
			return;
		}

		$bySender[$senderKey][] = [
			'full' => $signature,
			'preview' => $this->buildPreview($signature),
			'menuPreview' => $this->buildMenuPreview($signature),
			'signatureId' => $signatureId,
			'isShared' => false,
			'assignedAt' => null,
		];
	}

	/**
	 * @param array<string, array> $bySender Personal signatures, already grouped.
	 * @return array<string, array>
	 */
	private function mergeSharedSignatures(
		array $bySender,
		array $senders,
		int $userId,
		array $sharedByMailbox,
		array $resources,
	): array
	{
		$senderKeyByMailbox = [];
		$contextByMailbox = [];
		foreach ($senders as $sender)
		{
			$mailboxId = (int)($sender['mailboxId'] ?? 0);
			$senderKey = (string)($sender['formated'] ?? '');
			if ($mailboxId > 0 && $senderKey !== '')
			{
				$senderKeyByMailbox[$mailboxId] = $senderKey;
				$contextByMailbox[$mailboxId] = $this->buildContext($userId, $mailboxId, $sender);
			}
		}

		if (empty($senderKeyByMailbox))
		{
			return $bySender;
		}

		$seenBySender = [];
		$seenByMailbox = [];
		foreach ($sharedByMailbox as $mailboxId => $sharedSignatures)
		{
			$senderKey = $senderKeyByMailbox[$mailboxId] ?? null;
			if ($senderKey === null)
			{
				continue;
			}

			foreach ($sharedSignatures as $item)
			{
				$signatureId = (int)($item['signatureId'] ?? 0);
				if ($signatureId <= 0 || isset($seenByMailbox[$mailboxId][$signatureId]))
				{
					continue;
				}
				$seenByMailbox[$mailboxId][$signatureId] = true;
				$isFirstUnderSenderKey = !isset($seenBySender[$senderKey][$signatureId]);
				$seenBySender[$senderKey][$signatureId] = true;

				$signature = $this->resolveTemplate(
					(string)($item['signature'] ?? ''),
					$contextByMailbox[$mailboxId]->withImageResources($resources[$signatureId] ?? []),
				);
				if ($signature === null)
				{
					continue;
				}
				$assignedAt = $item['assignedAt'] ?? null;

				$entry = [
					'full' => $signature,
					'preview' => $this->buildPreview($signature),
					'menuPreview' => $this->buildMenuPreview($signature),
					'signatureId' => $signatureId,
					'isShared' => true,
					// Moment of the latest assignment: the client weighs a remembered choice
					// against it, an assignment made later wins over the choice.
					'assignedAt' => $assignedAt === null ? null : (int)$assignedAt,
				];

				// Two connections of one address share the formatted key, so a shared signature also goes
				// under the key of its own mailbox; the form reads that key first and keeps the sets apart.
				$bySender[self::mailboxSenderKey($mailboxId)][] = $entry;
				if ($isFirstUnderSenderKey)
				{
					$bySender[$senderKey][] = $entry;
				}
			}
		}

		return $bySender;
	}

	public static function mailboxSenderKey(int $mailboxId): string
	{
		return 'mailbox:' . $mailboxId;
	}

	private function collectMailboxIds(array $senders): array
	{
		return array_values(array_unique(array_filter(array_map(
			static fn(array $sender): int => (int)($sender['mailboxId'] ?? 0),
			$senders,
		))));
	}

	private function collectSignatureIds(array $personalRows, array $sharedByMailbox): array
	{
		$ids = [];
		foreach ($personalRows as $row)
		{
			if ($this->hasImageMarker((string)($row['SIGNATURE'] ?? '')))
			{
				$ids[] = (int)($row['ID'] ?? 0);
			}
		}
		foreach ($sharedByMailbox as $signatures)
		{
			foreach ($signatures as $signature)
			{
				if ($this->hasImageMarker((string)($signature['signature'] ?? '')))
				{
					$ids[] = (int)($signature['signatureId'] ?? 0);
				}
			}
		}

		return array_values(array_unique(array_filter(array_map('intval', $ids))));
	}

	private function hasImageMarker(string $template): bool
	{
		return str_contains($template, 'data-bx-mail-signature-image=');
	}

	private function resolveImageResources(array $signatureIds): array
	{
		try
		{
			return $this->imageResourceProvider->getBySignatureIds($signatureIds);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return [];
		}
	}

	private function resolveTemplate(string $template, SignatureTemplateContext $context): ?string
	{
		return $this->templateResolver->resolveInContext($template, $context);
	}

	/** @return array<string, SignatureTemplateContext> */
	private function buildContextBySender(int $userId, array $senders): array
	{
		$contexts = [];
		foreach ($senders as $sender)
		{
			$context = $this->buildContext($userId, (int)($sender['mailboxId'] ?? 0), $sender);
			foreach ([(string)($sender['formated'] ?? ''), (string)($sender['email'] ?? '')] as $key)
			{
				if ($key !== '')
				{
					$contexts[AssignmentResolver::normalizeSenderKey($key)] = $context;
				}
			}
		}

		return $contexts;
	}

	/** @return array<string, SignatureTemplateContext> */
	private function buildUniversalContextBySender(int $userId, array $senders): array
	{
		if (empty($senders))
		{
			return ['' => new SignatureTemplateContext($userId, 0, '', '')];
		}

		$contexts = [];
		foreach ($senders as $sender)
		{
			$senderKey = (string)($sender['email'] ?? '');
			$senderKey = $senderKey !== '' ? $senderKey : (string)($sender['formated'] ?? '');
			$senderKey = AssignmentResolver::normalizeSenderKey($senderKey);
			if ($senderKey !== '')
			{
				$contexts[$senderKey] = $this->buildContext($userId, (int)($sender['mailboxId'] ?? 0), $sender);
			}
		}

		return $contexts ?: ['' => new SignatureTemplateContext($userId, 0, '', '')];
	}

	private function buildContext(int $userId, int $mailboxId, array $sender): SignatureTemplateContext
	{
		return new SignatureTemplateContext(
			$userId,
			$mailboxId,
			(string)($sender['email'] ?? ''),
			(string)($sender['name'] ?? ''),
		);
	}

	/**
	 * Shared signatures of the given mailboxes, or nothing when the resolver fails: the same
	 * graceful fallback the old form does, so a broken shared model costs the shared signatures
	 * alone and not the whole compose form.
	 *
	 * @param int[] $mailboxIds
	 * @return array<int, array>
	 */
	private function resolveSharedSignatures(array $mailboxIds): array
	{
		try
		{
			return $this->signatureResolver->resolveSharedForMailboxes($mailboxIds);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return [];
		}
	}

	/**
	 * Remembered choices of the user, or nothing when the store fails: the same graceful fallback the
	 * old form does. Kept apart from the shared signature fallback on purpose: one source going down
	 * must not cost the other.
	 *
	 * @return array<string, string>
	 */
	private function resolveChoices(int $userId): array
	{
		try
		{
			return $this->choiceStorage->getAllChoices($userId);
		}
		catch (\Throwable $exception)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);

			return [];
		}
	}

	/**
	 * Legacy plain-text representation used to derive the selected signature name.
	 */
	private function buildPreview(string $signature): string
	{
		return SignaturePlainText::fromHtml($signature, self::PREVIEW_MAX_LENGTH);
	}

	/**
	 * Single-line plain-text representation shown in the signature menu.
	 */
	private function buildMenuPreview(string $signature): string
	{
		$preview = (string)preg_replace('#(?=</?(?:br|div|p)\b)#i', "\n", $signature);
		$preview = strip_tags($preview);
		$preview = html_entity_decode($preview, ENT_COMPAT, 'UTF-8');
		$preview = $this->removeLeadingMailSeparatorLines($preview);
		$preview = (string)preg_replace('#[\s\p{Z}]+#u', ' ', $preview);
		$preview = trim($preview);
		$preview = $this->truncateByGraphemes($preview);

		return trim($preview);
	}

	private function removeLeadingMailSeparatorLines(string $value): string
	{
		$lines = preg_split('#\R#u', $value);
		if ($lines === false)
		{
			return $value;
		}

		$contentLineIndex = 0;
		foreach ($lines as $line)
		{
			$lineWithoutWhitespace = (string)preg_replace('#[\s\p{Z}]+#u', '', $line);
			if ($lineWithoutWhitespace !== '' && preg_match('#^-+$#D', $lineWithoutWhitespace) !== 1)
			{
				break;
			}

			$contentLineIndex++;
		}

		return implode("\n", array_slice($lines, $contentLineIndex));
	}

	private function truncateByGraphemes(string $value): string
	{
		if (mb_strlen($value) <= self::MENU_PREVIEW_MAX_GRAPHEMES)
		{
			return $value;
		}

		$pattern = '#^(?:\X){0,' . self::MENU_PREVIEW_MAX_GRAPHEMES . '}#u';

		return preg_match($pattern, $value, $matches) === 1 ? $matches[0] : '';
	}

	/**
	 * @return array<array{ID: int, SENDER: string|null, SIGNATURE: string|null}>
	 */
	protected function loadPersonalSignatureRows(int $userId): array
	{
		return UserSignatureTable::getList([
			'select' => ['ID', 'SENDER', 'SIGNATURE'],
			'filter' => ['=USER_ID' => $userId],
			'order' => ['ID' => 'DESC'],
		])->fetchAll();
	}

	protected function loadSenders(int $userId): array
	{
		// The same list the form itself reads: a mailbox missing here would lose the signatures
		// assigned to it once the settings slider asks for them again.
		return (new SenderListProvider())->getSenders($userId);
	}
}
