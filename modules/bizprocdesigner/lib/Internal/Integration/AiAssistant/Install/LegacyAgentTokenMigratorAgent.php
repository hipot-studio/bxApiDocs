<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Install;

use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\Rest\APAuth\PasswordTable;
use Bitrix\Rest\APAuth\PermissionTable;
use Bitrix\Rest\Enum\APAuth\PasswordType;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\WebhookType;
use Throwable;

/**
 * One-shot CAgent migrator for the legacy webhook tokens of the external AI agent.
 *
 * Legacy tokens were issued by the former AgentWebhookService via PasswordTable::createPassword:
 * these are user (type=User) active tokens with a scope of EXACTLY [bizprocdesigner] (the single
 * privilege). The new path issues System tokens with external attributes, so the type=User filter
 * reliably excludes the new tokens.
 *
 * Per run the agent deactivates a batch (~100) of legacy tokens by a direct ORM update (ACTIVE='N')
 * and reschedules itself while there is work; when no active legacy tokens remain, it returns an
 * empty string and unregisters itself.
 *
 * The "exactly one scope" criterion is checked against PermissionTable (count === 1 && the single
 * PERM === bizprocdesigner), exactly as the legacy findExisting did. The provider is not applicable
 * here: its scope filter works as an OR and does not distinguish a narrow agent token from a broad
 * user one.
 *
 * This class owns the criterion: the batch query {@see self::findLegacyPasswordIds()} and the per-webhook
 * predicate {@see self::isLegacyToken()} are its two forms and change together. The predicate outlives the
 * migration: ContourCaller refuses a legacy token the migrator failed to deactivate.
 */
final class LegacyAgentTokenMigratorAgent
{
	private const SCOPE = 'bizprocdesigner';

	private const BATCH_LIMIT = 100;

	public static function execute(): string
	{
		if (!Loader::includeModule('rest'))
		{
			return '';
		}

		$deactivated = (new self())->deactivateLegacyBatch();

		return $deactivated > 0 ? self::getAgentName() : '';
	}

	public static function getAgentName(): string
	{
		return self::class . '::execute();';
	}

	/**
	 * Whether $webhook has the shape of a legacy agent token: a User webhook with a scope of exactly
	 * [bizprocdesigner]. The per-webhook form of {@see self::findLegacyPasswordIds()}; activity is not part
	 * of the shape, it is what the migrator changes.
	 */
	public static function isLegacyToken(IncomingWebhook $webhook): bool
	{
		return $webhook->getType() === WebhookType::User
			&& array_values(array_unique($webhook->getScopes())) === [self::SCOPE]
		;
	}

	/**
	 * Deactivates a single batch of legacy tokens. Returns the number actually deactivated in the run,
	 * counting only successful updates: a token whose update fails — an exception or an unsuccessful
	 * result — is logged and skipped, so a single failure neither aborts the batch nor keeps this
	 * one-shot agent rescheduling itself forever on a token it can never deactivate.
	 */
	private function deactivateLegacyBatch(): int
	{
		$deactivated = 0;

		foreach ($this->findLegacyPasswordIds() as $passwordId)
		{
			try
			{
				$result = PasswordTable::update($passwordId, ['ACTIVE' => PasswordTable::INACTIVE]);
			}
			catch (Throwable $e)
			{
				Container::getDefaultLogger()->error(
					'AI agent legacy token migrator failed to deactivate token '
					. $passwordId . ': ' . $e->getMessage(),
				);

				continue;
			}

			if ($result->isSuccess())
			{
				++$deactivated;
			}
			else
			{
				Container::getDefaultLogger()->error(
					'AI agent legacy token migrator could not deactivate token '
					. $passwordId . ': ' . implode('; ', $result->getErrorMessages()),
				);
			}
		}

		return $deactivated;
	}

	/**
	 * Ids of active user tokens with a scope of exactly [bizprocdesigner]. The per-webhook form of the same
	 * criterion is {@see self::isLegacyToken()}.
	 *
	 * The "exactly one scope" criterion is expressed at the SQL level: ids that HAVE
	 * PERM=bizprocdesigner MINUS ids that have any OTHER PERM. Filtering in PHP over the first N
	 * type=User tokens is not possible: broad (not ours) user tokens would take up the whole batch
	 * and permanently block access to the legacy tokens beyond the limit. Here the limit is applied
	 * to the already selected candidates.
	 *
	 * Idempotency is ensured by the ACTIVE='Y' condition: already deactivated tokens do not get into
	 * the batch and are not deactivated again.
	 *
	 * @return int[]
	 */
	private function findLegacyPasswordIds(): array
	{
		$hasOwnerScope = PermissionTable::query()
			->setSelect(['PASSWORD_ID'])
			->where('PERM', self::SCOPE)
		;

		$hasOtherScope = PermissionTable::query()
			->setSelect(['PASSWORD_ID'])
			->whereNot('PERM', self::SCOPE)
		;

		$rows = PasswordTable::query()
			->setSelect(['ID'])
			->where('ACTIVE', PasswordTable::ACTIVE)
			->where('TYPE', PasswordType::User->value)
			->whereIn('ID', $hasOwnerScope)
			->whereNotIn('ID', $hasOtherScope)
			->setLimit(self::BATCH_LIMIT)
			->fetchAll()
		;

		return array_map(static fn (array $row): int => (int)$row['ID'], $rows);
	}
}
