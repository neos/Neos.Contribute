### Setup your configuration

The setup wizard interactively configures your flow and neos forks and will also create the forks for you if needed. 
It further renames the original remotes to "upstream" and adds your fork as remote with name "origin".  

	./flow github:setup


### Transfer a gerrit change to github

The command 

	./flow github:createPullRequestFromGerrit <gerritPatchId>

transfers a gerrit patch to a github pull request. In detail it does the following steps:

1. Pull the change as patch from gerrit
2. Create a new branch for that patch from upstream/master
3. Patch the code and create a new commit
4. Push the commit to your fork on github
5. Create a pull request
6. Switch local repository back to master


#### Manually transfer a gerrit change to github

These are the steps you have to do to manually move a gerrit patch to github. The example commands move a patch for the package TYPO3.TYPO3CR.

1. Go to your change on https://review.typo3.org
2. Download the patch file and extract it
3. Bring your local repository and code up to date with the upstream repository
5. Navigate to the package directory (e.g. Packages/Neos/TYPO3.TYPO3CR/)
4. Add a new branch.
5. Patch your code using `git am`. Example:

		git am --directory TYPO3.TYPO3CR /tmp/GerritPatches/99194a75.diff
		
6. Commit the changes
8. Push the changes to origin/branchName
9. Go to github and open a pull request
10. Abandon the change on gerrit.