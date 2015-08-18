### Setup your configuration

The setup wizard interactively configures your flow and neos forks and will also create the forks for you if needed. 
It further renames the original remotes to "upstream" and adds your fork as remote with name "origin".  

	./flow github:setup


### Transfer a gerrit change to github

This command transfers a gerrit patch to a github pull request. In detail it does the following steps:

1. Pull the change as patch from gerrit
2. Create a new branch for that patch from upstream/master
3. Patch the code and create a new commit
4. Push the commit to your fork on github
5. Create a pull request
6. Switch local repository back to master


	./flow github:createPullRequestFromGerrit <gerritPatchId>
