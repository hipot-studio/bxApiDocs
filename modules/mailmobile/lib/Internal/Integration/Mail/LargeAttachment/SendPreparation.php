<?php

declare(strict_types=1);

namespace Bitrix\MailMobile\Internal\Integration\Mail\LargeAttachment;

use Bitrix\Mail\Public\Service\Draft\Dto\DraftSendClaim;
use Bitrix\Mail\Public\Service\Draft\DraftSendService;
use Bitrix\Mail\Public\Service\LargeAttachment\Dto\SendContractResult;
use Bitrix\Mail\Public\Service\LargeAttachment\LargeAttachmentAvailability;
use Bitrix\Mail\Public\Service\LargeAttachment\MessageMarkerService;
use Bitrix\Mail\Public\Service\LargeAttachment\SendContractValidator;
use Bitrix\MailMobile\Internal\Integration\Mail\AttachmentLimit;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * Mobile mail as a third consumer of the large attachment layer of the mail module, after the web form and
 * the CRM message. The adapter is thin on purpose: which set is valid, which file the set really holds,
 * which link the message must carry and how large a set has to be are all decided by the mail module.
 *
 * Everything mobile mail borrows from that layer passes through here, so the send path of the controller
 * never reaches into the mail module itself.
 */
final class SendPreparation
{
	public const CONFIRMED_FILE_IDS = 'confirmedFileIds';
	public const EXTERNAL_LINK_IDS = 'externalLinkIds';
	public const ERROR_INVALID_SEND_CONTRACT = 'MAIL_LA_INVALID_SEND_CONTRACT';
	public const ERROR_INVALID_SEND_RESULT = 'MAIL_LA_INVALID_SEND_RESULT';

	private const MARKER_LOGGER_ID = 'mail.large_attachment';

	/**
	 * The event manager is handed in by the caller that owns the send: subscribing the service marker is a
	 * side effect on the request, and the adapter must not reach for the manager of the process itself.
	 */
	public function __construct(
		private readonly ?SendContractValidator $validator = null,
		private readonly ?bool $featureEnabled = null,
		private readonly ?EventManager $eventManager = null,
		private readonly ?bool $tariffAllowed = null,
	)
	{
	}

	public static function isAvailable(): bool
	{
		return self::hasRequiredMailApi() && LargeAttachmentAvailability::isDiskUploadAvailable();
	}

	/**
	 * Whether the tariff of the portal still pays for a set. It is not a term of the availability of the
	 * scenario and must not become one: storing an attachment in the Disk costs the sender nothing, and only
	 * sending it as a service link is the paid feature. So the answer is asked apart, and only about a set.
	 *
	 * A mail module released before the scenario cannot be asked at all, and that reads as not allowed - the
	 * same way the channel of tariff restrictions of this module reads it.
	 */
	public static function isAllowedByTariff(): bool
	{
		return Loader::includeModule('mail')
			&& class_exists(LargeAttachmentAvailability::class)
			&& method_exists(LargeAttachmentAvailability::class, 'isAutoUploadAllowedByTariff')
			&& LargeAttachmentAvailability::isAutoUploadAllowedByTariff()
		;
	}

	/**
	 * The canonical threshold of the mail module, asked through the limit adapter of this module.
	 *
	 * @param int $totalRawSize Sum of raw (pre-base64) sizes of the attachments left in the message.
	 */
	public static function exceedsMailLimit(int $totalRawSize): bool
	{
		return AttachmentLimit::exceedsMailLimit($totalRawSize);
	}

	/**
	 * Confirms the converted sets of the outgoing message: refuses a draft that promises the recipients a
	 * set this request said nothing about, then hands the reported sets to the validator of the mail module.
	 *
	 * @param array $data Send payload as the client reported it.
	 * @param mixed[] $allowedFileIds Identifiers actually attached to this message.
	 */
	public function prepareForSend(
		int $userId,
		array $data,
		array $allowedFileIds,
		string $messageBody,
	): Result
	{
		$contracts = self::normalizeContracts($data['largeAttachments'] ?? []);

		if (self::hasUnconfirmedDraftSets($userId, $data, $contracts))
		{
			// An older build of the application opens a draft a newer one created: it knows nothing about
			// the set, and the message would leave without the file promised to the recipients.
			return (new Result())->addError(new Error(
				(string)Loc::getMessage('MAILMOBILE_SEND_PREPARATION_DRAFT_SET_UNCONFIRMED'),
				self::ERROR_INVALID_SEND_CONTRACT,
			));
		}

		return $this->prepare($userId, $contracts, $allowedFileIds, $messageBody);
	}

	/**
	 * @param array<int, array{token?: mixed, fileIds?: mixed}> $contracts
	 * @param mixed[] $allowedFileIds
	 */
	public function prepare(
		int $userId,
		array $contracts,
		array $allowedFileIds,
		string $messageBody,
	): Result
	{
		if ($contracts === [])
		{
			return $this->success([], []);
		}

		if (($this->featureEnabled ?? self::isAvailable()) === false)
		{
			// The client promised the recipients a link this portal cannot produce. Sending the message
			// without the file it names would leave them with a broken promise instead of an error.
			return (new Result())->addError(new Error(
				(string)Loc::getMessage('MAILMOBILE_SEND_PREPARATION_SCENARIO_UNAVAILABLE'),
				self::ERROR_INVALID_SEND_CONTRACT,
			));
		}

		// A set is addressed by a token the client keeps, so the tariff is asked again here and not only
		// where the set was made: otherwise a downgraded portal would confirm a token saved earlier and the
		// letter would leave as the service link the downgrade revoked. Asked after the availability of the
		// scenario on purpose - on a portal that cannot produce a link at all the tariff is the wrong reason.
		if (($this->tariffAllowed ?? self::isAllowedByTariff()) === false)
		{
			return (new Result())->addError(new Error(
				(string)Loc::getMessage('MAILMOBILE_SEND_PREPARATION_TARIFF_UNAVAILABLE'),
				SendContractValidator::ERROR_TARIFF_UNAVAILABLE,
			));
		}

		$validationResult = ($this->validator ?? new SendContractValidator())->validate(
			$userId,
			$contracts,
			$allowedFileIds,
			$messageBody,
		);
		if (!$validationResult->isSuccess())
		{
			return (new Result())->addErrors($validationResult->getErrors());
		}

		$sendContract = $validationResult->getData()[SendContractValidator::RESULT_KEY] ?? null;
		if (!$sendContract instanceof SendContractResult)
		{
			return (new Result())->addError(new Error(
				(string)Loc::getMessage('MAILMOBILE_SEND_PREPARATION_INVALID_RESULT'),
				self::ERROR_INVALID_SEND_RESULT,
			));
		}

		return $this->success($sendContract->fileIds, $sendContract->externalLinkIds);
	}

	/**
	 * The sets the client reported, in the shape the validator of the mail module reads. Nothing is
	 * discarded here: a set that cannot be read is left for the validator to refuse, because a request that
	 * names a set and gets a message without it is worse than a request that fails.
	 *
	 * @return array<int, array{token: mixed, fileIds: mixed}>
	 */
	public static function normalizeContracts(mixed $contracts): array
	{
		if (!is_array($contracts))
		{
			return [];
		}

		$normalized = [];
		foreach (array_values($contracts) as $contract)
		{
			$normalized[] = is_array($contract)
				? ['token' => $contract['token'] ?? null, 'fileIds' => $contract['fileIds'] ?? null]
				: ['token' => null, 'fileIds' => null]
			;
		}

		return $normalized;
	}

	/**
	 * Whether the claimed draft carries a converted set this request did not report. Which draft owns which
	 * set is decided by the mail module together with the draft storage it belongs to.
	 *
	 * @param array<int, array{token: mixed, fileIds: mixed}> $contracts
	 */
	public static function hasUnconfirmedDraftSets(int $userId, array $data, array $contracts): bool
	{
		if (!self::hasDraftSetApi())
		{
			return false;
		}

		$claimedTokens = [];
		foreach ($contracts as $contract)
		{
			if (is_string($contract['token']) && $contract['token'] !== '')
			{
				$claimedTokens[$contract['token']] = true;
			}
		}

		$draftSets = DraftSendService::getOwnedLargeAttachments(DraftSendClaim::forMail(
			$userId,
			$data['draftId'] ?? null,
			$data['draftRevision'] ?? null,
			$data['draftClientId'] ?? null,
		));
		foreach ($draftSets as $draftSet)
		{
			$token = (string)($draftSet['token'] ?? '');
			if ($token !== '' && !isset($claimedTokens[$token]))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Subscribes the service marker of the outgoing message to the locally stored copy of it. The marker is
	 * written by the mail module and only for the message this send produced, matched by its Message-Id.
	 *
	 * @param int[] $externalLinkIds
	 *
	 * @return int|null Event handler key to release once the send is over.
	 */
	public function addMarkerHandler(string $messageId, array $externalLinkIds): ?int
	{
		if ($externalLinkIds === [] || $this->eventManager === null || !self::hasRequiredMailApi())
		{
			return null;
		}

		$expectedMessageId = trim($messageId, '<>');

		return $this->eventManager->addEventHandler(
			'mail',
			'onMailMessageNew',
			static function (Event $event) use ($expectedMessageId, $externalLinkIds): void
			{
				$message = (array)$event->getParameter('message');
				if ((string)($message['MSG_ID'] ?? '') !== $expectedMessageId)
				{
					return;
				}

				$result = MessageMarkerService::registerForMessage(
					(int)($message['ID'] ?? 0),
					$externalLinkIds,
				);
				if (!$result->isSuccess())
				{
					(new LoggerFactory())->createById(self::MARKER_LOGGER_ID, [], false)?->error(
						'Could not register outgoing message markers: {error}',
						['error' => $result->getErrors()[0]->getMessage()],
					);
				}
			},
		);
	}

	public function removeMarkerHandler(?int $eventKey): void
	{
		if ($eventKey !== null && $this->eventManager !== null)
		{
			$this->eventManager->removeEventHandler('mail', 'onMailMessageNew', $eventKey);
		}
	}

	private static function hasRequiredMailApi(): bool
	{
		return Loader::includeModule('mail')
			&& class_exists(SendContractValidator::class)
			&& class_exists(MessageMarkerService::class)
			&& method_exists(MessageMarkerService::class, 'registerForMessage')
			&& class_exists(LargeAttachmentAvailability::class)
			&& method_exists(LargeAttachmentAvailability::class, 'isDiskUploadAvailable')
		;
	}

	/**
	 * The mail module is released on its own, so a build without the draft set contract simply reports no
	 * sets instead of failing every send.
	 */
	private static function hasDraftSetApi(): bool
	{
		return Loader::includeModule('mail')
			&& class_exists(DraftSendService::class)
			&& method_exists(DraftSendService::class, 'getOwnedLargeAttachments')
			&& class_exists(DraftSendClaim::class)
			&& method_exists(DraftSendClaim::class, 'forMail')
		;
	}

	/**
	 * @param int[] $confirmedFileIds
	 * @param int[] $externalLinkIds
	 */
	private function success(array $confirmedFileIds, array $externalLinkIds): Result
	{
		$result = new Result();
		$result->setData([
			self::CONFIRMED_FILE_IDS => $confirmedFileIds,
			self::EXTERNAL_LINK_IDS => $externalLinkIds,
		]);

		return $result;
	}
}
