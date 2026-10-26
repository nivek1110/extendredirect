<?php

declare(strict_types=1);

namespace Nivek1110\Extendredirect\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;

/**
 * The extension icon is shown in the Extension Manager, on the TER page and as the icon of the
 * disable log table. It must be a self-contained, small, square SVG that says what it is.
 */
class ExtensionIconTest extends TestCase
{
    private const ICON = __DIR__ . '/../../../Resources/Public/Icons/Extension.svg';

    private function load(): \DOMDocument
    {
        $document = new \DOMDocument();
        self::assertTrue($document->load(self::ICON), 'the icon is well-formed XML');

        return $document;
    }

    public function testIconExistsAndIsSmall(): void
    {
        self::assertFileExists(self::ICON);
        self::assertLessThan(8 * 1024, filesize(self::ICON), 'keep the icon below 8 KB');
    }

    public function testIconIsASquareSvg(): void
    {
        $root = $this->load()->documentElement;

        self::assertNotNull($root);
        self::assertSame('svg', $root->localName);
        self::assertSame('http://www.w3.org/2000/svg', $root->namespaceURI);
        $viewBox = preg_split('/\s+/', trim($root->getAttribute('viewBox')));
        self::assertIsArray($viewBox);
        self::assertCount(4, $viewBox, 'a viewBox is required so the icon scales');
        self::assertSame($viewBox[2], $viewBox[3], 'the icon is square');
    }

    public function testIconHasATitleThatNamesTheExtension(): void
    {
        $titles = $this->load()->getElementsByTagName('title');

        self::assertSame(1, $titles->length);
        self::assertSame('Extend Redirect', trim((string) $titles->item(0)?->textContent));
    }

    public function testIconIsSelfContainedAndSafe(): void
    {
        $svg = (string) file_get_contents(self::ICON);

        self::assertDoesNotMatchRegularExpression('/<script|<foreignObject|<image|onload=|onclick=/i', $svg);
        self::assertDoesNotMatchRegularExpression('#(?:href|src)\s*=\s*["\'](?:https?:)?//#i', $svg, 'no external references');
        self::assertDoesNotMatchRegularExpression('/url\(\s*["\']?https?:/i', $svg);
    }
}
