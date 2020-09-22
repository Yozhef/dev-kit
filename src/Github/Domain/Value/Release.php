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

namespace App\Github\Domain\Value;

use Webmozart\Assert\Assert;

/**
 * @author Oskar Stark <oskarstark@googlemail.com>
 */
final class Release
{
    private string $tagName;
    private \DateTimeImmutable $publishedAt;

    private function __construct(string $tagName, \DateTimeImmutable $publishedAt)
    {
        $tagName = trim($tagName);
        Assert::stringNotEmpty($tagName);

        $this->tagName = $tagName;
        $this->publishedAt = $publishedAt;
    }

    public static function fromResponse(array $response): self
    {
        Assert::notEmpty($response);

        Assert::keyExists($response, 'tag_name');
        Assert::stringNotEmpty($response['tag_name']);

        Assert::keyExists($response, 'published_at');
        Assert::stringNotEmpty($response['published_at']);

        return new self(
            $response['tag_name'],
            new \DateTimeImmutable($response['published_at'])
        );
    }

    public function tagName(): string
    {
        return $this->tagName;
    }

    public function publishedAt(): \DateTimeImmutable
    {
        return $this->publishedAt;
    }
}