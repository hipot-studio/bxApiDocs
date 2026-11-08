<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\VacationAutoReply;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Helper\Message;
use Bitrix\Mail\Internals\Model\MailboxAddressAliasTable;
use Bitrix\Mail\Internals\VacationAutoReplyLogTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Mail\Address;
use Bitrix\Main\Type\DateTime;

/**
 * Ordered chain of the mandatory exclusion rules: does this letter get an auto-reply, and to which
 * address.
 *
 * ALG-01 (NORMATIVE):
 *   decide(message, setting, now):
 *     if not feature_enabled:            return skip(feature_off)
 *     if setting is null or not active:  return skip(inactive)
 *     if now < setting.starts_at:        return skip(before_period)
 *     if now > setting.ends_at:          return skip(after_period)
 *     if message.is_outcome or message.is_draft or message.is_spam or message.is_trash:
 *                                        return skip(not_incoming)
 *     if not message.is_fresh_arrival:   return skip(backfill)
 *     sender = normalize(message.from)
 *     if sender is empty:                return skip(no_sender)
 *     if mailbox_address not in unique(message.to, message.cc):
 *                                        return skip(not_addressed)
 *     if sender in user_own_addresses:   return skip(self)
 *     if sender matches no_reply_pattern: return skip(service_address)
 *     if header(auto_submitted) not in ('', 'no'):
 *                                        return skip(auto_submitted)
 *     if header(precedence) in ('bulk', 'list', 'junk'):
 *                                        return skip(bulk)
 *     if header(list_id) or header(list_unsubscribe):
 *                                        return skip(mailing_list)
 *     if unique_count(message.to, message.cc) > 10:
 *                                        return skip(too_many_recipients)
 *     if log_has(setting.mailbox_id, setting.period_id, sender):
 *                                        return skip(already_notified)
 *     return reply(sender)
 *
 * The order is the contract: the cheap fields of the letter answer first, and the log of already
 * notified senders is asked last, when everything else has already agreed to an answer.
 *
 * The rules only decide. A letter dropped by a rule leaves no trace anywhere, including the log, and
 * the address of the verdict is the only one an answer may go to.
 *
 * What the rules cannot prove stays a letter of a human: an absent or unknown header is not a sign of
 * an automatic letter, and such a letter is answered. The user cannot switch a rule off or change it.
 *
 * Headers are read out of the letter the caller passes and are never fetched here. The answer leaves in a
 * pass of the queue, long after the event is gone, so the caller uses the copy stored in the database.
 * Only an oversized letter loses the tail of that copy, and losing it can cost a rule the very
 * sign it looks for - a letter nothing can be proven about is answered. Duplicates of one header name are
 * lost by the parser, so no rule may rely on more than one occurrence of the same name.
 */
class VacationAutoReplyRules
{
	public const REASON_FEATURE_OFF = 'feature_off';
	public const REASON_INACTIVE = 'inactive';
	public const REASON_BEFORE_PERIOD = 'before_period';
	public const REASON_AFTER_PERIOD = 'after_period';
	public const REASON_NOT_INCOMING = 'not_incoming';
	public const REASON_BACKFILL = 'backfill';
	public const REASON_NO_SENDER = 'no_sender';
	public const REASON_NOT_ADDRESSED = 'not_addressed';
	public const REASON_SELF = 'self';
	public const REASON_SERVICE_ADDRESS = 'service_address';
	public const REASON_AUTO_SUBMITTED = 'auto_submitted';
	public const REASON_BULK = 'bulk';
	public const REASON_MAILING_LIST = 'mailing_list';
	public const REASON_TOO_MANY_RECIPIENTS = 'too_many_recipients';
	public const REASON_ALREADY_NOTIFIED = 'already_notified';

	/** Ten unique addresses of "To" and "Copy" together are still answered, eleven are not. */
	private const RECIPIENTS_LIMIT = 10;

	/**
	 * Length of the address columns of `b_mail_message`. A letter with a long list of recipients keeps the
	 * whole of it only in HEADER, and the columns hold the first 255 characters of it.
	 */
	private const ADDRESS_FIELD_LENGTH = 255;

	/**
	 * Service addresses nobody is meant to answer, in the spellings senders actually use:
	 * no-reply, noreply, do-not-reply, donotreply.
	 */
	private const NO_REPLY_PATTERN = '/(?:no|do[._-]?not)[._-]?reply/i';

	private const BULK_PRECEDENCE_VALUES = ['bulk', 'list', 'junk'];

	/**
	 * Addresses already read within this hit, by mailbox. The receiver builds the rules anew for every job,
	 * so a pass over ten letters of one mailbox would otherwise ask the same two questions ten times.
	 *
	 * The memory lasts a hit and no longer, the way `MailboxTable::getTheOwnersMailboxes()` remembers the
	 * mailboxes of a user: the aliases of a mailbox are added and taken away while the portal runs. A
	 * mailbox read as reachable at no address is remembered as such - the rule keeps the answer in either
	 * way, and a pass that changed its mind halfway would answer the same letter differently.
	 *
	 * @var array<int, array<string, true>>
	 */
	private static array $mailboxAddresses = [];

	/**
	 * @param array $message Message as the `mail`/`onMailMessageNew` event publishes it.
	 * @param array|null $setting Setting of the mailbox (VacationAutoReplyService::getSetting()).
	 * @param bool $isFreshArrival Parameter of the same event: the letter has just arrived, and the
	 *                             pass is not loading history. Never recomputed here.
	 * @return array{reason: string|null, sender: string|null} Reason of the skip, or the address to
	 *         answer under `sender` with no reason at all.
	 */
	public function decide(
		array $message,
		?array $setting,
		bool $isFreshArrival,
		?DateTime $now = null,
	): array
	{
		if (!$this->isFeatureEnabled())
		{
			return self::skip(self::REASON_FEATURE_OFF);
		}

		if ($setting === null || empty($setting['active']))
		{
			return self::skip(self::REASON_INACTIVE);
		}

		$moment = ($now ?? new DateTime())->getTimestamp();
		if ($moment < self::periodBound($setting, 'startsAt'))
		{
			return self::skip(self::REASON_BEFORE_PERIOD);
		}

		if ($moment > self::periodBound($setting, 'endsAt'))
		{
			return self::skip(self::REASON_AFTER_PERIOD);
		}

		// The same set of flags the existing pick of incoming letters uses
		// (Integration\AI\MessageClassifier::isIncoming()): a letter synced out of the trash is no letter
		// to answer either
		if (
			!empty($message['IS_OUTCOME'])
			|| !empty($message['IS_DRAFT'])
			|| !empty($message['IS_SPAM'])
			|| !empty($message['IS_TRASH'])
		)
		{
			return self::skip(self::REASON_NOT_INCOMING);
		}

		// A letter loaded by a history pass may have arrived long before the period and be answered
		// after the user is already back
		if (!$isFreshArrival)
		{
			return self::skip(self::REASON_BACKFILL);
		}

		// Parsed before the addresses and not after: the columns of the addresses are 255 characters long,
		// and the whole of a long list is in the header alone
		$header = self::parseHeader($message);

		$sender = self::extractSenderAddress($message, $header);
		if ($sender === '')
		{
			return self::skip(self::REASON_NO_SENDER);
		}

		$recipients = self::extractRecipients($message, $header);
		$mailboxId = (int)($setting['mailboxId'] ?? 0);
		if (array_intersect_key($recipients, $this->getMailboxAddresses($mailboxId)) === [])
		{
			return self::skip(self::REASON_NOT_ADDRESSED);
		}

		$ownAddresses = $this->getUserOwnAddresses((int)($setting['userId'] ?? 0));
		if (isset($ownAddresses[$sender]))
		{
			return self::skip(self::REASON_SELF);
		}

		if (self::isServiceAddress($sender))
		{
			return self::skip(self::REASON_SERVICE_ADDRESS);
		}

		if (!in_array(self::headerKeyword($header, 'Auto-Submitted'), ['', 'no'], true))
		{
			return self::skip(self::REASON_AUTO_SUBMITTED);
		}

		if (in_array(self::headerKeyword($header, 'Precedence'), self::BULK_PRECEDENCE_VALUES, true))
		{
			return self::skip(self::REASON_BULK);
		}

		if (self::headerValue($header, 'List-Id') !== '' || self::headerValue($header, 'List-Unsubscribe') !== '')
		{
			return self::skip(self::REASON_MAILING_LIST);
		}

		if (count($recipients) > self::RECIPIENTS_LIMIT)
		{
			return self::skip(self::REASON_TOO_MANY_RECIPIENTS);
		}

		if ($this->isAlreadyNotified(
			$mailboxId,
			(int)($setting['periodId'] ?? 0),
			$sender,
		))
		{
			return self::skip(self::REASON_ALREADY_NOTIFIED);
		}

		return self::reply($sender);
	}

	/**
	 * @param array{reason: string|null, sender: string|null} $verdict
	 */
	public static function isReply(array $verdict): bool
	{
		return ($verdict['reason'] ?? null) === null;
	}

	protected function isFeatureEnabled(): bool
	{
		return Feature::isVacationAutoReplyAvailable();
	}

	/**
	 * Addresses of the mailboxes of the user, in the stored form of the log. The owner of the setting
	 * is the owner of the mailbox, so a letter of his own never gets an answer.
	 *
	 * Every mailbox of the person counts, whatever state it is in: a letter he sent from a mailbox that
	 * has since been switched off, or from a POP3 one the mail client never lists, is his letter all the
	 * same, and answering it is answering himself. The aliases of those mailboxes count for the same
	 * reason - an address a mailbox is reached at is an address its owner writes from.
	 *
	 * The cost of asking this way is two uncached reads per job where there used to be none: the
	 * `getTheOwnersMailboxes()` this grew out of is cached twice over - by a static of the process and by
	 * a managed cache of the portal - and normally cost the database nothing at all. Both reads go by
	 * index (USER_ID of the mailbox, the leading column of the unique key of the aliases), and the chain
	 * runs once per job of the queue - once per letter really about to be answered, beside the sending of
	 * a letter out of the portal, next to which two index reads are nothing.
	 *
	 * The cache of that method cannot be borrowed, and a cache of our own is worse than none: that one is
	 * dropped when a mailbox changes hands, which now happens on every update of a mailbox
	 * (`CAllMailBox::Update()`), while a set of addresses remembered here has nothing to drop it - and a
	 * stale set answers a letter of the owner as if it were a stranger's.
	 *
	 * @return array<string, true> Address as the key: the rule only asks whether one is there.
	 */
	protected function getUserOwnAddresses(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		return self::readUserOwnAddresses($userId);
	}

	/**
	 * @return array<string, true>
	 */
	private static function readUserOwnAddresses(int $userId): array
	{
		// Deliberately not MailboxTable::getTheOwnersMailboxes(): that one answers with the active IMAP
		// mailboxes a person works in, and the question here is which addresses are his at all
		$mailboxes = MailboxTable::getList([
			'select' => ['ID', 'EMAIL'],
			'filter' => ['=USER_ID' => $userId],
		])->fetchAll();

		$mailboxIds = array_column($mailboxes, 'ID');
		if ($mailboxIds === [])
		{
			return [];
		}

		return self::collectAddresses(array_merge($mailboxes, MailboxAddressAliasTable::getList([
			'select' => ['EMAIL'],
			'filter' => ['@MAILBOX_ID' => $mailboxIds],
		])->fetchAll()));
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Rows carrying an EMAIL each.
	 *
	 * @return array<string, true>
	 */
	private static function collectAddresses(array $rows): array
	{
		$addresses = [];
		foreach ($rows as $row)
		{
			$address = self::normalizeAddress((string)($row['EMAIL'] ?? ''));
			if ($address !== '')
			{
				$addresses[$address] = true;
			}
		}

		return $addresses;
	}

	/**
	 * Addresses the mailbox itself is reached at: the one it has now and the ones it used to have, in the
	 * stored form of the log. A letter that names none of them is a letter the mailbox was not addressed
	 * in - a blind copy, a catch-all forward or the traffic of a mailing that marked itself with nothing -
	 * and RFC 3834 section 2 asks such a letter to be left alone.
	 *
	 * A mailbox whose address cannot be read is reached at no address at all, so the answer stays in.
	 *
	 * @return array<string, true> Address as the key: the rule only asks whether one is there.
	 */
	protected function getMailboxAddresses(int $mailboxId): array
	{
		if ($mailboxId <= 0)
		{
			return [];
		}

		return self::$mailboxAddresses[$mailboxId] ??= self::readMailboxAddresses($mailboxId);
	}

	/**
	 * @return array<string, true>
	 */
	private static function readMailboxAddresses(int $mailboxId): array
	{
		$rows = MailboxAddressAliasTable::getList([
			'select' => ['EMAIL'],
			'filter' => ['=MAILBOX_ID' => $mailboxId],
		])->fetchAll();

		$rows[] = MailboxTable::getRow([
			'select' => ['EMAIL'],
			'filter' => ['=ID' => $mailboxId],
		]) ?? [];

		return self::collectAddresses($rows);
	}

	/**
	 * Has this address already been answered within this period of the mailbox.
	 */
	protected function isAlreadyNotified(int $mailboxId, int $periodId, string $address): bool
	{
		if ($mailboxId <= 0 || $periodId <= 0 || $address === '')
		{
			return false;
		}

		return VacationAutoReplyLogTable::getRow([
			'select' => ['MAILBOX_ID'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxId,
				'=PERIOD_ID' => $periodId,
				'=ADDRESS' => VacationAutoReplyLogTable::normalizeAddress($address),
			],
		]) !== null;
	}

	/**
	 * Address an answer would go to: the one the letter was written from, and no other (RFC 3834
	 * section 4). `Reply-To` is written by the sender himself and nothing confirms it - not even a
	 * domain of its own that matches, since a domain everybody writes from proves nobody - so taking it
	 * would let the sender of any letter pick who hears from the mailbox.
	 *
	 * An address that cannot be parsed counts as none at all.
	 */
	private static function extractSenderAddress(array $message, ?\CMailHeader $header): string
	{
		return self::firstAddress(self::addressField($message, 'FIELD_FROM', 'FROM', $header));
	}

	/**
	 * Unique addresses of "To" and "Copy" together, in the stored form of the log.
	 *
	 * @return array<string, true>
	 */
	private static function extractRecipients(array $message, ?\CMailHeader $header): array
	{
		return self::extractAddresses(self::addressField($message, 'FIELD_TO', 'TO', $header))
			+ self::extractAddresses(self::addressField($message, 'FIELD_CC', 'CC', $header))
		;
	}

	/**
	 * One address field of the letter, read back from the header when the stored column is full.
	 *
	 * Both decisions of this class that go by addresses are decided wrong on a truncated field: the address
	 * of the mailbox can be the one cut off, and then a letter written to the user is taken for a letter he
	 * only saw a copy of; and a list longer than the limit of the recipients is counted as shorter, so an
	 * answer goes to a mailing the limit is there to be silent about. Nothing of it fits in 255 characters:
	 * a handful of addresses with the names of their owners is already over.
	 *
	 * The header is the copy of the letter as it came, and it is already loaded and parsed here for
	 * `Auto-Submitted` and the headers of the mailing lists. The outgoing path of the module reads the
	 * fields back the very same way (`Helper\Mailbox::syncOutgoingMessage()`).
	 */
	private static function addressField(
		array $message,
		string $field,
		string $headerName,
		?\CMailHeader $header,
	): string
	{
		$stored = (string)($message[$field] ?? '');
		if (mb_strlen($stored) < self::ADDRESS_FIELD_LENGTH)
		{
			return $stored;
		}

		$written = self::headerValue($header, $headerName);

		// A header that carries no such field at all leaves the truncated column as the only thing there is
		return $written === '' ? $stored : $written;
	}

	private static function firstAddress(string $field): string
	{
		$addresses = self::extractAddresses($field);

		return $addresses === [] ? '' : (string)array_key_first($addresses);
	}

	/**
	 * Addresses of one header value, in the stored form of the log and in the order they are written.
	 *
	 * The value is split by the parser of the module and not by its commas: a comma inside a quoted name
	 * is part of that name, a group carries its members behind a colon and a semicolon separates
	 * addresses as well. A mailbox looked for among the pieces of a value cut on commas is a mailbox not
	 * found in its own letter.
	 *
	 * @return array<string, true>
	 */
	private static function extractAddresses(string $field): array
	{
		$addresses = [];
		foreach (Message::parseAddressList($field) as $item)
		{
			$item = trim($item);
			if ($item === '')
			{
				continue;
			}

			$address = new Address($item);
			if (!$address->validate())
			{
				continue;
			}

			$email = self::normalizeAddress((string)$address->getEmail());
			if ($email !== '')
			{
				$addresses[$email] = true;
			}
		}

		return $addresses;
	}

	private static function isServiceAddress(string $address): bool
	{
		$localPart = mb_strstr($address, '@', true);

		return preg_match(self::NO_REPLY_PATTERN, $localPart === false ? $address : $localPart) === 1;
	}

	/**
	 * The stored form of an address is the one the log keeps: the rule of the log and the rule of the
	 * chain must agree on what "the same sender" means.
	 */
	private static function normalizeAddress(string $address): string
	{
		return VacationAutoReplyLogTable::normalizeAddress($address);
	}

	/**
	 * A letter without a header is not parsed: there is nothing to read, and the legacy parser expects
	 * a real header.
	 */
	private static function parseHeader(array $message): ?\CMailHeader
	{
		$header = (string)($message['HEADER'] ?? '');

		return $header === '' ? null : \CMailMessage::parseHeader($header, LANG_CHARSET);
	}

	private static function headerValue(?\CMailHeader $header, string $name): string
	{
		return $header === null ? '' : trim((string)$header->getHeader($name));
	}

	/**
	 * Keyword of a header that carries parameters after it: `Auto-Submitted: auto-generated;
	 * owner-email=...` states auto-generated, and the parameters say nothing about the rule.
	 */
	private static function headerKeyword(?\CMailHeader $header, string $name): string
	{
		$value = self::headerValue($header, $name);
		$parameters = mb_strpos($value, ';');

		return mb_strtolower(trim($parameters === false ? $value : mb_substr($value, 0, $parameters)));
	}

	/**
	 * @return array{reason: string, sender: null}
	 */
	private static function skip(string $reason): array
	{
		return ['reason' => $reason, 'sender' => null];
	}

	/**
	 * @return array{reason: null, sender: string}
	 */
	private static function reply(string $sender): array
	{
		return ['reason' => null, 'sender' => $sender];
	}

	/**
	 * A bound of the period is a required field of the setting; a row without it is treated as a period
	 * long over, so a broken setting stays silent instead of answering.
	 *
	 * @param array<string, mixed> $setting
	 */
	private static function periodBound(array $setting, string $key): int
	{
		$bound = $setting[$key] ?? null;

		return $bound instanceof DateTime ? $bound->getTimestamp() : 0;
	}
}
