<?php

/*
 * This file is part of the Eventum (Issue Tracking System) package.
 *
 * @copyright (c) Eventum Team
 * @license GNU General Public License, version 2 or later (GPL-2+)
 *
 * For the full copyright and license information,
 * please see the COPYING and AUTHORS files
 * that were distributed with this source code.
 */

namespace Eventum\Test;

use Eventum\Markdown;

class MarkdownTest extends TestCase
{
    /** @var Markdown */
    private $renderer;

    public function setUp(): void
    {
        $this->renderer = new Markdown();
    }

    /**
     * @dataProvider dataProvider
     */
    public function test1(string $expected, string $input): void
    {
        $rendered = $this->renderer->render($input);
        $this->assertEquals($expected, $rendered);
    }

    public function dataProvider(): array
    {
        return [
            'simple' => [
                "<h1>closed h1</h1>\n",
                '# closed h1 #',
            ],
            'https://github.com/cebe/markdown/issues/157#issuecomment-385439965' => [
                "<p>here is a <a href=\"http://github.com\">linkref</a>.<br />\nand <a href=\"http://google.com\">inline</a></p>\n",
                "here is a [linkref].\nand [inline](http://google.com)\n\n[linkref]: http://github.com",
            ],
        ];
    }
}
