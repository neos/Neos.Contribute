<?php
namespace Neos\Contribute\Command;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "Neos.Contribute".       *
 *                                                                        *
 *                                                                        */

use Github\Client as GitHubClient;
use Github\Exception\RuntimeException;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Configuration\ConfigurationManager;
use TYPO3\Flow\Utility\Arrays;

/**
 * @Flow\Scope("singleton")
 */
class GitHubCommandController extends \TYPO3\Flow\Cli\CommandController {

	/**
	 * @var \TYPO3\Flow\Configuration\Source\YamlSource
	 * @Flow\Inject
	 */
	protected $configurationSource;

	/**
	 @Flow\Inject(setting="gitHub")
	 */
	protected $gitHubSettings;

	/**
	 * @Flow\inject
	 * @var \TYPO3\Flow\Utility\Environment
	 */
	protected $environment;



	/**
	 * @var GitHubClient
	 */
	protected $gitHubClient;


	/**
	 * @Flow\inject
	 * @var \Neos\Contribute\Domain\Service\GerritService
	 */
	protected $gerritService;


	/**
	 * @Flow\inject
	 * @var \Neos\Contribute\Domain\Service\GitHubService
	 */
	protected $gitHubService;


	public function initializeObject() {
		$this->gitHubClient = new GithubClient();
	}


	public function setupCommand() {

		$this->output("
<b>Welcome To Flow / Neos Development</b>
This wizzard gets your environemnt up and running to easily contribute
code or documentation to the Neos project.
All you need is a github.com account. \n\n");

		$this->setupGitHubSettings();

//		$answer = $this->output->askConfirmation('Do you want to fork the flow development repository into your github account? ');
//
//		if($answer === TRUE) {
//			$this->forkRepository(
//				Arrays::getValueByPath($this->gitHubSettings, 'origin.organization'),
//				Arrays::getValueByPath($this->gitHubSettings, 'repositories.flow.origin')
//			);
//		}
//
//		$this->saveUserSettings();
	}




	public function setupGitHubSettings() {

		$this->setupAccessToken();
		$this->setupFork('flow');
		$this->setupFork('neos');

	}



	protected function setupAccessToken() {

		if ((string)Arrays::getValueByPath($this->gitHubSettings, 'contributer.accessToken') === '') {
			$this->outputLine("In order to perform actions on GitHub, you have to configure an access token. \nThis can be done on <u>https://github.com/settings/tokens/new.</u>");
			$gitHubAccessToken = $this->output->askHiddenResponse('Please enter your gitHub access token (will not be displayed): ');
			$this->gitHubSettings = Arrays::setValueByPath($this->gitHubSettings, 'contributer.accessToken', $gitHubAccessToken);

			try {
				$this->gitHubService->setGitHubSettings($this->gitHubSettings)->authenticateToGitHub();
			} catch (\Exception $exception) {
				$this->outputLine(sprintf("<error>%s</error>", $exception->getMessage()));
				$this->sendAndExit($exception->getCode());
			}

			$this->saveUserSettings();
		}
	}


	protected function setupFork($collectionName) {

		if ((string)Arrays::getValueByPath($this->gitHubSettings, sprintf('contributer.repositories.%s', $collectionName)) !== '') {
			return;
		}

		$originOrganization = Arrays::getValueByPath($this->gitHubSettings, 'origin.organization');
		$originRepository = Arrays::getValueByPath($this->gitHubSettings, sprintf('origin.repositories.%s', $collectionName));

		$this->outputLine(sprintf("\n<b>Setup %s Development Repository</b>", ucfirst($collectionName)));

		if ($this->output->askConfirmation(sprintf('Do you already have a fork of the %s Development Collection? (y/N): ', ucfirst($collectionName)), FALSE)) {
			$contributerForkName = $this->output->ask('Please provide the name of your fork: ');
			$this->gitHubSettings = Arrays::setValueByPath($this->gitHubSettings, sprintf('contributer.repositories.%s', $collectionName), $contributerForkName);
		} else {
			if ($this->output->askConfirmation(sprintf('Should I fork the %s Development Collection into your GitHub Account? (Y/n): ', ucfirst($collectionName)), TRUE)) {
				$contributerForkName = ucfirst($collectionName);

				try {
					$response = $this->gitHubService->forkRepository($originOrganization, $originRepository);
				} catch (RuntimeException $exception) {
					$this->outputLine(sprintf('Error while forking %s/%s: <error>%s</error>', $originOrganization, $originRepository, $exception->getMessage()));
					$this->sendAndExit($exception->getCode());
				}

				$this->outputLine(sprintf('<success>Successfully forked %s/%s to %s</success>', $originOrganization, $originRepository, $response['html_url']));
				$this->gitHubSettings = Arrays::setValueByPath($this->gitHubSettings, sprintf('contributer.repositories.%s', $collectionName), $contributerForkName);
			}
		}

		$this->saveUserSettings();
	}




	/**
	 * @param integer $patchId The gerrit patchset id
	 */
	public function createPullRequestFromGerritCommand($patchId) {

		$this->outputLine('Requesting Patch Details from gerrit.');
		$package = $this->gerritService->getPatchTargetPackage($patchId);
		$this->outputLine(sprintf('Determined %s as the target package key for this change.', $package));

		$patchPathAndFileName = $this->gerritService->getPatchFromGerrit($patchId);
		$this->outputLine('Successfully fetched changeset from gerrit');

		$this->outputLine(sprintf('The following changes will be applied to package <b>%s</b>', $package->getPackageKey()));

		$this->output($this->executeGitCommand(sprintf('git apply --check %s', $patchPathAndFileName), $package->getPackagePath()));
		$this->output($this->executeGitCommand(sprintf('git apply --stat %s', $patchPathAndFileName), $package->getPackagePath()));

		if(!$this->output->askConfirmation('Would you like to apply this patch? ', FALSE)) {
			return;
		}

		$this->output($this->executeGitCommand(sprintf('git am < %s', $patchPathAndFileName), $package->getPackagePath()));
		$this->output(sprintf('<success>Successfully Applied patch %s</success>', $patchId));

		if(!$this->output->askConfirmation('Would you like to push the change to your default remote repository? ', FALSE)) {
			return;
		}
	}










	protected function saveUserSettings() {
		$frameworkConfiguration = $this->configurationSource->load(FLOW_PATH_CONFIGURATION . ConfigurationManager::CONFIGURATION_TYPE_SETTINGS);
		$frameworkConfiguration = Arrays::setValueByPath($frameworkConfiguration, 'Neos.Contribute.gitHub', $this->gitHubSettings);
		$this->configurationSource->save(FLOW_PATH_CONFIGURATION . ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, $frameworkConfiguration);
	}



	/**
	 * @param $workingDirectory
	 * @param $command
	 * @return mixed
	 * @throws \RuntimeException
	 */
	protected function executeGitCommand($command, $workingDirectory) {
		$cwd = getcwd();
		chdir($workingDirectory);

		// $this->outputLine(sprintf('DEBUG: Exec Git Command %s in WD %s', $command, $workingDirectory));

		exec($command . " 2>&1", $output, $returnValue);
		chdir($cwd);
		$outputString = implode("\n", $output) . "\n";

		if ($returnValue !== 0) {
			$this->outputLine(sprintf("<error>%s</error>\n", $outputString));
			$this->sendAndExit(1);
		}

		return $outputString;
	}



}