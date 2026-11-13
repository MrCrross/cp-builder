<?php

use App\Modules\Statistics\Support\UaProfile;
use App\Modules\Statistics\Support\UaProfileResolver;

beforeEach(fn () => $this->resolver = new UaProfileResolver);

it('detects common bots by user agent', function (string $ua) {
    expect($this->resolver->resolve($ua)->isBot)->toBeTrue();
})->with([
    'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
    'curl' => ['curl/8.5.0'],
    'python requests' => ['python-requests/2.31.0'],
    'YandexBot' => ['Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)'],
    'headless chrome' => ['Mozilla/5.0 HeadlessChrome/120.0 Safari/537.36'],
]);

it('does not flag regular browsers as bots', function (string $ua) {
    expect($this->resolver->resolve($ua)->isBot)->toBeFalse();
})->with([
    'Chrome desktop' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36'],
    'Safari iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'],
]);

it('treats empty user agent as bot', function () {
    expect($this->resolver->resolve(null)->isBot)->toBeTrue()
        ->and($this->resolver->resolve('')->isBot)->toBeTrue()
        ->and($this->resolver->resolve('   ')->isBot)->toBeTrue();
});

it('classifies device type', function (?string $ua, string $expected) {
    expect($this->resolver->resolve($ua)->deviceType)->toBe($expected);
})->with([
    'windows desktop' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36', UaProfile::DEVICE_DESKTOP],
    'mac desktop' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/17.0', UaProfile::DEVICE_DESKTOP],
    'android phone' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36', UaProfile::DEVICE_MOBILE],
    'iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1', UaProfile::DEVICE_MOBILE],
    'ipad' => ['Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1', UaProfile::DEVICE_TABLET],
    'empty' => ['', UaProfile::DEVICE_UNKNOWN],
    'null' => [null, UaProfile::DEVICE_UNKNOWN],
]);

it('classifies operating system', function (string $ua, string $expected) {
    expect($this->resolver->resolve($ua)->os)->toBe($expected);
})->with([
    'windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0', UaProfile::OS_WINDOWS],
    'macos' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/17.0', UaProfile::OS_MACOS],
    'linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0', UaProfile::OS_LINUX],
    'android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) Chrome/120.0 Mobile Safari/537.36', UaProfile::OS_ANDROID],
    'ios' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148', UaProfile::OS_IOS],
    'unknown' => ['SomeCustomAgent/1.0', UaProfile::OS_UNKNOWN],
]);
