<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Cli\Key;

use Bitrix\BIConnector\Internal\Services\Key\IssuedKeyAuditor;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deactivates the access keys whose author can no longer manage keys.
 *
 * Without --apply the command writes nothing and only reports what it would revoke.
 *
 * Usage:
 *   php bitrix/bitrix.php biconnector:key:revoke
 *   php bitrix/bitrix.php biconnector:key:revoke --apply --format=json
 */
final class RevokeCommand extends Command
{
	public const NOTIFY_TAG_PREFIX = 'biconnector_key_revoke_';

	public function isEnabled(): bool
	{
		return Loader::includeModule('biconnector');
	}

	protected function configure(): void
	{
		$this
			->setName('biconnector:key:revoke')
			->setDescription('Deactivate BI Connector access keys whose author lost the right to manage keys')
			->addOption('apply', null, InputOption::VALUE_NONE, 'Write the revocation; without it nothing is changed')
			->addOption(
				'format',
				null,
				InputOption::VALUE_REQUIRED,
				'Output format: ' . KeyReport::FORMAT_TABLE . ' or ' . KeyReport::FORMAT_JSON,
				KeyReport::FORMAT_TABLE,
			)
		;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$format = (string)$input->getOption('format');
		if (!KeyReport::isKnownFormat($format))
		{
			$output->writeln('<error>Unknown format: ' . $format . '</error>');

			return self::INVALID;
		}

		$apply = (bool)$input->getOption('apply');
		$auditor = new IssuedKeyAuditor();

		$candidates = [];
		$alreadyInactive = [];
		foreach ($auditor->collect() as $row)
		{
			if (!$auditor->isSuspicious($row))
			{
				continue;
			}

			if ($auditor->isPendingRevocation($row))
			{
				$candidates[] = KeyReport::presentRow($row, true);
			}
			else
			{
				$alreadyInactive[] = KeyReport::presentRow($row, true);
			}
		}
		$candidateIds = array_column($candidates, 'id');

		$revokedIds = [];
		$errors = [];
		if ($apply && $candidateIds)
		{
			$result = $auditor->revoke($candidateIds);
			$revokedIds = $result->getData()['revoked'] ?? [];
			$errors = $result->getErrorMessages();

			if ($revokedIds)
			{
				$this->notifyPortalOwner($revokedIds);
			}
		}

		$this->report($output, $format, $apply, $candidates, $alreadyInactive, $revokedIds, $errors);

		return $errors ? self::FAILURE : self::SUCCESS;
	}

	private function report(
		OutputInterface $output,
		string $format,
		bool $apply,
		array $candidates,
		array $alreadyInactive,
		array $revokedIds,
		array $errors,
	): void
	{
		if ($format === KeyReport::FORMAT_JSON)
		{
			$output->writeln((string)json_encode(
				[
					'applied' => $apply,
					'candidates' => $candidates,
					'alreadyInactive' => array_column($alreadyInactive, 'id'),
					'revoked' => $revokedIds,
					'errors' => $errors,
					'note' => IssuedKeyAuditor::SCOPE_NOTE,
				],
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
			));

			return;
		}

		if (!$candidates)
		{
			$output->writeln('No access keys to revoke.');
		}
		else
		{
			$output->writeln(
				$apply
					? 'Revoked ' . count($revokedIds) . ' of ' . count($candidates) . ' key(s):'
					: 'Dry run, nothing was changed. Would revoke ' . count($candidates) . ' key(s):',
			);
			foreach ($candidates as $candidate)
			{
				$output->writeln(
					'  #' . $candidate['id']
					. ' app ' . $candidate['appId']
					. ', author ' . $candidate['createdBy']
					. ', used: ' . ($candidate['wasUsed'] ? 'yes' : 'no')
				);
			}

			if (!$apply)
			{
				$output->writeln('Run the command with --apply to write the revocation.');
			}
		}

		if ($alreadyInactive)
		{
			$output->writeln(
				'Left as is, ' . count($alreadyInactive) . ' suspicious key(s) already revoked earlier: #'
				. implode(', #', array_column($alreadyInactive, 'id'))
			);
		}

		foreach ($errors as $error)
		{
			$output->writeln('<error>' . $error . '</error>');
		}

		$output->writeln(IssuedKeyAuditor::SCOPE_NOTE);
	}

	/**
	 * @param int[] $revokedIds
	 */
	private function notifyPortalOwner(array $revokedIds): void
	{
		\CAdminNotify::Add([
			'MESSAGE' => (string)Loc::getMessagePlural(
				'BICONNECTOR_CLI_KEY_REVOKE_NOTIFY_MESSAGE',
				count($revokedIds),
				[
					'#COUNT#' => count($revokedIds),
					'#KEYS#' => implode(', ', $revokedIds),
				],
			),
			'TAG' => self::NOTIFY_TAG_PREFIX . (new \Bitrix\Main\Type\DateTime())->format('YmdHis') . '_' . $revokedIds[0],
			'MODULE_ID' => 'biconnector',
			'ENABLE_CLOSE' => 'Y',
		]);
	}
}
