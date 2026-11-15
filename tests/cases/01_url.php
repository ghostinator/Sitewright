<?php
use RankMathWebapp\Core\Url;

// RFC 3986 §5.4 examples (base http://a/b/c/d;p?q)
$base = 'http://a/b/c/d;p?q';
foreach ([
    'g' => 'http://a/b/c/g', './g' => 'http://a/b/c/g', 'g/' => 'http://a/b/c/g/', '/g' => 'http://a/g',
    '//g' => 'http://g', '?y' => 'http://a/b/c/d;p?y', 'g?y' => 'http://a/b/c/g?y', '#s' => 'http://a/b/c/d;p?q',
    '' => 'http://a/b/c/d;p?q', '.' => 'http://a/b/c/', '..' => 'http://a/b/', '../g' => 'http://a/b/g',
    '../..' => 'http://a/', '../../g' => 'http://a/g', '../../../g' => 'http://a/g', 'g;x=1/../y' => 'http://a/b/c/y',
    'g?y/./x' => 'http://a/b/c/g?y/./x', 'https://x/y' => 'https://x/y', 'mailto:a@b.c' => 'mailto:a@b.c',
] as $ref => $expected) {
    test("resolve '$ref'", fn () => eq(Url::resolve($base, (string)$ref), $expected));
}
test('resolve keeps port and base path', fn () => eq(Url::resolve('https://ex.com:8443/blog/post/', '../img/a.png'), 'https://ex.com:8443/blog/img/a.png'));
test('resolve protocol-relative', fn () => eq(Url::resolve('https://ex.com/a', '//cdn.ex.com/x.js'), 'https://cdn.ex.com/x.js'));
test('resolve rejects control chars', fn () => eq(Url::resolve('http://a/', "x\x00y"), null));
test('normalize strips fragment, default port, dots; lower-cases host', fn () =>
    eq(Url::normalize('HTTP://Example.COM:80/a/./b/../c?x=1#frag'), 'http://example.com/a/c?x=1'));
test('normalize empty path to /', fn () => eq(Url::normalize('https://example.com'), 'https://example.com/'));
test('normalize rejects non-http and credentials', function () {
    eq(Url::normalize('javascript:alert(1)'), null);
    eq(Url::normalize('mailto:a@b.c'), null);
    eq(Url::normalize('ftp://x/y'), null);
    eq(Url::normalize('http://u:p@x/'), null);
});
