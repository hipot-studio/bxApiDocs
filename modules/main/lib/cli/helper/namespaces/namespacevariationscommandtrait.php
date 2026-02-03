<?php

namespace Bitrix\Main\Cli\Helper\Namespaces;

use Bitrix\Main\Cli\Helper\AskQuestionTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

trait NamespaceVariationsCommandTrait
{
	use AskQuestionTrait;

	private static $PREFIX_OPTION_NAME = 'prefix';
	private static $CONTEXT_OPTION_NAME = 'context';
	private static $NAMESPACE_OPTION_NAME = 'namespace';

	protected static function configureNamespaceVariationsOptions(Command $command): void
	{
		$command->addOption(self::$PREFIX_OPTION_NAME, 'P', InputArgument::OPTIONAL, 'Prefix for the standard namespace for the generated class');

		$command->addOption(self::$CONTEXT_OPTION_NAME, 'C', InputArgument::OPTIONAL, 'Namespace of context for the generated class');

		// in the future, if it suddenly hurts a lot without a custom
		//$command->addOption(self::$NAMESPACE_OPTION_NAME, 'N', InputArgument::OPTIONAL, 'Namespace in which the generated class will be placed');
	}

	protected function fillNamespaceVariationsOptions(NamespaceVariationsDto $dto, InputInterface $input, OutputInterface $output): void
	{
		$prefix = $this->askOrGetOption(self::$PREFIX_OPTION_NAME, $input, $output, false);
		if (!empty($prefix))
		{
			$dto->setPrefixForStandardNamespace(
				(string)$prefix,
			);
		}

		$context = $this->askOrGetOption(self::$CONTEXT_OPTION_NAME, $input, $output, false);
		if (!empty($context))
		{
			$dto->setPrefixForContext(
				(string)$context,
			);
		}

		//$namespace = $this->askOrGetOption(self::$NAMESPACE_OPTION_NAME, $input, $output, false);
		//if (!empty($namespace))
		//{
		//	$dto->setCustomNamespace(
		//		(string)$namespace,
		//	);
		//}
	}
}
