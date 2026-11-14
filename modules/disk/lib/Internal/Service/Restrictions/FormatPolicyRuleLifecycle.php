<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Restrictions;

use Bitrix\Disk\Internal\Entity\FormatPolicyRule;
use Bitrix\Disk\Internal\Interface\TeamDirectoryInterface;
use Bitrix\Disk\Internal\Repository\FormatPolicyRuleRepository;
use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\ManagedCache;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Event;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Removes the private format rule of an addressee that is gone: a section of the iblock structure,
 * a team of the HR structure or an employee. Only deletion removes a rule: a renamed or deactivated addressee keeps it,
 * and the rule applies again when the employee comes back.
 *
 * The handlers never throw: a failure to remove a rule must not stop the deletion it follows.
 */
class FormatPolicyRuleLifecycle
{
	protected const LOG_FEATURE = 'disk restrictions format policy';

	protected readonly TeamDirectoryInterface $teamDirectory;
	private ?LoggerInterface $logger = null;

	public function __construct(
		protected readonly FormatPolicyRuleRepository $ruleRepository,
		protected readonly FormatPolicyRuleIndex $ruleIndex,
		?TeamDirectoryInterface $teamDirectory = null,
	)
	{
		$this->teamDirectory = $teamDirectory ?? ServiceLocator::getInstance()->get(TeamDirectoryInterface::class);
	}

	/**
	 * Handler of iblock:OnAfterIBlockSectionDelete.
	 */
	public static function onIblockSectionDelete($section): void
	{
		if (is_array($section))
		{
			static::create()?->removeRuleOfIblockSection((int)($section['IBLOCK_ID'] ?? 0), (int)($section['ID'] ?? 0));
		}
	}

	/**
	 * Handler of humanresources:OnNodeDeleted. Teams only: the rule of a department goes away
	 * with its iblock section.
	 */
	public static function onNodeDeleted(Event $event): void
	{
		$lifecycle = static::create();
		if ($lifecycle === null)
		{
			return;
		}

		$teamId = $lifecycle->readDeletedTeamId($event->getParameter('node'));
		if ($teamId !== null)
		{
			$lifecycle->removeRuleOfTeam($teamId);
		}
	}

	/**
	 * Handler of main:OnUserDelete. Returns nothing: a false answer would cancel the deletion.
	 */
	public static function onUserDelete($userId): void
	{
		static::create()?->removeRuleOfUser((int)$userId);
	}

	public function removeRuleOfIblockSection(int $iblockId, int $sectionId): bool
	{
		if ($iblockId <= 0 || $iblockId !== $this->getStructureIblockId())
		{
			return false;
		}

		return $this->removeRule(FormatPolicyRule::ENTITY_TYPE_DEPARTMENT, $sectionId);
	}

	public function removeRuleOfTeam(int $nodeId): bool
	{
		return $this->removeRule(FormatPolicyRule::ENTITY_TYPE_TEAM, $nodeId);
	}

	public function removeRuleOfUser(int $userId): bool
	{
		return $this->removeRule(FormatPolicyRule::ENTITY_TYPE_USER, $userId);
	}

	protected static function create(): ?self
	{
		try
		{
			return static::compose();
		}
		catch (Throwable $exception)
		{
			LoggerFactory::create(feature: static::LOG_FEATURE)->error(
				'Unable to create the lifecycle of format policy rules',
				['exception' => $exception],
			);

			return null;
		}
	}

	/**
	 * The event manager calls the handlers by the name of the class and the method, not through the
	 * container, so their dependencies are put together here, the way .settings.php registers them.
	 */
	protected static function compose(): self
	{
		$ruleRepository = new FormatPolicyRuleRepository();

		return new self(
			$ruleRepository,
			new FormatPolicyRuleIndex(new ManagedCache(), $ruleRepository),
		);
	}

	protected function readDeletedTeamId(mixed $node): ?int
	{
		try
		{
			return $this->teamDirectory->readDeletedTeamId($node);
		}
		catch (Throwable $exception)
		{
			$this->getLogger()->error('Unable to read the deleted team of the HR structure', ['exception' => $exception]);

			return null;
		}
	}

	protected function getStructureIblockId(): int
	{
		return (int)Option::get('intranet', 'iblock_structure', 0);
	}

	protected function getLogger(): LoggerInterface
	{
		return $this->logger ??= LoggerFactory::create(feature: static::LOG_FEATURE);
	}

	private function removeRule(string $entityType, int $entityId): bool
	{
		if ($entityId <= 0)
		{
			return false;
		}

		try
		{
			$rule = $this->ruleRepository->findByAddressee($entityType, $entityId);
			if ($rule === null)
			{
				return false;
			}

			$this->ruleRepository->delete((int)$rule->getId());
			$this->ruleIndex->invalidate();
			$this->ruleIndex->markRemoved();
		}
		catch (Throwable $exception)
		{
			$this->getLogger()->error('Unable to remove the format policy rule of a deleted addressee', [
				'entityType' => $entityType,
				'entityId' => $entityId,
				'exception' => $exception,
			]);

			return false;
		}

		return true;
	}
}
