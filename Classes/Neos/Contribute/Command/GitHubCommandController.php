<?php
namespace Neos\Contribute\Command;

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "Neos.Contribute".       *
 *                                                                        *
 *                                                                        */

use Github\Client as GitHubClient;
use Github\Exception\RuntimeException;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Composer\Exception\InvalidConfigurationException;
use TYPO3\Flow\Configuration\ConfigurationManager;
use TYPO3\Flow\Utility\Arrays;
use TYPO3\Flow\Utility\Files;

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


	/**
	 * Interactively prepares your repositories
	 *
	 * The setup wizard interactively configures your flow and neos forks and will also create the forks for you if needed.
	 * It further renames the original remotes to "upstream" and adds your fork as remote with name "origin".
	 */
	public function setupCommand() {

		$this->outputLine("
<b>Welcome To Flow / Neos Development</b>
This wizzard gets your environemnt up and running to easily contribute
code or documentation to the Neos project.\n");

		$this->setupAccessToken();
		$this->setupFork('flow');
		$this->setupFork('neos');

		$this->outputLine("\n<success>Everything is set up correctly.</success>");
	}



	/**
	 * Transfers a gerrit patch to a github pull request.
	 *
	 * @param integer $patchId The gerrit patchset id
	 */
	public function createPullRequestFromGerritCommand($patchId) {

		try {
			$this->gitHubService->authenticate();
		} catch (InvalidConfigurationException $e) {
			$this->outputLine('<error>It was not possible to authenticate with github.</error>');
			$this->outputLine('Please run <b>./flow github:setup</b> first');
		}

		$this->outputLine('Requesting Patch Details from gerrit.');
		$package = $this->gerritService->getPatchTargetPackage($patchId);
		$packageKey = $package->getPackageKey();
		$packagePath = $package->getPackagePath();

		$this->outputLine(sprintf('Determined <b>%s</b> as the target package key for this change.', $packageKey));

		$patchPathAndFileName = $this->gerritService->getPatchFromGerrit($patchId);
		$this->outputLine('Successfully fetched changeset from gerrit.');

		$this->outputLine(sprintf('The following changes will be applied to package <b>%s</b>', $packageKey));

		$this->output($this->executeGitCommand(sprintf('git apply --directory %s --check %s', $packageKey, $patchPathAndFileName), $packagePath));
		$this->output($this->executeGitCommand(sprintf('git apply --directory %s --stat %s', $packageKey, $patchPathAndFileName), $packagePath));

		if(!$this->output->askConfirmation("\nWould you like to apply this patch? (Y/n): ", TRUE)) {
			return;
		}

		$this->output($this->executeGitCommand(sprintf('git fetch upstream master'), $packagePath));
		$this->output($this->executeGitCommand(sprintf('git checkout -b %s upstream/master', $patchId), $packagePath));
		$this->output($this->executeGitCommand(sprintf('git am --directory %s %s', $packageKey, $patchPathAndFileName), $packagePath));
		$this->outputLine(sprintf('<success>Successfully Applied patch %s</success>', $patchId));

		if(!$this->output->askConfirmation("\nWould you like to push the change to your repository and create a pull request? (Y/n)", TRUE)) {
			return;
		}

		$remoteRepository = $this->getGitRemoteRepositoryOfDirectory($packagePath);
		$this->output($this->executeGitCommand(sprintf('git push origin %s', $patchId), $packagePath));
		$this->createPullRequest($remoteRepository, $patchId);

		$this->output($this->executeGitCommand(sprintf('git checkout master'), $packagePath));
	}


	/**
	 * Create a pull request for the given patch
	 *
	 * @param $repository
	 * @param $patchId
	 */
	protected function createPullRequest($repository, $patchId) {
		$commitDetails = $this->gerritService->getCommitDetails($patchId);
		$commitDetails['message'] = str_replace($commitDetails['subject'], '', $commitDetails['message']);

		$result = $this->gitHubService->createPullRequest($repository, $patchId, $commitDetails['subject'], $commitDetails['message']);
		$patchUrl = $result['html_url'];

		$this->outputLine(sprintf('<success>Successfully opened a pull request </success><b>%s</b><success> for patch %s </success>',$patchUrl, $patchId));
	}


	protected function setupAccessToken() {
		if ((string)Arrays::getValueByPath($this->gitHubSettings, 'contributor.accessToken') === '') {
			$this->outputLine("In order to perform actions on GitHub, you have to configure an access token. \nThis can be done on <u>https://github.com/settings/tokens/new.</u>");
			$gitHubAccessToken = $this->output->askHiddenResponse('Please enter your gitHub access token (will not be displayed): ');
			$this->gitHubSettings = Arrays::setValueByPath($this->gitHubSettings, 'contributor.accessToken', $gitHubAccessToken);
		}

		try {
			$this->gitHubService->setGitHubSettings($this->gitHubSettings)->authenticate();
		} catch (\Exception $exception) {
			$this->outputLine(sprintf("<error>%s</error>", $exception->getMessage()));
			$this->sendAndExit($exception->getCode());
		}
		$this->outputLine('<success>Authentication to GitHub was successful!</success>');
		$this->saveUserSettings();
	}


	/**
	 * @param string $collectionName
	 */
	protected function setupFork($collectionName) {
		$contributorRepositoryName = (string)Arrays::getValueByPath($this->gitHubSettings, sprintf('contributor.repositories.%s.name', $collectionName));
		if ($contributorRepositoryName !== '') {
			if($this->gitHubService->checkRepositoryExists($contributorRepositoryName)) {
				$this->outputLine(sprintf('<success>A fork of the %s dev-collection was found in your github account!</success>', $collectionName));
				$this->setupRemotes($collectionName);
				return;
			} else {
				$this->outputLine(sprintf('A fork of %s was configured, but was not found in your github account.', $collectionName));
			}
		}

		$originOrganization = Arrays::getValueByPath($this->gitHubSettings, 'origin.organization');
		$originRepository = Arrays::getValueByPath($this->gitHubSettings, sprintf('origin.repositories.%s.name', $collectionName));

		$this->outputLine(sprintf("\n<b>Setup %s Development Repository</b>", ucfirst($collectionName)));

		if ($this->output->askConfirmation(sprintf('Do you already have a fork of the %s Development Collection? (y/N): ', ucfirst($collectionName)), FALSE)) {
			$contributorForkName = $this->output->ask('Please provide the name of your fork: ');
			if(!$this->gitHubService->checkRepositoryExists($contributorRepositoryName)) {
				$this->outputLine(sprintf('<error>The fork %s was not found in your repository. Please start again</error>', $contributorRepositoryName));
			}

			$this->gitHubSettings = Arrays::setValueByPath($this->gitHubSettings, sprintf('contributor.repositories.%s.name', $collectionName), $contributorForkName);
		} else {
			if ($this->output->askConfirmation(sprintf('Should I fork the %s Development Collection into your GitHub Account? (Y/n): ', ucfirst($collectionName)), TRUE)) {
				$contributorForkName = $originRepository;

				try {
					$response = $this->gitHubService->forkRepository($originOrganization, $originRepository);
				} catch (RuntimeException $exception) {
					$this->outputLine(sprintf('Error while forking %s/%s: <error>%s</error>', $originOrganization, $originRepository, $exception->getMessage()));
					$this->sendAndExit($exception->getCode());
				}

				$this->outputLine(sprintf('<success>Successfully forked %s/%s to %s</success>', $originOrganization, $originRepository, $response['html_url']));
				$this->gitHubSettings = Arrays::setValueByPath($this->gitHubSettings, sprintf('contributor.repositories.%s.name', $collectionName), $contributorForkName);
			}
		}

		$this->saveUserSettings();

		$this->setupRemotes($collectionName);
	}


	/**
	 * Renames the original repository to upstream
	 * and adds the own fork as origin
	 *
	 * @param $collectionName
	 * @throws \Exception
	 */
	protected function setupRemotes($collectionName) {
		$packageCollectionPath = Files::concatenatePaths (array(
			FLOW_PATH_PACKAGES,
			(string)Arrays::getValueByPath($this->gitHubSettings, sprintf('origin.repositories.%s.packageDirectory', $collectionName))
		));

		$contributorRepositoryName = (string)Arrays::getValueByPath($this->gitHubSettings, sprintf('contributor.repositories.%s.name', $collectionName));
		$sshUrl = $this->gitHubService->buildSSHUrlForRepository($contributorRepositoryName);

		$this->executeGitCommand('git remote rm origin', $packageCollectionPath, TRUE);
		$this->executeGitCommand('git remote add origin ' . $sshUrl , $packageCollectionPath);
		$this->executeGitCommand('git remote rm upstream', $packageCollectionPath, TRUE);
		$this->executeGitCommand(sprintf('git remote add upstream git://github.com/%s/%s.git', $this->gitHubSettings['origin']['organization'], $collectionName), $packageCollectionPath);
	}



	protected function saveUserSettings() {
		$frameworkConfiguration = $this->configurationSource->load(FLOW_PATH_CONFIGURATION . ConfigurationManager::CONFIGURATION_TYPE_SETTINGS);
		$frameworkConfiguration = Arrays::setValueByPath($frameworkConfiguration, 'Neos.Contribute.gitHub', $this->gitHubSettings);
		$this->configurationSource->save(FLOW_PATH_CONFIGURATION . ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, $frameworkConfiguration);
	}


	/**
	 * @param $directoryPath
	 * @return string
	 */
	protected function getGitRemoteRepositoryOfDirectory($directoryPath) {
		$remoteInfo = $this->executeGitCommand('git remote show origin', $directoryPath);
		preg_match('/Fetch.*(flow-development-collection|neos-development-collection)\.git/', $remoteInfo, $matches);
		return $matches[1];
	}


	/**
	 * @param string $command
	 * @param string $workingDirectory
	 * @param boolean $force
	 * @return string
	 */
	protected function executeGitCommand($command, $workingDirectory, $force = FALSE) {
		$cwd = getcwd();
		chdir($workingDirectory);

		$this->outputLine(sprintf("<b>GIT</b> [%s] %s", $workingDirectory, $command));

		exec($command . " 2>&1", $output, $returnValue);
		chdir($cwd);
		$outputString = implode("\n", $output) . "\n";

		if ($returnValue !== 0 && $force === FALSE) {
			$this->outputLine(sprintf("<error>%s</error>\n", $outputString));
			$this->sendAndExit(1);
		}

		return $outputString;
	}



}