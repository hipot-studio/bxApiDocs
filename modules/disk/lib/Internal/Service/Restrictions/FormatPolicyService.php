<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Restrictions;

use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Enum\WriteOperationContext;
use Bitrix\Disk\Public\Enum\RestrictionPolicy;
use Bitrix\Disk\Public\Provider\RestrictionsAvailabilityProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Answers whether a file may be written under the format policy of the user performing the write.
 *
 * The checks go from the cheapest to the most expensive one: the write context and the
 * availability of restrictions, then the emptiness of the policy, then the editing session
 * exception, then the name itself, and last of all the beginning of the content.
 */
class FormatPolicyService
{
	/**
	 * The refusal reaches the user as an error of the file being written, so the code belongs to
	 * the model of that file and is named here by it. One value, one owner: the transports read
	 * the very same code out of the file the write was refused for.
	 */
	public const ERROR_FORMAT_RESTRICTED = File::ERROR_FORMAT_RESTRICTED;

	protected readonly SignatureDetector $signatureDetector;

	/**
	 * @param RestrictionsAvailabilityProvider $availabilityProvider
	 * @param FormatPolicyStorage $policyStorage
	 * @param EffectivePolicyResolver $policyResolver Resolver of the policy of a user; it reads the
	 *        global rule from its own storage.
	 */
	public function __construct(
		protected readonly RestrictionsAvailabilityProvider $availabilityProvider,
		protected readonly FormatPolicyStorage $policyStorage,
		protected readonly EffectivePolicyResolver $policyResolver,
	)
	{
		$this->signatureDetector = new SignatureDetector();
	}

	/**
	 * Tells whether a file with this name may be written in this context.
	 *
	 * @param string $name Name of the file being written.
	 * @param WriteOperationContext $context Context of the write operation.
	 * @param int|null $actorId User performing the write; none gets the global rule.
	 * @param string|null $contentHead Head of the file content, when the write path has it at hand.
	 * @param int|null $sessionStartedAt Start time of the editing session, for the session context.
	 * @return Result
	 */
	public function isAllowed(
		string $name,
		WriteOperationContext $context,
		?int $actorId,
		?string $contentHead = null,
		?int $sessionStartedAt = null,
	): Result
	{
		$result = new Result();

		$policy = $this->findAppliedPolicy($context, $actorId, $sessionStartedAt);
		if ($policy === null)
		{
			return $result;
		}

		if ($this->isNameOfRestrictedFormat($name, $policy))
		{
			return $result->addError($this->createRefusal());
		}

		if ($this->isContentOfRestrictedFormat($contentHead, $policy->extensionMap))
		{
			return $result->addError($this->createRefusal());
		}

		return $result;
	}

	/**
	 * Tells whether the verdict on a write in this context may still come down to the content.
	 * The write path asks this before it goes for the head of the content: the order of the checks
	 * stays here, and the caller pays for the read only where the read can change the verdict.
	 *
	 * An applied policy is not enough on its own. The content answers only through a type the
	 * detector recognizes firmly, and such a type meets the policy through its own extensions, so a
	 * policy naming none of them - "zip" alone, say - would send every write to the storage for a
	 * head that cannot change anything. Such a write is judged by its name, as it would have been
	 * after the read.
	 *
	 * @param WriteOperationContext $context Context of the write operation.
	 * @param int|null $actorId User performing the write; none gets the global rule.
	 * @param int|null $sessionStartedAt Start time of the editing session, for the session context.
	 * @return bool
	 */
	public function needsContentHead(WriteOperationContext $context, ?int $actorId, ?int $sessionStartedAt = null): bool
	{
		$policy = $this->findAppliedPolicy($context, $actorId, $sessionStartedAt);
		if ($policy === null)
		{
			return false;
		}

		return $this->hasRestrictedExtensionOfStrongType($policy->extensionMap);
	}

	/**
	 * Tells whether a write in this context is judged by the policy at all: the restriction applies
	 * on the portal and the policy of the user names something. A caller that has to read something
	 * to be judged - the names of the files of a folder - asks this first and reads nothing when not.
	 *
	 * @param WriteOperationContext $context Context of the write operation.
	 * @param int|null $actorId User performing the write; none gets the global rule.
	 * @return bool
	 */
	public function isPolicyApplied(WriteOperationContext $context, ?int $actorId): bool
	{
		return $this->findAppliedPolicy($context, $actorId, null) !== null;
	}

	/**
	 * Returns the policy the user writes under, whether or not restrictions apply on the portal.
	 *
	 * @param int|null $actorId User performing the write; none gets the global rule.
	 * @return EffectivePolicy
	 */
	public function findEffectivePolicy(?int $actorId): EffectivePolicy
	{
		return $this->policyResolver->resolve($actorId);
	}

	/**
	 * Returns the policy this write is judged by, or null when it is judged by none: content the
	 * product generates itself, a portal without the restriction, an empty policy of the user and a
	 * session opened before that policy last changed all leave the write to itself.
	 */
	protected function findAppliedPolicy(
		WriteOperationContext $context,
		?int $actorId,
		?int $sessionStartedAt,
	): ?EffectivePolicy
	{
		if ($this->isContextOutsideThePolicy($context))
		{
			return null;
		}

		if (!$this->availabilityProvider->isApplied(RestrictionPolicy::Formats))
		{
			return null;
		}

		$policy = $this->findEffectivePolicy($actorId);
		if ($policy->isEmpty())
		{
			return null;
		}

		if ($this->isSessionStartedBeforePolicy($context, $sessionStartedAt, $policy->changedAt))
		{
			return null;
		}

		return $policy;
	}

	/**
	 * Content the product generates is not the user putting a file on the portal, so it is outside
	 * the policy whatever the policy names. Content a move carries is judged: the place it lands in
	 * may be written under a stricter policy than the one it was stored under.
	 */
	protected function isContextOutsideThePolicy(WriteOperationContext $context): bool
	{
		return $context === WriteOperationContext::ProductGenerated;
	}

	protected function isNameOfRestrictedFormat(string $name, EffectivePolicy $policy): bool
	{
		$extension = $this->getLastExtension($name);
		if ($extension === null)
		{
			return $policy->blockNoExtension;
		}

		return isset($policy->extensionMap[$extension]);
	}

	/**
	 * The content answers for itself only when its type is recognized firmly enough: a weak
	 * verdict is left to the name alone. A recognized type meets the policy through its own
	 * synonymous extensions, so a type the policy names by none of them restricts nothing.
	 */
	protected function isContentOfRestrictedFormat(?string $contentHead, array $restrictedExtensionMap): bool
	{
		$type = $this->signatureDetector->detect($contentHead);
		if ($type === null || !$type['isStrong'])
		{
			return false;
		}

		foreach ($type['extensions'] as $extension)
		{
			if (isset($restrictedExtensionMap[$extension]))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether the policy names an extension the content itself could give away. The map of
	 * the detector is bounded by its table, so the smaller side is the one walked over.
	 */
	protected function hasRestrictedExtensionOfStrongType(array $restrictedExtensionMap): bool
	{
		foreach ($this->signatureDetector->getStronglyRecognizedExtensionMap() as $extension => $ignored)
		{
			if (isset($restrictedExtensionMap[$extension]))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * One refusal for both ways the format is recognized: the caller tells the user by the name
	 * of the file, whatever gave the format away.
	 */
	protected function createRefusal(): Error
	{
		return new Error(
			'The format of the file is restricted on this portal.',
			self::ERROR_FORMAT_RESTRICTED,
		);
	}

	/**
	 * A session opened before the policy was saved keeps saving its document: the history of the
	 * policy is not stored, so the start time is compared with the last change of the whole policy
	 * of the user writing.
	 *
	 * The amnesty covers the whole verdict, the name included, and that is what makes it worth
	 * anything: the name is the only check a document already open can fail, so leaving it on would
	 * cost the user the work of the session the moment the policy names their format. The amnesty is
	 * bounded by the life of the session and by the document it was opened for - an editing session
	 * writes new versions of one file and cannot rename it, so nothing new of a restricted format
	 * gets into the storage this way.
	 *
	 * The way into the editor is judged without the amnesty, and deliberately so: opening a session
	 * is a decision of its own, made by the name as any upload is
	 * ({@see FormatEditingRestriction}). A user whose session is alive keeps saving; the same user
	 * reopening the document is given a view. The two points are meant to differ.
	 *
	 * Both times are kept to the second, so a session opened within the second of the save has no
	 * knowable order against it, and the amnesty is given to it: a session wrongly amnestied writes
	 * one more version of a file that is already there, while a session wrongly checked loses the
	 * work of the user - the refusal is final for the editor and its saves stop reaching Disk.
	 */
	protected function isSessionStartedBeforePolicy(
		WriteOperationContext $context,
		?int $sessionStartedAt,
		int $savedAt,
	): bool
	{
		return $context === WriteOperationContext::EditingSession
			&& $sessionStartedAt !== null
			&& $sessionStartedAt <= $savedAt
		;
	}

	/**
	 * Returns the last extension of the name in lower case, or null when the name has none. The
	 * extension is everything after the last dot, exactly as pathinfo() and the shell of the
	 * receiving side read it: a name that is nothing but a dot and an extension - ".exe" - carries
	 * that extension as much as "setup.exe" does, and a hidden file carries the one it really has
	 * (".gitignore" gives "gitignore", restricted only where the policy names it). A path is cut
	 * down to its last part.
	 *
	 * The name is normalized before the extension is taken from it: dots and spaces at the end are
	 * dropped on the way to the file system of the receiving side - Windows, WebDAV clients and the
	 * desktop sync all put "payload.exe." down as "payload.exe" - so the extension is read from the
	 * name the file ends up carrying, not from the one the request spelled.
	 */
	protected function getLastExtension(string $name): ?string
	{
		$slashPosition = strrpos($name, '/');
		$baseName = $slashPosition === false ? $name : substr($name, $slashPosition + 1);
		$baseName = rtrim($baseName, ". \t\n\r\0\x0B");

		$dotPosition = strrpos($baseName, '.');
		if ($dotPosition === false)
		{
			return null;
		}

		return strtolower(substr($baseName, $dotPosition + 1));
	}
}
