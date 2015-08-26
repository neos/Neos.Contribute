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

These are the steps you have to do to manually move a gerrit patch to github. The example commands move a patch for the package TYPO3.TYPO3CR. If you have changes that are stacked on each other, repeat step 5 until all needed changes have been applied, then continue.

1. Go to your change on https://review.typo3.org
2. Bring your local repository and code up to date with the upstream repository
3. Navigate to the package directory (e.g. Packages/Neos/TYPO3.TYPO3CR/)
4. Add a new branch.
5. Patch your code using `git am` after having copied the "fomat patch" git command. Example:

   `git fetch http://review.typo3.org/Packages/TYPO3.TYPO3CR refs/changes/xx/yyyyy/z && git format-patch -1 -k --stdout FETCH_HEAD | git am -k --directory TYPO3.TYPO3CR`

   Mind the added `-k` for the `format-patch` and `am` git commands, it makes sure tags like `[TASK]` are kept.
   
   If you are patching a repository that is not a "development collection", you can leave out `--directory`.
		
6. Check the result (you may need to amend the commit to fix the [<TAG>] used in the subject line if you left
   out the `-k` option)
7. Push the changes to origin/branchName
8. Go to github and open a pull request
9. Abandon the change on gerrit.

Hint: If you are using SourceTree, steps 7 and 8 can be done by using the "Create Pull Request" item in
the "Repository" menu.
