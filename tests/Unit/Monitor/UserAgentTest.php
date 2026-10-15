<?php

declare(strict_types=1);

namespace Sentinel\Tests\Monitor;

use Sentinel\Monitor\Core\UserAgent;

/**
 * Unit tests for {@see UserAgent}.
 *
 * The shape asserted here is the one an upstream's bot protection was
 * asked for — product, version, contact, deployment — so these tests
 * are deliberately literal about the punctuation. home_url() comes
 * from the wp-mocks WordPress stub group and answers
 * https://example.test/.
 */

test('the plugin identifies itself with name version contact and site', function () {
    $this->assertSame(
        'Sentinel/1.0.0 (rest@aa-bristol.org; https://example.test)',
        UserAgent::plugin(),
    );
});

it('builds the documented shape for any app', function () {
    $this->assertSame(
        'Widget/1.2.3 (rest@aa-bristol.org; https://example.test)',
        UserAgent::forApp('Widget', '1.2.3'),
    );
});

test('a missing version leaves out the slash', function () {
    // Better a product with no version than "Widget/" or an invented one.
    $this->assertSame(
        'Widget (rest@aa-bristol.org; https://example.test)',
        UserAgent::forApp('Widget'),
    );
});

test('an empty app name falls back to the plugin', function () {
    $this->assertStringStartsWith('Sentinel/1.0', UserAgent::forApp('', '1.0'));
});

test('header breaking characters are stripped', function () {
    // A newline here would be header injection; a bracket or
    // semicolon would close the comment early and leave the
    // contact details dangling outside it.
    $this->assertSame(
        'Widget/1.2.3 evil (rest@aa-bristol.org; https://example.test)',
        UserAgent::forApp('Widget', "1.2.3\r\n(evil);"),
    );
});

test('the contact is the role address', function () {
    $this->assertSame('rest@aa-bristol.org', UserAgent::CONTACT);
});
