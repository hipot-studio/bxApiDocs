<?php

declare(strict_types=1);

namespace Bitrix\Anonymizer\Internal\Repository;

use Bitrix\Anonymizer\Internal\Entities\Quest;
use Bitrix\Anonymizer\Internal\Entities\Request;
use Bitrix\Anonymizer\Internal\Exceptions\AnonymizerException;
use Bitrix\Anonymizer\Internal\Integration\AnonymizerService\Command\AnonymizeText;
use Bitrix\Anonymizer\Internal\Integration\AnonymizerService\Command\CommandInterface;
use Bitrix\Anonymizer\Public\Providers\FileProviderInterface;
use Bitrix\Anonymizer\Public\Providers\ProviderInterface;
use Bitrix\Anonymizer\Public\Providers\TextProviderInterface;
use Bitrix\Anonymizer\Public\Resolvers\ReplaceResolverInterface;
use Bitrix\Anonymizer\Public\Resolvers\ResolverInterface;

/**
 * Operation groups (anonymize, …): pick command by provider, fill resolver by quest + bindings.
 *
 * @phpstan-type OperationBinding array{
 *     commandCode: string,
 *     provider: class-string<ProviderInterface>,
 *     command: class-string<CommandInterface>,
 *     resolver: class-string<ResolverInterface>,
 *     init: callable(ProviderInterface): CommandInterface,
 * }
 */
final class CommandRegistry
{
	/**
	 * @return list<OperationBinding>
	 */
	private static function getAnonymizeBindings(): array
	{
		return [
			[
				'commandCode' => AnonymizeText::getCode(),
				'provider' => FileProviderInterface::class,
				'command' => AnonymizeText::class,
				'resolver' => ReplaceResolverInterface::class,
				'init' => [self::class, 'initAnonymizeTextFromFileProvider'],
			],
			[
				'commandCode' => AnonymizeText::getCode(),
				'provider' => TextProviderInterface::class,
				'command' => AnonymizeText::class,
				'resolver' => ReplaceResolverInterface::class,
				'init' => [self::class, 'initAnonymizeTextCommand'],
			],
		];
	}

	/**
	 * @return list<list<OperationBinding>>
	 */
	private static function operationBindingGroups(): array
	{
		return [
			self::getAnonymizeBindings(),
		];
	}

	public static function getByCode(string $code): ?CommandInterface
	{
		foreach (self::operationBindingGroups() as $bindings)
		{
			foreach ($bindings as $binding)
			{
				if ($binding['commandCode'] !== $code)
				{
					continue;
				}

				$class = $binding['command'];

				return new $class();
			}
		}

		return null;
	}

	/**
	 * @throws AnonymizerException
	 */
	public static function getForAnonymize(ProviderInterface $provider): CommandInterface
	{
		return self::getFor($provider, self::getAnonymizeBindings());
	}

	/**
	 * @param list<OperationBinding> $bindings
	 *
	 * @throws AnonymizerException
	 */
	private static function getFor(ProviderInterface $provider, array $bindings): CommandInterface
	{
		$binding = self::findBindingForProvider($provider, $bindings);
		if ($binding === null)
		{
			throw new AnonymizerException(
				'No anonymize command registered for provider: ' . $provider::class,
			);
		}

		try
		{
			return ($binding['init'])($provider);
		}
		catch (\TypeError $e)
		{
			throw new AnonymizerException(
				'Command init callback is incompatible with binding provider '
				. $binding['provider']
				. ' for provider '
				. $provider::class,
				0,
				$e,
			);
		}
	}

	/**
	 * @throws AnonymizerException
	 */
	public static function fillResolverForAnonymize(Quest $quest, ResolverInterface $resolver): void
	{
		self::fillResolverFor($quest, $resolver, self::getAnonymizeBindings());
	}

	/**
	 * @param list<OperationBinding> $bindings
	 *
	 * @throws AnonymizerException
	 */
	private static function fillResolverFor(
		Quest $quest,
		ResolverInterface $resolver,
		array $bindings,
	): void
	{
		$questId = $quest->getId();
		if ($questId === null)
		{
			throw new AnonymizerException('Quest id is required to fill resolver');
		}

		$provider = $quest->getContext()->provider;
		$binding = self::findBindingForProvider($provider, $bindings);
		if ($binding === null)
		{
			throw new AnonymizerException(
				'No operation binding for quest provider: ' . $provider::class,
			);
		}

		$request = Request::getByQuestAndCommand($questId, $binding['commandCode']);
		$command = $request?->getCommand();
		if ($request === null || $command === null)
		{
			throw new AnonymizerException(
				'No request found for quest '
				. $questId
				. ' and command '
				. $binding['commandCode'],
			);
		}

		if (!$command instanceof $binding['command'])
		{
			throw new AnonymizerException(
				'Request command '
				. $command::class
				. ' does not match binding for provider '
				. $provider::class,
			);
		}

		if (!$resolver instanceof $binding['resolver'])
		{
			throw new AnonymizerException(
				'Resolver '
				. $resolver::class
				. ' is not compatible with command: '
				. $command::getCode(),
			);
		}

		$command->fillResolver($questId, $resolver);
	}

	public static function initAnonymizeTextCommand(TextProviderInterface $provider): CommandInterface
	{
		$command = new AnonymizeText();
		$command->setText($provider->getText());

		return $command;
	}

	public static function initAnonymizeTextFromFileProvider(FileProviderInterface $provider): CommandInterface
	{
		$command = new AnonymizeText();
		$command->setText($provider->getContent());

		return $command;
	}

	public static function processResponse(
		CommandInterface $command,
		int $questId,
		array $rawResult,
	): void
	{
		$command->processResponse($questId, $rawResult);
	}

	public static function resolveAnonymizeCommandCode(ProviderInterface $provider): ?string
	{
		$binding = self::findBindingForProvider($provider, self::getAnonymizeBindings());

		return $binding['commandCode'] ?? null;
	}

	/**
	 * @param list<OperationBinding> $bindings
	 *
	 * @return OperationBinding|null
	 */
	private static function findBindingForProvider(ProviderInterface $provider, array $bindings): ?array
	{
		$bestBinding = null;
		$bestScore = -1;

		foreach ($bindings as $binding)
		{
			if (!$provider instanceof $binding['provider'])
			{
				continue;
			}

			$score = self::bindingSpecificityScore($provider, $binding['provider']);
			if ($score > $bestScore)
			{
				$bestBinding = $binding;
				$bestScore = $score;
			}
		}

		return $bestBinding;
	}

	private static function bindingSpecificityScore(ProviderInterface $provider, string $bindingProvider): int
	{
		if ($provider::class === $bindingProvider)
		{
			return 1_000_000;
		}

		if (class_exists($bindingProvider) && is_subclass_of($provider, $bindingProvider))
		{
			$depth = 0;
			for ($class = $provider::class; $class !== false && $class !== $bindingProvider; $class = get_parent_class($class))
			{
				$depth++;
			}

			return 10_000 - $depth;
		}

		return 1;
	}
}
