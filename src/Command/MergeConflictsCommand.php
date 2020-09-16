<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Command;

use App\Config\Projects;
use App\Domain\Value\Project;
use App\Github\Domain\Value\Label;
use App\Util\Util;
use Github\Client as GithubClient;
use Github\Exception\ExceptionInterface;
use Github\ResultPagerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Sullivan Senechal <soullivaneuh@gmail.com>
 */
final class MergeConflictsCommand extends AbstractNeedApplyCommand
{
    private Projects $projects;
    private GithubClient $github;
    private ResultPagerInterface $githubPager;

    public function __construct(Projects $projects, GithubClient $github, ResultPagerInterface $githubPager)
    {
        parent::__construct();

        $this->projects = $projects;
        $this->github = $github;
        $this->githubPager = $githubPager;
    }

    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('merge-conflicts')
            ->setDescription('Comments non-mergeable pull requests, asking the author to solve conflicts.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io->title('Comment non-mergable pull requests');

        /** @var Project $project */
        foreach ($this->projects->all() as $project) {
            try {
                $this->io->section($project->name());

                $this->checkPullRequests($project);
            } catch (ExceptionInterface $e) {
                $this->io->error(sprintf(
                    'Failed with message: %s',
                    $e->getMessage()
                ));
            }
        }

        return 0;
    }

    private function checkPullRequests(Project $project): void
    {
        $package = $project->package();

        $repositoryName = Util::getRepositoryNameWithoutVendorPrefix($package);

        foreach ($this->github->pullRequests()->all(static::GITHUB_GROUP, $repositoryName) as $pullRequest) {
            $number = $pullRequest['number'];
            $pullRequest = $this->github->pullRequests()->show(static::GITHUB_GROUP, $repositoryName, $number);

            // The value of the mergeable attribute can be true, false, or null.
            // If the value is null this means that the mergeability hasn't been computed yet.
            // @see: https://developer.github.com/v3/pulls/#get-a-single-pull-request
            if (false === $pullRequest['mergeable']) {
                $comments = array_filter(
                    $this->githubPager->fetchAll($this->github->issues()->comments(), 'all', [
                        static::GITHUB_GROUP,
                        $repositoryName,
                        $number,
                    ]),
                    static function ($comment) {
                        return $comment['user']['login'] === static::GITHUB_USER;
                    }
                );
                $lastComment = end($comments);
                $lastCommentDate = $lastComment ? new \DateTime($lastComment['created_at']) : null;

                $commits = $this->githubPager->fetchAll($this->github->pullRequest(), 'commits', [
                    static::GITHUB_GROUP,
                    $repositoryName,
                    $number,
                ]);
                $lastCommit = end($commits);
                $lastCommitDate = new \DateTime($lastCommit['commit']['committer']['date']);

                if (!$lastCommentDate || $lastCommentDate < $lastCommitDate) {
                    if ($this->apply) {
                        $this->github->issues()->comments()->create(static::GITHUB_GROUP, $repositoryName, $number, [
                            'body' => 'Could you please rebase your PR and fix merge conflicts?',
                        ]);

                        $this->github->issues()->labels()->add(
                            static::GITHUB_GROUP,
                            $repositoryName,
                            $number,
                            Label::PendingAuthor()->toString()
                        );
                    }

                    $this->io->text(sprintf(
                        '#%d - %s',
                        $pullRequest['number'],
                        $pullRequest['title']
                    ));
                }
            }
        }
    }
}
