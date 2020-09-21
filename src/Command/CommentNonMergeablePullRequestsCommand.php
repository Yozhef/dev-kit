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
use App\Github\Api\Comments;
use App\Github\Api\Commits;
use App\Github\Api\Issues;
use App\Github\Api\PullRequests;
use App\Github\Domain\Value\Label;
use Github\Client as GithubClient;
use Github\Exception\ExceptionInterface;
use Github\ResultPagerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Sullivan Senechal <soullivaneuh@gmail.com>
 * @author Oskar Stark <oskarstark@googlemail.com>
 */
final class CommentNonMergeablePullRequestsCommand extends AbstractNeedApplyCommand
{
    private Projects $projects;
    private PullRequests $pullRequests;
    private Comments $comments;
    private Commits $commits;
    private Issues $issues;
    private GithubClient $github;
    private ResultPagerInterface $githubPager;

    public function __construct(Projects $projects, PullRequests $pullRequests, Comments $comments, Commits $commits, Issues $issues, GithubClient $github, ResultPagerInterface $githubPager)
    {
        parent::__construct();

        $this->projects = $projects;
        $this->pullRequests = $pullRequests;
        $this->comments = $comments;
        $this->commits = $commits;
        $this->issues = $issues;
        $this->github = $github;
        $this->githubPager = $githubPager;
    }

    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('comment-non-mergeable-pull-requests')
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
        $repository = $project->repository();

        foreach ($this->pullRequests->all($repository) as $pr) {
            if (false === $pr->isMergeable()) {
                $comments = array_filter(
                    $this->githubPager->fetchAll($this->github->issues()->comments(), 'all', [
                        $repository->vendor(),
                        $repository->name(),
                        $pr->issue()->toInt(),
                    ]),
                    static function ($comment) {
                        return $comment['user']['login'] === static::GITHUB_USER;
                    }
                );
                $lastComment = end($comments);
                $lastCommentDate = $lastComment ? new \DateTime($lastComment['created_at']) : null;

                $lastCommit = $this->commits->lastCommit(
                    $repository,
                    $pr
                );

                if (!$lastCommentDate || $lastCommentDate < $lastCommit->date()) {
                    if ($this->apply) {
                        $this->comments->create(
                            $repository,
                            $pr->issue(),
                            'Could you please rebase your PR and fix merge conflicts?'
                        );

                        $this->issues->addLabel(
                            $repository,
                            $pr->issue(),
                            Label::PendingAuthor()
                        );
                    }

                    $this->io->text(sprintf(
                        '#%d - %s',
                        $pr->issue()->toInt(),
                        $pr->title()
                    ));
                }
            }
        }
    }
}
