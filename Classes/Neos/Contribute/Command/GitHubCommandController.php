<?php
namespace Neos\Contribute\Command;

require(FLOW_PATH_FLOW . 'Scripts/Migrations/Tools.php');

/*                                                                        *
 * This script belongs to the TYPO3 Flow package "Neos.Contribute".       *
 *                                                                        *
 *                                                                        */

use Github\Client as GitHubClient;
use Github\Exception\RuntimeException;
use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Configuration\ConfigurationManager;
use TYPO3\Flow\Core\Migrations\Tools;
use TYPO3\Flow\Utility\Arrays;
use TYPO3\Flow\Utility\Files;

/**
 * @Flow\Scope("singleton")
 */
class GitHubCommandController extends \TYPO3\Flow\Cli\CommandController
{
    /**
     * @var GitHubClient
     */
    protected $gitHubClient;

    /**
     * @Flow\Inject
     * @var \TYPO3\Flow\Configuration\Source\YamlSource
     */
    protected $configurationSource;

    /**
     * @Flow\Inject(setting="gitHub")
     */
    protected $gitHubSettings;

    /**
     * @Flow\Inject
     * @var \TYPO3\Flow\Utility\Environment
     */
    protected $environment;

    /**
     * @Flow\Inject
     * @var \Neos\Contribute\Domain\Service\GerritService
     */
    protected $gerritService;

    /**
     * @Flow\Inject
     * @var \Neos\Contribute\Domain\Service\GitHubService
     */
    protected $gitHubService;

    /**
     * @return void
     */
    public function initializeObject()
    {
        $this->gitHubClient = new GithubClient();
    }

    /**
     * Interactively prepares your repositories
     *
     * The setup wizard interactively configures your flow and neos forks and will also create the forks for you if needed.
     * It further renames the original remotes to "upstream" and adds your fork as remote with name "origin".
     *
     * @return void
     */
    public function setupCommand()
    {
        $this->outputLine("
<b>Welcome to Flow / Neos Development</b>
This wizard gets your environment up and running to easily contribute
code or documentation to the Neos Project.\n");

        $this->setupAccessToken();
        $this->setupFork('flow');
        
        // Only setup Neos if this is a Neos distribution
        $neosCollectionPath = Files::concatenatePaths(array(
            FLOW_PATH_PACKAGES,
            (string)Arrays::getValueByPath($this->gitHubSettings, 'origin.repositories.neos.packageDirectory')
        ));
        if (is_dir($neosCollectionPath)) {
            $this->setupFork('neos');
        }

        $this->outputLine("\n<success>Everything is set up correctly.</success>");
    }

    /**
     * Transfers a Gerrit patch to a Github pull request.
     *
     * @param integer $patchId The Gerrit Change-ID
     * @return void
     */
    public function applyGerritChangeCommand($patchId)
    {
        $this->output->ask("Make sure your development collection is set up correctly and that the master branch is clean and synced with upstream master before continuing.\nAlso make sure <b>php-cs-fixer</b> is installed globally.\n\nPress any key to continue.");

        $this->outputLine('');
        $this->outputLine('If anything goes wrong, go to the development collection and checkout a clean master branch.');

        $this->outputLine('');
        $this->outputLine('Requesting patch details from Gerrit.');
        $package = $this->gerritService->getPatchTargetPackage($patchId);
        $packageKey = $package->getPackageKey();
        $packagePath = $package->getPackagePath();
        $collectionPath = dirname($packagePath);

        $this->outputLine(sprintf('Determined <b>%s</b> as the target package key for this change.', $packageKey));

        $commitDetails = $this->gerritService->getCommitDetails($patchId);

        $oldParentCommitId = $commitDetails['parents'][0]['commit'];
        $newParentCommitId = trim($this->executeCommand(sprintf('git log --grep=%s --pretty=format:%%H', $oldParentCommitId), $collectionPath));
        if (!$newParentCommitId) {
            $this->outputLine('<error>Unable to find old parent commit ID for change in development collection.</error>');
            return;
        }
        $this->outputLine(sprintf('<success>Found old parent commit ID for change in development collection.</success>', $packageKey));
        $this->outputLine('');

        $cleanRepository = trim($this->executeCommand('git status --porcelain', $collectionPath));
        if ($cleanRepository) {
            $this->outputLine(sprintf('<error>Development collection "%s" not clean.</error>', $collectionPath));
            return;
        }

        $this->outputLine('');
        $this->outputLine('Creating new branch for change.');
        $this->output($this->executeCommand('git checkout master', $collectionPath));
        $this->output($this->executeCommand('git pull', $collectionPath));
        $this->output($this->executeCommand(sprintf('git branch -f gerrit-%s', $patchId), $collectionPath));
        $this->output($this->executeCommand(sprintf('git checkout gerrit-%s', $patchId), $collectionPath));
        $this->output($this->executeCommand(sprintf('git reset --hard %s', $newParentCommitId), $collectionPath));
        $this->outputLine('<success>Successfully created new branch for change.</success>');

        $this->outputLine('');
        $this->outputLine('Fetching change from Gerrit.');
        $patchPathAndFileName = $this->gerritService->getPatchFromGerrit($patchId);
        $this->outputLine('<success>Successfully fetched change from Gerrit.</success>');

        $this->outputLine(sprintf('The following changes will be applied to package <b>%s</b>', $packageKey));
        $this->output($this->executeCommand(sprintf('git apply --directory %s --check %s', $packageKey, $patchPathAndFileName), $collectionPath));
        $this->output($this->executeCommand(sprintf('git apply --directory %s --stat %s', $packageKey, $patchPathAndFileName), $collectionPath));

        $this->outputLine('');
        $this->outputLine('Applying change in new branch.');
        $this->output($this->executeCommand(sprintf('git am --directory %s %s', $packageKey, $patchPathAndFileName), $collectionPath));
        $this->outputLine(sprintf('<success>Successfully applied patch %1$s in branch "gerrit-%1$s"</success>', $patchId));

        $this->outputLine('');
        $this->outputLine(sprintf('<b>Converting change to PSR-2 and updating license header</b>', $patchId));
        $modifiedFiles = array_filter(explode(PHP_EOL, $this->executeCommand('git diff HEAD~1 --name-only', $collectionPath)));
        $this->executeCommand('git reset --soft HEAD~1', $collectionPath);
        foreach ($modifiedFiles as $modifiedFile) {
            if (pathinfo($modifiedFile, PATHINFO_EXTENSION) === 'php') {
                $this->output($this->executeCommand(sprintf('php-cs-fixer fix --level=psr2 %s', $modifiedFile), $collectionPath, true));
                Tools::searchAndReplace('/\/\*{1}([\s\S]+?)(script belongs)([\s\S]+?)\*\//', '/*
 * This file is part of the ' . $packageKey . ' package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */', $collectionPath . DIRECTORY_SEPARATOR . $modifiedFile, true);
            }
        }

        $this->outputLine('');
        $this->outputLine(sprintf('<b>Stashing change, resetting branch top master branch tip and applying change</b>', $patchId));
        $this->executeCommand('git stash', $collectionPath);
        $this->executeCommand('git reset --hard master', $collectionPath);
        $stashApplyingOutput = trim($this->executeCommand('git stash pop', $collectionPath, true));

        $commitMessage = preg_replace('/\n\n\n/', "\n", preg_replace('/(Releases|Change-Id):.+/', '', $commitDetails['message']));
        if (strpos($stashApplyingOutput, 'CONFLICT') !== -1) {
            $this->output('<error>' . $stashApplyingOutput . '</error>');
            $this->outputLine('');
            $this->outputLine('');
            $this->outputLine('<success>Change applied with <error>conflicts</error>.</success>');
            $this->outputLine('');
            $this->outputLine('<b>Next steps:</b>');
            $this->outputLine('');
            $this->outputLine(sprintf('<b>1. Go to "%s" where the branch "gerrit-%s" is checked out with the changes applied.</b>', $collectionPath, $patchId));
            $this->outputLine('');
            $this->outputLine('<b>2. Fix the conflicts and create new commit with the following commit message:</b>');
            $this->output($commitMessage);
            $this->outputLine('');
            $this->outputLine('<b>3. Push the new branch to your personal fork and create a new pull request from it.</b>');
        } else {
            $this->outputLine('');
            $this->outputLine('');
            $this->outputLine('<success>Change applied on master <b>without</b> conflicts.</success>');
            $this->outputLine('');
            $this->outputLine('<b>Next steps:</b>');
            $this->outputLine('');
            $this->outputLine(sprintf('<b>1. Go to "%s" where the branch "gerrit-%s" is checked out with the changes applied.</b>', $collectionPath, $patchId));
            $this->outputLine('');
            $this->outputLine('<b>2. Fix the conflicts and create new commit with the following commit message:</b>');
            $this->outputLine('');
            $this->outputLine('<b>3. Push the new branch to your personal fork and create a new pull request from it.</b>');
        }
    }

    /**
     * Create a pull request for the given patch
     *
     * @param string $repository
     * @param string $patchId
     * @return void
     */
    protected function createPullRequest($repository, $patchId)
    {
        $commitDetails = $this->gerritService->getCommitDetails($patchId);
        $commitDetails['message'] = str_replace($commitDetails['subject'], '', $commitDetails['message']);

        $result = $this->gitHubService->createPullRequest($repository, $patchId, $commitDetails['subject'], $commitDetails['message']);
        $patchUrl = $result['html_url'];

        $this->outputLine(sprintf('<success>Successfully opened a pull request </success><b>%s</b><success> for patch %s </success>', $patchUrl, $patchId));
    }

    /**
     * @return void
     */
    protected function setupAccessToken()
    {
        if ((string)Arrays::getValueByPath($this->gitHubSettings, 'contributor.accessToken') === '') {
            $this->outputLine("In order to perform actions on GitHub, you have to configure an access token. \nThis can be done on <u>https://github.com/settings/tokens/new.</u>. \nNote that this wizard only needs the 'public_repo' scope.");
            $gitHubAccessToken = $this->output->askHiddenResponse('Please enter your GitHub access token (will not be displayed): ');
            $this->gitHubSettings = Arrays::setValueByPath($this->gitHubSettings, 'contributor.accessToken', $gitHubAccessToken);
        }

        try {
            $this->gitHubService->setGitHubSettings($this->gitHubSettings)->authenticate();
        } catch (\Exception $exception) {
            $this->outputLine(sprintf('<error>%s</error>', $exception->getMessage()));
            $this->sendAndExit($exception->getCode());
        }
        $this->outputLine('<success>Authentication to GitHub was successful!</success>');
        $this->saveUserSettings();
    }

    /**
     * @param string $collectionName
     * @return void
     */
    protected function setupFork($collectionName)
    {
        $contributorRepositoryName = (string)Arrays::getValueByPath($this->gitHubSettings, sprintf('contributor.repositories.%s.name', $collectionName));
        if ($contributorRepositoryName !== '') {
            if ($this->gitHubService->checkRepositoryExists($contributorRepositoryName)) {
                $this->outputLine(sprintf('<success>A fork of the %s dev-collection was found in your github account!</success>', $collectionName));
                $this->setupRemotes($collectionName);
                return;
            } else {
                $this->outputLine(sprintf('A fork of %s was configured, but was not found in your GitHub account.', $collectionName));
            }
        }

        $originOrganization = Arrays::getValueByPath($this->gitHubSettings, 'origin.organization');
        $originRepository = Arrays::getValueByPath($this->gitHubSettings, sprintf('origin.repositories.%s.name', $collectionName));

        $this->outputLine(sprintf("\n<b>Setup %s Development Repository</b>", ucfirst($collectionName)));

        if ($this->output->askConfirmation(sprintf('Do you already have a fork of the %s Development Collection? (y/N): ', ucfirst($collectionName)), false)) {
            $contributorRepositoryName = $this->output->ask('Please provide the name of your fork (without your username): ');
            if (!$this->gitHubService->checkRepositoryExists($contributorRepositoryName)) {
                $this->outputLine(sprintf('<error>The fork %s was not found in your repository. Please start again</error>', $contributorRepositoryName));
            }

            $this->gitHubSettings = Arrays::setValueByPath($this->gitHubSettings, sprintf('contributor.repositories.%s.name', $collectionName), $contributorRepositoryName);
        } else {
            if ($this->output->askConfirmation(sprintf('Should I fork the %s Development Collection into your GitHub Account? (Y/n): ', ucfirst($collectionName)), true)) {
                $contributorRepositoryName = $originRepository;

                try {
                    $response = $this->gitHubService->forkRepository($originOrganization, $originRepository);
                } catch (RuntimeException $exception) {
                    $this->outputLine(sprintf('Error while forking %s/%s: <error>%s</error>', $originOrganization, $originRepository, $exception->getMessage()));
                    $this->sendAndExit($exception->getCode());
                }

                $this->outputLine(sprintf('<success>Successfully forked %s/%s to %s</success>', $originOrganization, $originRepository, $response['html_url']));
                $this->gitHubSettings = Arrays::setValueByPath($this->gitHubSettings, sprintf('contributor.repositories.%s.name', $collectionName), $contributorRepositoryName);
            }
        }

        $this->saveUserSettings();

        $this->setupRemotes($collectionName);
    }

    /**
     * Renames the original repository to upstream
     * and adds the own fork as origin
     *
     * @param string $collectionName
     * @return void
     * @throws \Exception
     */
    protected function setupRemotes($collectionName)
    {
        $packageCollectionPath = Files::concatenatePaths(array(
            FLOW_PATH_PACKAGES,
            (string)Arrays::getValueByPath($this->gitHubSettings, sprintf('origin.repositories.%s.packageDirectory', $collectionName))
        ));

        $originRepositoryName = (string) Arrays::getValueByPath($this->gitHubSettings, sprintf('origin.repositories.%s.name', $collectionName));
        $contributorRepositoryName = (string) Arrays::getValueByPath($this->gitHubSettings, sprintf('contributor.repositories.%s.name', $collectionName));
        $sshUrl = $this->gitHubService->buildSSHUrlForRepository($contributorRepositoryName);

        $this->executeCommand('git remote rm origin', $packageCollectionPath, true);
        $this->executeCommand('git remote add origin ' . $sshUrl, $packageCollectionPath);
        $this->executeCommand('git remote rm upstream', $packageCollectionPath, true);
        $this->executeCommand(sprintf('git remote add upstream git://github.com/%s/%s.git', $this->gitHubSettings['origin']['organization'], $originRepositoryName), $packageCollectionPath);
        $this->executeCommand('git config --add remote.upstream.fetch +refs/pull/*/head:refs/remotes/upstream/pr/*', $packageCollectionPath);
    }

    /**
     * @return void
     */
    protected function saveUserSettings()
    {
        $frameworkConfiguration = $this->configurationSource->load(FLOW_PATH_CONFIGURATION . ConfigurationManager::CONFIGURATION_TYPE_SETTINGS);
        $frameworkConfiguration = Arrays::setValueByPath($frameworkConfiguration, 'Neos.Contribute.gitHub', $this->gitHubSettings);
        $this->configurationSource->save(FLOW_PATH_CONFIGURATION . ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, $frameworkConfiguration);
    }

    /**
     * @param string $directoryPath
     * @return string
     */
    protected function getGitRemoteRepositoryOfDirectory($directoryPath)
    {
        $remoteInfo = $this->executeCommand('git remote show origin', $directoryPath);
        preg_match('/Fetch.*(flow-development-collection|neos-development-collection)\.git/', $remoteInfo, $matches);
        return $matches[1];
    }

    /**
     * @param string $command
     * @param string $workingDirectory
     * @param boolean $force
     * @return string
     */
    protected function executeCommand($command, $workingDirectory, $force = false)
    {
        $cwd = getcwd();
        if (@chdir($workingDirectory) === false) {
            $this->outputLine(sprintf('<error>Directory "%s" does not exist, maybe your git remotes are not configured correctly, try to run ./flow github:setup</error>\n', $workingDirectory));
            $this->sendAndExit(1);
        }

        $this->outputLine(sprintf('» [%s] %s', $workingDirectory, $command));

        exec($command . ' 2>&1', $output, $returnValue);
        chdir($cwd);
        $outputString = implode("\n", $output) . "\n";

        if ($returnValue !== 0 && $force === false) {
            $this->outputLine(sprintf("<error>%s</error>\n", $outputString));
            $this->sendAndExit(1);
        }

        return $outputString;
    }
}
